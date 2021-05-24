<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception\ExtensionNotLoadedException;
use Laminas\Cache\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\Exception\RedisRuntimeException;
use Laminas\Cache\Storage\Plugin\PluginInterface;
use Laminas\Cache\Storage\Plugin\Serializer;
use Laminas\Cache\Storage\PluginCapableInterface;
use RedisCluster as RedisClusterFromExtension;
use RedisClusterException;
use ReflectionClass;

use function array_key_exists;
use function assert;
use function extension_loaded;
use function is_int;
use function strpos;

/**
 * @psalm-type RedisClusterInfoType = array<string,mixed>&array{redis_version:string}
 */
final class RedisClusterResourceManager implements RedisClusterResourceManagerInterface
{
    /** @var array<non-empty-string,int>|null */
    private static $clusterOptionsCache;

    /** @var RedisClusterOptions */
    private $options;

    /** @psalm-var array<int,mixed> */
    private $libraryOptions = [];

    public function __construct(RedisClusterOptions $options)
    {
        $this->options = $options;
        if (! extension_loaded('redis')) {
            throw new ExtensionNotLoadedException('Redis extension is not loaded');
        }
    }

    /**
     * @return array<non-empty-string,int>
     */
    private static function getRedisClusterOptions(): array
    {
        if (self::$clusterOptionsCache !== null) {
            return self::$clusterOptionsCache;
        }

        $reflection = new ReflectionClass(RedisClusterFromExtension::class);

        $options = [];
        foreach ($reflection->getConstants() as $constant => $constantValue) {
            if (strpos($constant, 'OPT_') !== 0) {
                continue;
            }
            assert($constant !== '');
            assert(is_int($constantValue));

            $options[$constant] = $constantValue;
        }

        return self::$clusterOptionsCache = $options;
    }

    public function getVersion(): string
    {
        $versionFromOptions = $this->options->redisVersion();
        if ($versionFromOptions) {
            return $versionFromOptions;
        }

        $resource = $this->getResource();
        try {
            $info = $this->info($resource);
        } catch (RedisClusterException $exception) {
            throw RedisRuntimeException::fromClusterException($exception, $resource);
        }

        $version = $info['redis_version'];
        assert($version !== '');
        $this->options->setRedisVersion($version);

        return $version;
    }

    public function getResource(): RedisClusterFromExtension
    {
        try {
            $resource = $this->createRedisResource($this->options);
        } catch (RedisClusterException $exception) {
            throw RedisRuntimeException::fromFailedConnection($exception);
        }

        $libraryOptions = $this->options->libOptions();

        try {
            $resource             = $this->applyLibraryOptions($resource, $libraryOptions);
            $this->libraryOptions = $this->mergeLibraryOptionsFromCluster($libraryOptions, $resource);
        } catch (RedisClusterException $exception) {
            throw RedisRuntimeException::fromClusterException($exception, $resource);
        }

        return $resource;
    }

    private function createRedisResource(RedisClusterOptions $options): RedisClusterFromExtension
    {
        if ($options->hasNodename()) {
            return $this->createRedisResourceFromNodename(
                $options->nodename(),
                $options->timeout(),
                $options->readTimeout(),
                $options->persistent()
            );
        }

        return new RedisClusterFromExtension(
            null,
            $options->seeds(),
            $options->timeout(),
            $options->readTimeout(),
            $options->persistent()
        );
    }

    private function createRedisResourceFromNodename(
        string $nodename,
        float $fallbackTimeout,
        float $fallbackReadTimeout,
        bool $persistent
    ): RedisClusterFromExtension {
        $options     = new RedisClusterOptionsFromIni();
        $seeds       = $options->seeds($nodename);
        $timeout     = $options->timeout($nodename, $fallbackTimeout);
        $readTimeout = $options->readTimeout($nodename, $fallbackReadTimeout);

        return new RedisClusterFromExtension(null, $seeds, $timeout, $readTimeout, $persistent);
    }

    /**
     * @param array<int,mixed> $options
     */
    private function applyLibraryOptions(
        RedisClusterFromExtension $resource,
        array $options
    ): RedisClusterFromExtension {
        /** @psalm-suppress MixedAssignment */
        foreach ($options as $option => $value) {
            /** @psalm-suppress InvalidArgument,MixedArgument */
            $resource->setOption($option, $value);
        }

        return $resource;
    }

    /**
     * @param array<int,mixed> $options
     * @return array<int,mixed>
     */
    private function mergeLibraryOptionsFromCluster(array $options, RedisClusterFromExtension $resource): array
    {
        foreach (self::getRedisClusterOptions() as $constantValue) {
            if (array_key_exists($constantValue, $options)) {
                continue;
            }

            /**
             * @see https://github.com/phpredis/phpredis#getoption
             *
             * @psalm-suppress InvalidArgument
             */
            $options[$constantValue] = $resource->getOption($constantValue);
        }

        return $options;
    }

    /**
     * @return mixed
     */
    public function getLibOption(int $option)
    {
        /**
         * @see https://github.com/phpredis/phpredis#getoption
         *
         * @psalm-suppress InvalidArgument
         */
        return $this->libraryOptions[$option] ?? $this->getResource()->getOption($option);
    }

    public function hasSerializationSupport(PluginCapableInterface $adapter): bool
    {
        $options        = $this->options;
        $libraryOptions = $options->libOptions();
        $serializer     = $libraryOptions[RedisClusterFromExtension::OPT_SERIALIZER] ??
            RedisClusterFromExtension::SERIALIZER_NONE;

        if ($serializer !== RedisClusterFromExtension::SERIALIZER_NONE) {
            return true;
        }

        /** @var iterable<PluginInterface> $plugins */
        $plugins = $adapter->getPluginRegistry();
        foreach ($plugins as $plugin) {
            if (! $plugin instanceof Serializer) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @psalm-return RedisClusterInfoType
     */
    private function info(RedisClusterFromExtension $resource): array
    {
        $nodename = $this->options->nodename();

        if ($nodename !== '') {
            /** @psalm-var RedisClusterInfoType $info */
            $info = $resource->info($nodename);
            return $info;
        }

        $seeds = $this->options->seeds();
        if ($seeds === []) {
            throw new RuntimeException('Neither the node name nor any seed is configured.');
        }

        $seed = $seeds[0];
        /** @psalm-var RedisClusterInfoType $info */
        $info = $resource->info($seed);

        return $info;
    }
}
