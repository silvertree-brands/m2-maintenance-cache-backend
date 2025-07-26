<?php
/**
 * Copyright (c) 2025. Silvertree Brands
 */

declare(strict_types=1);

namespace Silvertree\MaintenanceCacheBackend\Plugin;

use Magento\Framework\App\MaintenanceMode;
use Psr\Log\LoggerInterface;
use Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService;

class MaintenanceModePlugin
{
    /**
     * Constructor method.
     *
     * @param LoggerInterface $logger
     * @param CacheMaintenanceService $cacheMaintenanceService
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CacheMaintenanceService $cacheMaintenanceService
    ) {
    }

    /**
     * Checks the maintenance mode status, prioritizing cache maintenance service if available.
     *
     * @param MaintenanceMode $subject
     * @param callable $proceed
     * @param string $remoteAddr
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundIsOn(MaintenanceMode $subject, callable $proceed, string $remoteAddr = ''): bool
    {
        try {
            if (!$this->cacheMaintenanceService->isMaintenanceEnabled()) {
                return false;
            }

            $addressInfo = $this->cacheMaintenanceService->getMaintenanceAddresses();
            return !in_array($remoteAddr, $addressInfo, true);
        } catch (\Throwable $e) {
            $this->logger->warning('Cache maintenance check failed, falling back to filesystem', [
                'exception' => $e->getMessage()
            ]);
        }

        return $proceed($remoteAddr);
    }

    /**
     * Interceptor method for setting maintenance mode with additional handling.
     *
     * @param MaintenanceMode $subject
     * @param callable $proceed
     * @param bool $isOn
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSet(MaintenanceMode $subject, callable $proceed, bool $isOn): void
    {
        try {
            $this->cacheMaintenanceService->setMaintenanceMode($isOn);
            return;
        } catch (\Throwable $e) {
            $this->logger->warning('Cache maintenance set failed, falling back to filesystem', [
                'exception' => $e->getMessage(),
                'maintenance_mode' => $isOn
            ]);
        }

        $proceed($isOn);
    }

    /**
     * Interceptor method for retrieving address information in maintenance mode.
     *
     * @param MaintenanceMode $subject
     * @param callable $proceed
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetAddressInfo(MaintenanceMode $subject, callable $proceed): array
    {
        try {
            return $this->cacheMaintenanceService->getMaintenanceAddresses();
        } catch (\Throwable $e) {
            $this->logger->warning('Cache address info retrieval failed, falling back to filesystem', [
                'exception' => $e->getMessage()
            ]);
        }

        return $proceed();
    }

    /**
     * Interceptor method for setting maintenance mode IP addresses.
     *
     * @param MaintenanceMode $subject
     * @param callable $proceed
     * @param string $addresses
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSetAddresses(MaintenanceMode $subject, callable $proceed, string $addresses): void
    {
        try {
            $addressArray = empty($addresses) ? [] : explode(',', $addresses);
            $addressArray = array_map('trim', $addressArray);
            $this->cacheMaintenanceService->setMaintenanceAddresses($addressArray);
            return;
        } catch (\Throwable $e) {
            $this->logger->warning('Cache address setting failed, falling back to filesystem', [
                'exception' => $e->getMessage(),
                'addresses' => $addresses
            ]);
        }

        $proceed($addresses);
    }
}
