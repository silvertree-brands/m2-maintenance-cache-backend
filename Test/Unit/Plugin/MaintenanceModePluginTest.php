<?php
/**
 * Copyright (c) 2025. Silvertree Brands
 */

declare(strict_types=1);

namespace Silvertree\MaintenanceCacheBackend\Test\Unit\Plugin;

/**
 * Unit test for MaintenanceModePlugin
 */
#[\PHPUnit\Framework\Attributes\Group('silvertree-vendor')]
#[\PHPUnit\Framework\Attributes\CoversClass(\Silvertree\MaintenanceCacheBackend\Plugin\MaintenanceModePlugin::class)]
class MaintenanceModePluginTest extends \PHPUnit\Framework\TestCase
{
    private \Silvertree\MaintenanceCacheBackend\Plugin\MaintenanceModePlugin $plugin;

    /** @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $loggerMock;

    /** @var \Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService&\PHPUnit\Framework\MockObject\MockObject */
    private $cacheMaintenanceServiceMock;

    /** @var \Magento\Framework\App\MaintenanceMode&\PHPUnit\Framework\MockObject\MockObject */
    private $maintenanceModeMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->cacheMaintenanceServiceMock = $this->createMock(
            \Silvertree\MaintenanceCacheBackend\Service\CacheMaintenanceService::class
        );
        $this->maintenanceModeMock = $this->createMock(\Magento\Framework\App\MaintenanceMode::class);

        $this->plugin = new \Silvertree\MaintenanceCacheBackend\Plugin\MaintenanceModePlugin(
            $this->loggerMock,
            $this->cacheMaintenanceServiceMock
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundIsOnSuccessful(): void
    {
        $remoteAddr = '192.168.1.1';
        $proceed = function ($addr) {
            $this->fail("proceed() unexpectedly called with addr={$addr}");
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('isMaintenanceEnabled')
            ->willReturn(true);

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willReturn(['10.0.0.1', '192.168.1.2']);

        $this->loggerMock->expects($this->never())->method('warning');

        $result = $this->plugin->aroundIsOn($this->maintenanceModeMock, $proceed, $remoteAddr);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundIsOnWithWhitelistedIp(): void
    {
        $remoteAddr = '192.168.1.1';
        $proceed = function ($addr) {
            $this->fail("proceed() unexpectedly called with addr={$addr}");
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('isMaintenanceEnabled')
            ->willReturn(true);

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willReturn(['192.168.1.1', '10.0.0.1']);

        $result = $this->plugin->aroundIsOn($this->maintenanceModeMock, $proceed, $remoteAddr);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundIsOnWhenMaintenanceDisabled(): void
    {
        $remoteAddr = '192.168.1.1';
        $proceed = function ($addr) {
            $this->fail("proceed() unexpectedly called with addr={$addr}");
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('isMaintenanceEnabled')
            ->willReturn(false);

        $this->cacheMaintenanceServiceMock
            ->expects($this->never())
            ->method('getMaintenanceAddresses');

        $result = $this->plugin->aroundIsOn($this->maintenanceModeMock, $proceed, $remoteAddr);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundSetSuccessful(): void
    {
        $isOn = true;
        $proceed = function ($receivedIsOn) {
            $this->fail(
                'proceed() unexpectedly called: '
                . \json_encode($receivedIsOn, \JSON_THROW_ON_ERROR)
            );
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceMode')
            ->with($isOn);

        $this->loggerMock->expects($this->never())->method('warning');

        $this->plugin->aroundSet($this->maintenanceModeMock, $proceed, $isOn);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundSetWithCacheFailure(): void
    {
        $isOn = true;
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

        $this->plugin->aroundSet($this->maintenanceModeMock, $proceed, $isOn);
        $this->assertTrue($proceedCalled, 'Proceed should be called for fallback');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundSetWhenMaintenanceOff(): void
    {
        $isOn = false;
        $proceed = function ($receivedIsOn) {
            $this->fail(
                'proceed() unexpectedly called: '
                . \json_encode($receivedIsOn, \JSON_THROW_ON_ERROR)
            );
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceMode')
            ->with($isOn);

        $this->plugin->aroundSet($this->maintenanceModeMock, $proceed, $isOn);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundGetAddressInfoSuccessful(): void
    {
        $expectedAddresses = ['192.168.1.1', '10.0.0.1'];
        $proceed = function () {
            return ['filesystem_addresses'];
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willReturn($expectedAddresses);

        $this->loggerMock->expects($this->never())->method('warning');

        $result = $this->plugin->aroundGetAddressInfo($this->maintenanceModeMock, $proceed);

        $this->assertEquals($expectedAddresses, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundGetAddressInfoReturnsEmptyArray(): void
    {
        $proceed = function () {
            return [];
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('getMaintenanceAddresses')
            ->willReturn([]);

        $result = $this->plugin->aroundGetAddressInfo($this->maintenanceModeMock, $proceed);

        $this->assertEquals([], $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundSetAddressesSuccessful(): void
    {
        $addresses = '192.168.1.1,10.0.0.1';
        $proceed = function ($receivedAddresses) {
            $this->fail(
                'proceed() unexpectedly called: '
                . \json_encode($receivedAddresses, \JSON_THROW_ON_ERROR)
            );
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceAddresses')
            ->with(['192.168.1.1', '10.0.0.1']);

        $this->loggerMock->expects($this->never())->method('warning');

        $this->plugin->aroundSetAddresses($this->maintenanceModeMock, $proceed, $addresses);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAroundSetAddressesWithEmptyString(): void
    {
        $addresses = '';
        $proceed = function ($receivedAddresses) {
            $this->fail(
                'proceed() unexpectedly called: '
                . \json_encode($receivedAddresses, \JSON_THROW_ON_ERROR)
            );
        };

        $this->cacheMaintenanceServiceMock
            ->expects($this->once())
            ->method('setMaintenanceAddresses')
            ->with([]);

        $this->plugin->aroundSetAddresses($this->maintenanceModeMock, $proceed, $addresses);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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
