<?php
/**
 * Copyright (c) 2025. Silvertree Brands
 */

declare(strict_types=1);

namespace Silvertree\MaintenanceCacheBackend\Test\Unit\Service;

use Magento\Framework\App\Cache\Frontend\Factory as CacheFrontendFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService;

/**
 * Unit test for CacheMaintenanceService
 */
class CacheMaintenanceServiceTest extends TestCase
{
    private CacheMaintenanceService $service;

    /** @var CacheFrontendFactory|MockObject */
    private $cacheFactoryMock;

    /** @var DeploymentConfig|MockObject */
    private $deploymentConfigMock;

    /** @var SerializerInterface|MockObject */
    private $serializerMock;

    /** @var LoggerInterface|MockObject */
    private $loggerMock;

    /** @var FrontendInterface|MockObject */
    private $cacheFrontendMock;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->cacheFactoryMock = $this->createMock(CacheFrontendFactory::class);
        $this->deploymentConfigMock = $this->createMock(DeploymentConfig::class);
        $this->serializerMock = $this->createMock(SerializerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->cacheFrontendMock = $this->createMock(FrontendInterface::class);

        // Default setup - cache is configured and available
        $this->deploymentConfigMock
            ->method('get')
            ->with('cache/maintenance')
            ->willReturn([
                'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
                'backend_options' => ['server' => 'localhost', 'database' => '14']
            ]);

        $this->cacheFactoryMock
            ->method('create')
            ->willReturn($this->cacheFrontendMock);

        // Default serializer behavior
        $this->serializerMock
            ->method('serialize')
            ->willReturnCallback(fn($data) => json_encode($data));

        $this->serializerMock
            ->method('unserialize')
            ->willReturnCallback(function ($data) {
                if ($data === false) {
                    throw new \InvalidArgumentException('Cannot unserialize false value');
                }
                $decoded = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Invalid JSON data: ' . json_last_error_msg());
                }
                return $decoded;
            });

        $this->service = $objectManager->getObject(CacheMaintenanceService::class, [
            'cacheFrontendFactory' => $this->cacheFactoryMock,
            'deploymentConfig' => $this->deploymentConfigMock,
            'serializer' => $this->serializerMock,
            'logger' => $this->loggerMock
        ]);
    }

    public function testIsMaintenanceEnabledReturnsTrueWhenCached(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_STATUS')
            ->willReturn('true'); // JSON serialized boolean

        $result = $this->service->isMaintenanceEnabled();

        $this->assertTrue($result);
    }

    public function testIsMaintenanceEnabledThrowsExceptionWhenNotCached(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_STATUS')
            ->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot unserialize false value');

        $this->service->isMaintenanceEnabled();
    }

    public function testIsMaintenanceEnabledThrowsExceptionOnCacheError(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_STATUS')
            ->willReturn('invalid_json'); // This will cause unserialize to fail

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON data:');

        $this->service->isMaintenanceEnabled();
    }

    public function testSetMaintenanceModeToTrue(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('save')
            ->with('true', 'MAINTENANCE_MODE_STATUS')
            ->willReturn(true);

        $this->service->setMaintenanceMode(true);
    }

    public function testSetMaintenanceModeToFalse(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('save')
            ->with('false', 'MAINTENANCE_MODE_STATUS')
            ->willReturn(true);

        $this->service->setMaintenanceMode(false);
    }

    public function testSetMaintenanceModeThrowsExceptionOnCacheFailure(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('save')
            ->with('true', 'MAINTENANCE_MODE_STATUS')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to save data to cache for key: MAINTENANCE_MODE_STATUS');

        $this->service->setMaintenanceMode(true);
    }

    public function testSetMaintenanceAddresses(): void
    {
        $addresses = ['192.168.1.1', '10.0.0.1'];
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('save')
            ->with('["192.168.1.1","10.0.0.1"]', 'MAINTENANCE_MODE_ADDRESSES')
            ->willReturn(true);

        $this->service->setMaintenanceAddresses($addresses);
    }

    public function testSetMaintenanceAddressesWithEmptyArray(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('remove')
            ->with('MAINTENANCE_MODE_ADDRESSES');

        $this->service->setMaintenanceAddresses([]);
    }

    public function testGetMaintenanceAddressesReturnsArrayWhenCached(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_ADDRESSES')
            ->willReturn('["192.168.1.1","10.0.0.1"]');

        $result = $this->service->getMaintenanceAddresses();

        $this->assertEquals(['192.168.1.1', '10.0.0.1'], $result);
    }

    public function testGetMaintenanceAddressesThrowsExceptionWhenNotCached(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_ADDRESSES')
            ->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot unserialize false value');

        $this->service->getMaintenanceAddresses();
    }

    /**
     * Test with boolean false value - JSON serialized
     */
    public function testSerializationEdgeCases(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('save')
            ->with('false', 'MAINTENANCE_MODE_STATUS')
            ->willReturn(true);

        $this->service->setMaintenanceMode(false);
    }

    public function testMissingCacheConfigurationThrowsException(): void
    {
        // Create service with missing cache configuration
        $objectManager = new ObjectManager($this);

        $deploymentConfigMock = $this->createMock(DeploymentConfig::class);
        $deploymentConfigMock
            ->method('get')
            ->with('cache/maintenance')
            ->willReturn(null); // No configuration

        $cacheFactoryMock = $this->createMock(CacheFrontendFactory::class);
        // Factory should not be called when config is missing
        $cacheFactoryMock->expects($this->never())->method('create');

        $serializerMock = $this->createMock(SerializerInterface::class);
        $loggerMock = $this->createMock(LoggerInterface::class);

        $service = $objectManager->getObject(CacheMaintenanceService::class, [
            'cacheFrontendFactory' => $cacheFactoryMock,
            'deploymentConfig' => $deploymentConfigMock,
            'serializer' => $serializerMock,
            'logger' => $loggerMock
        ]);

        // Service should throw exception when cache is not configured
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache not available for key: MAINTENANCE_MODE_STATUS');

        $service->isMaintenanceEnabled();
    }

    public function testCacheFactoryExceptionThrowsException(): void
    {
        // Create service where cache factory throws exception
        $objectManager = new ObjectManager($this);

        $deploymentConfigMock = $this->createMock(DeploymentConfig::class);
        $deploymentConfigMock
            ->method('get')
            ->with('cache/maintenance')
            ->willReturn([
                'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
                'backend_options' => ['server' => 'localhost']
            ]);

        $cacheFactoryMock = $this->createMock(CacheFrontendFactory::class);
        $cacheFactoryMock
            ->expects($this->once())
            ->method('create')
            ->willThrowException(new \Exception('Cache factory error'));

        $serializerMock = $this->createMock(SerializerInterface::class);

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to initialize maintenance cache, falling back to filesystem',
                ['exception' => 'Cache factory error']
            );

        $service = $objectManager->getObject(CacheMaintenanceService::class, [
            'cacheFrontendFactory' => $cacheFactoryMock,
            'deploymentConfig' => $deploymentConfigMock,
            'serializer' => $serializerMock,
            'logger' => $loggerMock
        ]);

        // Service should throw exception when cache factory fails
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache not available for key: MAINTENANCE_MODE_STATUS');

        $service->isMaintenanceEnabled();
    }

    public function testInvalidBackendConfigurationThrowsException(): void
    {
        // Create service with invalid backend configuration
        $objectManager = new ObjectManager($this);

        $deploymentConfigMock = $this->createMock(DeploymentConfig::class);
        $deploymentConfigMock
            ->method('get')
            ->with('cache/maintenance')
            ->willReturn([
                'invalid_key' => 'some_value' // Missing 'backend' key
            ]);

        $cacheFactoryMock = $this->createMock(CacheFrontendFactory::class);
        // Factory should not be called for invalid config
        $cacheFactoryMock->expects($this->never())->method('create');

        $serializerMock = $this->createMock(SerializerInterface::class);
        $loggerMock = $this->createMock(LoggerInterface::class);

        $service = $objectManager->getObject(CacheMaintenanceService::class, [
            'cacheFrontendFactory' => $cacheFactoryMock,
            'deploymentConfig' => $deploymentConfigMock,
            'serializer' => $serializerMock,
            'logger' => $loggerMock
        ]);

        // Service should throw exception with invalid configuration
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache not available for key: MAINTENANCE_MODE_STATUS');

        $service->isMaintenanceEnabled();
    }
}
