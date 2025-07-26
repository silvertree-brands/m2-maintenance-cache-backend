<?php
/**
 * Copyright (c) 2025. Silvertree Brands
 */

declare(strict_types=1);

namespace Silvertree\MaintenanceCacheBackend\Test\Unit\Plugin;

use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Silvertree\MaintenanceCacheBackend\Plugin\MaintenanceModePlugin;
use Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService;

/**
 * Unit test for MaintenanceModePlugin
 */
class MaintenanceModePluginTest extends TestCase
{
    /**
     * @var MaintenanceModePlugin|object
     */
    private MaintenanceModePlugin $plugin;

    /** @var LoggerInterface|MockObject */
    private $loggerMock;

    /** @var CacheMaintenanceService|MockObject */
    private $cacheMaintenanceServiceMock;

    /** @var MaintenanceMode|MockObject */
    private $maintenanceModeMock;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->cacheMaintenanceServiceMock = $this->createMock(CacheMaintenanceService::class);
        $this->maintenanceModeMock = $this->createMock(MaintenanceMode::class);

        $this->plugin = $objectManager->getObject(MaintenanceModePlugin::class, [
            'logger' => $this->loggerMock,
            'cacheMaintenanceService' => $this->cacheMaintenanceServiceMock
        ]);
    }

    public function testAroundIsOnSuccessful(): void
    {
        $remoteAddr = '192.168.1.1';
        $proceed = function ($addr) {
            return true; // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('isMaintenanceEnabled')
            ->willReturn(true);

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willReturn(['10.0.0.1', '192.168.1.2']); // IP not in whitelist

        $this->loggerMock->expects($this->never())->method('warning');

        $result = $this->plugin->aroundIsOn($this->maintenanceModeMock, $proceed, $remoteAddr);

        $this->assertTrue($result); // Maintenance active for non-whitelisted IP
    }

    public function testAroundIsOnWithWhitelistedIp(): void
    {
        $remoteAddr = '192.168.1.1';
        $proceed = function ($addr) {
            return true; // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('isMaintenanceEnabled')
            ->willReturn(true);

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willReturn(['192.168.1.1', '10.0.0.1']); // IP is in whitelist

        $result = $this->plugin->aroundIsOn($this->maintenanceModeMock, $proceed, $remoteAddr);

        $this->assertFalse($result); // Maintenance NOT active for whitelisted IP
    }

    public function testAroundIsOnWhenMaintenanceDisabled(): void
    {
        $remoteAddr = '192.168.1.1';
        $proceed = function ($addr) {
            return true; // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('isMaintenanceEnabled')
            ->willReturn(false);

        $this->cacheMaintenanceServiceMock
            ->expects($this->never())
            ->method('getMaintenanceAddresses');

        $result = $this->plugin->aroundIsOn($this->maintenanceModeMock, $proceed, $remoteAddr);

        $this->assertFalse($result); // Maintenance NOT active when disabled
    }

    public function testAroundIsOnWithCacheFailure(): void
    {
        $remoteAddr = '192.168.1.1';
        $exception = new \Exception('Cache read failure');
        $proceedCalled = false;
        $proceed = function ($addr) use ($remoteAddr, &$proceedCalled) {
            $proceedCalled = true;
            $this->assertEquals($remoteAddr, $addr);
            return true;
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('isMaintenanceEnabled')
            ->willThrowException($exception);

        $this->loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Cache maintenance check failed, falling back to filesystem',
                ['exception' => $exception->getMessage()]
            );

        $result = $this->plugin->aroundIsOn($this->maintenanceModeMock, $proceed, $remoteAddr);

        $this->assertTrue($result);
        $this->assertTrue($proceedCalled, 'Proceed should be called for fallback');
    }

    public function testAroundSetSuccessful(): void
    {
        $isOn = true;
        $addresses = ['192.168.1.1', '10.0.0.1'];
        $proceed = function ($isOn) {
            // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceMode')
            ->with($isOn);

        $this->loggerMock->expects($this->never())->method('warning');

        $this->plugin->aroundSet($this->maintenanceModeMock, $proceed, $isOn, $addresses);
    }

    public function testAroundSetWithCacheFailure(): void
    {
        $isOn = true;
        $addresses = ['192.168.1.1'];
        $exception = new \Exception('Cache write failure');
        $proceedCalled = false;
        $proceed = function ($receivedIsOn) use ($isOn, &$proceedCalled) {
            $proceedCalled = true;
            $this->assertEquals($isOn, $receivedIsOn);
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceMode')
            ->with($isOn)
            ->willThrowException($exception);

        $this->loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Cache maintenance set failed, falling back to filesystem',
                [
                    'exception' => $exception->getMessage(),
                    'maintenance_mode' => $isOn
                ]
            );

        $this->plugin->aroundSet($this->maintenanceModeMock, $proceed, $isOn, $addresses);
        $this->assertTrue($proceedCalled, 'Proceed should be called for fallback');
    }

    public function testAroundSetWithEmptyAddresses(): void
    {
        $isOn = false;
        $addresses = [];
        $proceed = function ($isOn) {
            // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceMode')
            ->with($isOn);

        $this->plugin->aroundSet($this->maintenanceModeMock, $proceed, $isOn, $addresses);
    }

    public function testAroundGetAddressInfoSuccessful(): void
    {
        $expectedAddresses = ['192.168.1.1', '10.0.0.1'];
        $proceed = function () {
            return ['filesystem_addresses']; // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willReturn($expectedAddresses);

        $this->loggerMock->expects($this->never())->method('warning');

        $result = $this->plugin->aroundGetAddressInfo($this->maintenanceModeMock, $proceed);

        $this->assertEquals($expectedAddresses, $result);
    }

    public function testAroundGetAddressInfoWithCacheFailure(): void
    {
        $expectedAddresses = ['filesystem_addresses'];
        $exception = new \Exception('Cache read failure');
        $proceedCalled = false;
        $proceed = function () use ($expectedAddresses, &$proceedCalled) {
            $proceedCalled = true;
            return $expectedAddresses;
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willThrowException($exception);

        $this->loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Cache address info retrieval failed, falling back to filesystem',
                ['exception' => $exception->getMessage()]
            );

        $result = $this->plugin->aroundGetAddressInfo($this->maintenanceModeMock, $proceed);

        $this->assertEquals($expectedAddresses, $result);
        $this->assertTrue($proceedCalled, 'Proceed should be called for fallback');
    }

    public function testAroundGetAddressInfoReturnsEmptyString(): void
    {
        $proceed = function () {
            return []; // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willReturn([]);

        $result = $this->plugin->aroundGetAddressInfo($this->maintenanceModeMock, $proceed);

        $this->assertEquals([], $result);
    }

    /**
     * Test multiple exception scenarios to ensure robust error handling
     */
    public function testMultipleExceptionScenarios(): void
    {
        $remoteAddr = '10.0.0.1';
        $proceedCalled = false;
        $proceed = function ($addr) use ($remoteAddr, &$proceedCalled) {
            $proceedCalled = true;
            $this->assertEquals($remoteAddr, $addr);
            return false;
        };

        $exception = new \RuntimeException('Runtime error');

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('isMaintenanceEnabled')
            ->willThrowException($exception);

        $result = $this->plugin->aroundIsOn($this->maintenanceModeMock, $proceed, $remoteAddr);
        $this->assertFalse($result);
        $this->assertTrue($proceedCalled, 'Proceed should be called for fallback');
    }

    public function testAroundSetAddressesSuccessful(): void
    {
        $addresses = '192.168.1.1,10.0.0.1';
        $proceed = function ($addresses) {
            // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceAddresses')
            ->with(['192.168.1.1', '10.0.0.1']);

        $this->loggerMock->expects($this->never())->method('warning');

        $this->plugin->aroundSetAddresses($this->maintenanceModeMock, $proceed, $addresses);
    }

    public function testAroundSetAddressesWithEmptyString(): void
    {
        $addresses = '';
        $proceed = function ($addresses) {
            // This should not be called
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceAddresses')
            ->with([]);

        $this->plugin->aroundSetAddresses($this->maintenanceModeMock, $proceed, $addresses);
    }

    public function testAroundSetAddressesWithCacheFailure(): void
    {
        $addresses = '192.168.1.1';
        $exception = new \Exception('Cache failure');
        $proceedCalled = false;
        $proceed = function ($receivedAddresses) use ($addresses, &$proceedCalled) {
            $proceedCalled = true;
            $this->assertEquals($addresses, $receivedAddresses);
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceAddresses')
            ->with(['192.168.1.1'])
            ->willThrowException($exception);

        $this->loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Cache address setting failed, falling back to filesystem',
                [
                    'exception' => $exception->getMessage(),
                    'addresses' => $addresses
                ]
            );

        $this->plugin->aroundSetAddresses($this->maintenanceModeMock, $proceed, $addresses);
        $this->assertTrue($proceedCalled, 'Proceed should be called for fallback');
    }
}
