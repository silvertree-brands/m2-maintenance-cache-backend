<?php
/**
 * Copyright (c) 2025. Silvertree Brands
 */

declare(strict_types=1);

namespace Silvertree\MaintenanceCacheBackend\Service;

use Magento\Framework\App\Cache\Frontend\Factory as CacheFrontendFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cache-based maintenance mode service
 *
 * Handles maintenance mode operations using cache backend instead of filesystem
 */
class CacheMaintenanceService
{

    public const CACHE_KEY_PREFIX = 'MAINTENANCE_MODE_';
    private const CACHE_KEY_STATUS = 'STATUS';
    private const CACHE_KEY_ADDRESSES = 'ADDRESSES';

    /**
     * @var FrontendInterface|null
     */
    private ?FrontendInterface $cache = null;

    /**
     * Constructor
     *
     * @param CacheFrontendFactory $cacheFrontendFactory
     * @param DeploymentConfig $deploymentConfig
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheFrontendFactory $cacheFrontendFactory,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
        $this->initializeCache();
    }

    /**
     * Initialize cache frontend from configuration
     *
     * @return void
     */
    private function initializeCache(): void
    {
        try {
            $cacheConfig = $this->deploymentConfig->get('cache/maintenance');

            if (!$cacheConfig) {
                $this->cache = null;
                return;
            }

            if (isset($cacheConfig['backend'])) {
                $this->cache = $this->cacheFrontendFactory->create($cacheConfig);
            } else {
                $this->cache = null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to initialize maintenance cache, falling back to filesystem', [
                'exception' => $e->getMessage()
            ]);
            $this->cache = null;
        }
    }

    /**
     * Check if maintenance mode is enabled via cache
     *
     * @return bool
     */
    public function isMaintenanceEnabled(): bool
    {
        $status = $this->getCacheData($this->getCacheKey(self::CACHE_KEY_STATUS));
        return $status !== false && (bool)$status;
    }

    /**
     * Set maintenance mode status in cache
     *
     * @param bool $isOn
     * @return void
     * @throws \RuntimeException
     */
    public function setMaintenanceMode(bool $isOn): void
    {
        $this->setCacheData($this->getCacheKey(self::CACHE_KEY_STATUS), $isOn);
    }

    /**
     * Get maintenance mode IP addresses from cache
     *
     * @return array
     */
    public function getMaintenanceAddresses(): array
    {
        $addresses = $this->getCacheData($this->getCacheKey(self::CACHE_KEY_ADDRESSES));
        if ($addresses === false) {
            return [];
        }

        return is_array($addresses) ? $addresses : [];
    }

    /**
     * Set maintenance mode IP addresses from cache
     *
     * @param array $addresses
     * @return void
     * @throws \RuntimeException
     */
    public function setMaintenanceAddresses(array $addresses): void
    {
        if (!empty($addresses)) {
            $this->setCacheData($this->getCacheKey(self::CACHE_KEY_ADDRESSES), $addresses);
        } else {
            $this->removeCacheData($this->getCacheKey(self::CACHE_KEY_ADDRESSES));
        }
    }

    /**
     * Generate full cache key with configured prefix
     *
     * @param string $suffix
     * @return string
     */
    private function getCacheKey(string $suffix): string
    {
        return self::CACHE_KEY_PREFIX . $suffix;
    }

    /**
     * Save data to cache
     *
     * @param string $key
     * @param mixed $data
     * @return void
     */
    private function setCacheData(string $key, mixed $data): void
    {
        if (!$this->cache) {
            throw new \RuntimeException("Cache not available for key: $key");
        }

        $serializedData = $this->serializer->serialize($data);

        if (!$this->cache->save($serializedData, $key)) {
            throw new \RuntimeException("Failed to save data to cache for key: $key");
        }
    }

    /**
     * Retrieve data from cache
     *
     * @param string $key
     * @return mixed
     */
    private function getCacheData(string $key): mixed
    {
        if (!$this->cache) {
            throw new \RuntimeException("Cache not available for key: $key");
        }

        $serializedData = $this->cache->load($key);
        return $this->serializer->unserialize($serializedData);
    }

    /**
     * Remove data from cache
     *
     * @param string $key
     * @return void
     */
    private function removeCacheData(string $key): void
    {
        if (!$this->cache) {
            throw new \RuntimeException("Cache not available for key: $key");
        }

        $this->cache->remove($key);
    }
}
