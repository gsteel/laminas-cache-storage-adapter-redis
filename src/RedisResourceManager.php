<?php

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Laminas\Stdlib\ArrayUtils;
use Redis as RedisResource;
use ReflectionClass;
use Traversable;

use function array_merge;
use function constant;
use function defined;
use function is_array;
use function is_string;
use function method_exists;
use function parse_url;
use function str_replace;
use function strpos;
use function strtoupper;
use function trim;

/**
 * This is a resource manager for redis
 */
final class RedisResourceManager
{
    /**
     * Registered resources
     *
     * @var array
     */
    protected $resources = [];

    /**
     * Check if a resource exists
     *
     * @param string $id
     * @return bool
     */
    public function hasResource($id)
    {
        return isset($this->resources[$id]);
    }

    /**
     * Get redis server version
     *
     * @param string $resourceId
     * @return string
     * @throws Exception\RuntimeException
     */
    public function getVersion($resourceId)
    {
        // check resource id and initialize the resource
        $this->getResource($resourceId);

        return $this->resources[$resourceId]['version'];
    }

    /**
     * Get redis major server version
     *
     * @param string $resourceId
     * @return int
     * @throws Exception\RuntimeException
     */
    public function getMajorVersion($resourceId)
    {
        // check resource id and initialize the resource
        $this->getResource($resourceId);

        return (int) $this->resources[$resourceId]['version'];
    }

    /**
     * Get redis server version
     *
     * @deprecated 2.2.2 Use getMajorVersion instead
     *
     * @param string $id
     * @return int
     * @throws Exception\RuntimeException
     */
    public function getMayorVersion($id)
    {
        return $this->getMajorVersion($id);
    }

    /**
     * Get redis resource database
     *
     * @param string $id
     * @return string
     */
    public function getDatabase($id)
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $resource = &$this->resources[$id];
        return $resource['database'];
    }

    /**
     * Get redis resource password
     *
     * @param string $id
     * @return string
     */
    public function getPassword($id)
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $resource = &$this->resources[$id];
        return $resource['password'];
    }

    /**
     * Gets a redis resource
     *
     * @param string $id
     * @return RedisResource
     * @throws Exception\RuntimeException
     */
    public function getResource($id)
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $resource = &$this->resources[$id];
        if ($resource['resource'] instanceof RedisResource) {
            //in case new server was set then connect
            if (! $resource['initialized']) {
                $this->connect($resource);
            }

            if (! $resource['version']) {
                $info                = $resource['resource']->info();
                $resource['version'] = $info['redis_version'];
            }

            return $resource['resource'];
        }

        $redis = new RedisResource();

        $resource['resource'] = $redis;
        $this->connect($resource);

        $this->normalizeLibOptions($resource['lib_options']);

        foreach ($resource['lib_options'] as $k => $v) {
            $redis->setOption($k, $v);
        }

        $info                             = $redis->info();
        $resource['version']              = $info['redis_version'];
        $this->resources[$id]['resource'] = $redis;
        return $redis;
    }

    /**
     * Get server
     *
     * @param string $id
     * @throws Exception\RuntimeException
     * @return array array('host' => <host>[, 'port' => <port>[, 'timeout' => <timeout>]])
     */
    public function getServer($id)
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $resource = &$this->resources[$id];
        return $resource['server'];
    }

    /**
     * Normalize one server into the following format:
     * array('host' => <host>[, 'port' => <port>[, 'timeout' => <timeout>]])
     *
     * @param string|array $server
     * @throws Exception\InvalidArgumentException
     */
    protected function normalizeServer(&$server)
    {
        $host    = null;
        $port    = null;
        $timeout = 0;

        // convert a single server into an array
        if ($server instanceof Traversable) {
            $server = ArrayUtils::iteratorToArray($server);
        }

        if (is_array($server)) {
            // array(<host>[, <port>[, <timeout>]])
            if (isset($server[0])) {
                $host    = (string) $server[0];
                $port    = isset($server[1]) ? (int) $server[1] : $port;
                $timeout = isset($server[2]) ? (int) $server[2] : $timeout;
            }

            // array('host' => <host>[, 'port' => <port>, ['timeout' => <timeout>]])
            if (! isset($server[0]) && isset($server['host'])) {
                $host    = (string) $server['host'];
                $port    = isset($server['port']) ? (int) $server['port'] : $port;
                $timeout = isset($server['timeout']) ? (int) $server['timeout'] : $timeout;
            }
        } else {
            // parse server from URI host{:?port}
            $server = trim($server);
            if (strpos($server, '/') !== 0) {
                //non unix domain socket connection
                $server = parse_url($server);
            } else {
                $server = ['host' => $server];
            }
            if (! $server) {
                throw new Exception\InvalidArgumentException("Invalid server given");
            }

            $host    = $server['host'];
            $port    = isset($server['port']) ? (int) $server['port'] : $port;
            $timeout = isset($server['timeout']) ? (int) $server['timeout'] : $timeout;
        }

        if (! $host) {
            throw new Exception\InvalidArgumentException('Missing required server host');
        }

        $server = [
            'host'    => $host,
            'port'    => $port,
            'timeout' => $timeout,
        ];
    }

    /**
     * Extract password to be used on connection
     *
     * @param mixed $resource
     * @param mixed $serverUri
     * @return string|null
     */
    protected function extractPassword($resource, $serverUri)
    {
        if (! empty($resource['password'])) {
            return $resource['password'];
        }

        if (! is_string($serverUri)) {
            return;
        }

        // parse server from URI host{:?port}
        $server = trim($serverUri);

        if (strpos($server, '/') === 0) {
            return;
        }

        //non unix domain socket connection
        $server = parse_url($server);

        return $server['pass'] ?? null;
    }

    /**
     * Connects to redis server
     *
     * @param array $resource
     * @return void
     * @throws Exception\RuntimeException
     */
    protected function connect(array &$resource)
    {
        $server = $resource['server'];
        $redis  = $resource['resource'];
        if ($resource['persistent_id'] !== '') {
            //connect or reuse persistent connection
            $success = $redis->pconnect(
                $server['host'],
                $server['port'],
                $server['timeout'],
                $resource['persistent_id']
            );
        } elseif ($server['port']) {
            $success = $redis->connect($server['host'], $server['port'], $server['timeout']);
        } elseif ($server['timeout']) {
            //connect through unix domain socket
            $success = $redis->connect($server['host'], $server['timeout']);
        } else {
            $success = $redis->connect($server['host']);
        }

        if (! $success) {
            throw new Exception\RuntimeException('Could not establish connection with Redis instance');
        }

        $resource['initialized'] = true;
        if ($resource['password']) {
            $redis->auth($resource['password']);
        }
        $redis->select($resource['database']);
    }

    /**
     * Set a resource
     *
     * @param string $id
     * @param array|Traversable|RedisResource $resource
     * @return RedisResourceManager Fluent interface
     */
    public function setResource($id, $resource)
    {
        $id = (string) $id;
        //TODO: how to get back redis connection info from resource?
        $defaults = [
            'persistent_id' => '',
            'lib_options'   => [],
            'server'        => [],
            'password'      => '',
            'database'      => 0,
            'resource'      => null,
            'initialized'   => false,
            'version'       => 0,
        ];
        if (! $resource instanceof RedisResource) {
            if ($resource instanceof Traversable) {
                $resource = ArrayUtils::iteratorToArray($resource);
            } elseif (! is_array($resource)) {
                throw new Exception\InvalidArgumentException(
                    'Resource must be an instance of an array or Traversable'
                );
            }

            $resource = array_merge($defaults, $resource);
            // normalize and validate params
            $this->normalizePersistentId($resource['persistent_id']);

            // #6495 note: order is important here, as `normalizeServer` applies destructive
            // transformations on $resource['server']
            $resource['password'] = $this->extractPassword($resource, $resource['server']);

            $this->normalizeServer($resource['server']);
        } else {
            //there are two ways of determining if redis is already initialized
            //with connect function:
            //1) pinging server
            //2) checking undocumented property socket which is available only
            //after successful connect
            $resource = array_merge(
                $defaults,
                [
                    'resource'    => $resource,
                    'initialized' => isset($resource->socket),
                ]
            );
        }
        $this->resources[$id] = $resource;
        return $this;
    }

    /**
     * Remove a resource
     *
     * @param string $id
     * @return RedisResourceManager Fluent interface
     */
    public function removeResource($id)
    {
        unset($this->resources[$id]);
        return $this;
    }

    /**
     * Set the persistent id
     *
     * @param string $id
     * @param string $persistentId
     * @return RedisResourceManager Fluent interface
     * @throws Exception\RuntimeException
     */
    public function setPersistentId($id, $persistentId)
    {
        if (! $this->hasResource($id)) {
            return $this->setResource($id, [
                'persistent_id' => $persistentId,
            ]);
        }

        $resource = &$this->resources[$id];
        if ($resource['resource'] instanceof RedisResource && $resource['initialized']) {
            throw new Exception\RuntimeException(
                "Can't change persistent id of resource {$id} after initialization"
            );
        }

        $this->normalizePersistentId($persistentId);
        $resource['persistent_id'] = $persistentId;

        return $this;
    }

    /**
     * Get the persistent id
     *
     * @param string $id
     * @return string
     * @throws Exception\RuntimeException
     */
    public function getPersistentId($id)
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $resource = &$this->resources[$id];

        return $resource['persistent_id'];
    }

    /**
     * Normalize the persistent id
     *
     * @param string $persistentId
     */
    protected function normalizePersistentId(&$persistentId)
    {
        $persistentId = (string) $persistentId;
    }

    /**
     * Set Redis options
     *
     * @param string $id
     * @param array  $libOptions
     * @return RedisResourceManager Fluent interface
     */
    public function setLibOptions($id, array $libOptions)
    {
        if (! $this->hasResource($id)) {
            return $this->setResource($id, [
                'lib_options' => $libOptions,
            ]);
        }

        $resource = &$this->resources[$id];

        $resource['lib_options'] = $libOptions;

        if (! $resource['resource'] instanceof RedisResource) {
            return $this;
        }

        $this->normalizeLibOptions($libOptions);
        $redis = &$resource['resource'];

        if (method_exists($redis, 'setOptions')) {
            $redis->setOptions($libOptions);
        } else {
            foreach ($libOptions as $key => $value) {
                $redis->setOption($key, $value);
            }
        }

        return $this;
    }

    /**
     * Get Redis options
     *
     * @param string $id
     * @return array
     * @throws Exception\RuntimeException
     */
    public function getLibOptions($id)
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $resource = &$this->resources[$id];

        if ($resource['resource'] instanceof RedisResource) {
            $libOptions = [];
            $reflection = new ReflectionClass('Redis');
            $constants  = $reflection->getConstants();
            foreach ($constants as $constName => $constValue) {
                if (strpos($constName, 'OPT_') === 0) {
                    $libOptions[$constValue] = $resource['resource']->getOption($constValue);
                }
            }
            return $libOptions;
        }
        return $resource['lib_options'];
    }

    /**
     * Set one Redis option
     *
     * @param string     $id
     * @param string|int $key
     * @param mixed      $value
     * @return RedisResourceManager Fluent interface
     */
    public function setLibOption($id, $key, $value)
    {
        return $this->setLibOptions($id, [$key => $value]);
    }

    /**
     * Get one Redis option
     *
     * @param string     $id
     * @param string|int $key
     * @return mixed
     * @throws Exception\RuntimeException
     */
    public function getLibOption($id, $key)
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $this->normalizeLibOptionKey($key);
        $resource = &$this->resources[$id];

        if ($resource['resource'] instanceof RedisResource) {
            return $resource['resource']->getOption($key);
        }

        return $resource['lib_options'][$key] ?? null;
    }

    /**
     * Normalize Redis options
     *
     * @param array|Traversable $libOptions
     * @throws Exception\InvalidArgumentException
     */
    protected function normalizeLibOptions(&$libOptions)
    {
        if (! is_array($libOptions) && ! $libOptions instanceof Traversable) {
            throw new Exception\InvalidArgumentException(
                "Lib-Options must be an array or an instance of Traversable"
            );
        }

        $result = [];
        foreach ($libOptions as $key => $value) {
            $this->normalizeLibOptionKey($key);
            $result[$key] = $value;
        }

        $libOptions = $result;
    }

    /**
     * Convert option name into it's constant value
     *
     * @param string|int $key
     * @throws Exception\InvalidArgumentException
     */
    protected function normalizeLibOptionKey(&$key)
    {
        // convert option name into it's constant value
        if (is_string($key)) {
            $const = 'Redis::OPT_' . str_replace([' ', '-'], '_', strtoupper($key));
            if (! defined($const)) {
                throw new Exception\InvalidArgumentException("Unknown redis option '{$key}' ({$const})");
            }
            $key = constant($const);
        } else {
            $key = (int) $key;
        }
    }

    /**
     * Set server
     *
     * Server can be described as follows:
     * - URI:   /path/to/sock.sock
     * - Assoc: array('host' => <host>[, 'port' => <port>[, 'timeout' => <timeout>]])
     * - List:  array(<host>[, <port>, [, <timeout>]])
     *
     * @param string       $id
     * @param string|array $server
     * @return RedisResourceManager
     */
    public function setServer($id, $server)
    {
        if (! $this->hasResource($id)) {
            return $this->setResource($id, [
                'server' => $server,
            ]);
        }

        $this->normalizeServer($server);

        $resource             = &$this->resources[$id];
        $resource['password'] = $this->extractPassword($resource, $server);

        if ($resource['resource'] instanceof RedisResource) {
            $resourceParams = ['server' => $server];

            if (! empty($resource['password'])) {
                $resourceParams['password'] = $resource['password'];
            }

            $this->setResource($id, $resourceParams);
        } else {
            $resource['server'] = $server;
        }

        return $this;
    }

    /**
     * Set redis password
     *
     * @param string $id
     * @param string $password
     * @return RedisResource
     */
    public function setPassword($id, $password)
    {
        if (! $this->hasResource($id)) {
            return $this->setResource($id, [
                'password' => $password,
            ]);
        }

        $resource                = &$this->resources[$id];
        $resource['password']    = $password;
        $resource['initialized'] = false;
        return $this;
    }

    /**
     * Set redis database number
     *
     * @param string $id
     * @param int $database
     * @return RedisResourceManager
     */
    public function setDatabase($id, $database)
    {
        $database = (int) $database;

        if (! $this->hasResource($id)) {
            return $this->setResource($id, [
                'database' => $database,
            ]);
        }

        $resource = &$this->resources[$id];
        if ($resource['resource'] instanceof RedisResource && $resource['initialized']) {
            $resource['resource']->select($database);
        }

        $resource['database'] = $database;

        return $this;
    }
}
