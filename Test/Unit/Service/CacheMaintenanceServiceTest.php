<?php
/**
 * Copyright (c) 2025. Silvertree Brands
 */

declare(strict_types=1);

namespace Silvertree\MaintenanceCacheBackend\Test\Unit\Service;

/**
 * Unit test for CacheMaintenanceService
 */
#[\PHPUnit\Framework\Attributes\Group('silvertree-vendor')]
#[\PHPUnit\Framework\Attributes\CoversClass(\Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService::class)]
class CacheMaintenanceServiceTest extends \PHPUnit\Framework\TestCase
{
    private \Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService $service;

    /** @var \Magento\Framework\App\Cache\Frontend\Factory&\PHPUnit\Framework\MockObject\MockObject */
    private $cacheFactoryMock;

    /** @var \Magento\Framework\App\DeploymentConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $deploymentConfigMock;

    /** @var \Magento\Framework\Serialize\SerializerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $serializerMock;

    /** @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $loggerMock;

    /** @var \Magento\Framework\Cache\FrontendInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $cacheFrontendMock;

    protected function setUp(): void
    {
        $this->cacheFactoryMock = $this->createMock(\Magento\Framework\App\Cache\Frontend\Factory::class);
        $this->deploymentConfigMock = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $this->serializerMock = $this->createMock(\Magento\Framework\Serialize\SerializerInterface::class);
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->cacheFrontendMock = $this->createMock(\Magento\Framework\Cache\FrontendInterface::class);

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

        $this->serializerMock
            ->method('serialize')
            ->willReturnCallback(fn ($data) => json_encode($data));

        $this->serializerMock
            ->method('unserialize')
            ->willReturnCallback(function ($data) {
                if ($data === false) {
                    throw new \InvalidArgumentException('Cannot unserialize false value');
                }
                $decoded = json_decode((string) $data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Invalid JSON data: ' . json_last_error_msg());
                }
                return $decoded;
            });

        $this->service = new \Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService(
            $this->cacheFactoryMock,
            $this->deploymentConfigMock,
            $this->serializerMock,
            $this->loggerMock
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testIsMaintenanceEnabledReturnsTrueWhenCached(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_STATUS')
            ->willReturn('true');

        $result = $this->service->isMaintenanceEnabled();

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testIsMaintenanceEnabledReturnsFalseWhenNotCached(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_STATUS')
            ->willReturn(false);

        $this->assertFalse($this->service->isMaintenanceEnabled());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testIsMaintenanceEnabledThrowsExceptionOnCacheError(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_STATUS')
            ->willReturn('invalid_json');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON data:');

        $this->service->isMaintenanceEnabled();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSetMaintenanceModeToTrue(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('save')
            ->with('true', 'MAINTENANCE_MODE_STATUS')
            ->willReturn(true);

        $this->service->setMaintenanceMode(true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSetMaintenanceModeToFalse(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('save')
            ->with('false', 'MAINTENANCE_MODE_STATUS')
            ->willReturn(true);

        $this->service->setMaintenanceMode(false);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSetMaintenanceAddressesWithEmptyArray(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('remove')
            ->with('MAINTENANCE_MODE_ADDRESSES');

        $this->service->setMaintenanceAddresses([]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function testGetMaintenanceAddressesReturnsEmptyWhenNotCached(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('load')
            ->with('MAINTENANCE_MODE_ADDRESSES')
            ->willReturn(false);

        $this->assertSame([], $this->service->getMaintenanceAddresses());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSerializationEdgeCases(): void
    {
        $this->cacheFrontendMock
            ->expects($this->once())
            ->method('save')
            ->with('false', 'MAINTENANCE_MODE_STATUS')
            ->willReturn(true);

        $this->service->setMaintenanceMode(false);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMissingCacheConfigurationThrowsException(): void
    {
        $deploymentConfigMock = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $deploymentConfigMock
            ->method('get')
            ->with('cache/maintenance')
            ->willReturn(null);

        $cacheFactoryMock = $this->createMock(\Magento\Framework\App\Cache\Frontend\Factory::class);
        $cacheFactoryMock->expects($this->never())->method('create');

        $serializerMock = $this->createMock(\Magento\Framework\Serialize\SerializerInterface::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $service = new \Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService(
            $cacheFactoryMock,
            $deploymentConfigMock,
            $serializerMock,
            $loggerMock
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache not available for key: MAINTENANCE_MODE_STATUS');

        $service->isMaintenanceEnabled();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testCacheFactoryExceptionThrowsException(): void
    {
        $deploymentConfigMock = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $deploymentConfigMock
            ->method('get')
            ->with('cache/maintenance')
            ->willReturn([
                'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
                'backend_options' => ['server' => 'localhost']
            ]);

        $cacheFactoryMock = $this->createMock(\Magento\Framework\App\Cache\Frontend\Factory::class);
        $cacheFactoryMock
            ->expects($this->once())
            ->method('create')
            ->willThrowException(new \Exception('Cache factory error'));

        $serializerMock = $this->createMock(\Magento\Framework\Serialize\SerializerInterface::class);

        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to initialize maintenance cache, falling back to filesystem',
                ['exception' => 'Cache factory error']
            );

        $service = new \Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService(
            $cacheFactoryMock,
            $deploymentConfigMock,
            $serializerMock,
            $loggerMock
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache not available for key: MAINTENANCE_MODE_STATUS');

        $service->isMaintenanceEnabled();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testInvalidBackendConfigurationThrowsException(): void
    {
        $deploymentConfigMock = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $deploymentConfigMock
            ->method('get')
            ->with('cache/maintenance')
            ->willReturn([
                'invalid_key' => 'some_value'
            ]);

        $cacheFactoryMock = $this->createMock(\Magento\Framework\App\Cache\Frontend\Factory::class);
        $cacheFactoryMock->expects($this->never())->method('create');

        $serializerMock = $this->createMock(\Magento\Framework\Serialize\SerializerInterface::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $service = new \Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService(
            $cacheFactoryMock,
            $deploymentConfigMock,
            $serializerMock,
            $loggerMock
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache not available for key: MAINTENANCE_MODE_STATUS');

        $service->isMaintenanceEnabled();
    }
}
