<?php

declare(strict_types=1);

namespace Drupal\Tests\interval_trigger\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\interval_trigger\ApcuDebounceGate;

/**
 * @group interval_trigger
 */
class ApcuDebounceGateTest extends UnitTestCase {

  protected function createGate(int $requestTime = 1000, string $siteUuid = '11111111-1111-1111-1111-111111111111'): ApcuDebounceGate {
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('uuid')->willReturn($siteUuid);

    $configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $configFactory->get('system.site')->willReturn($config->reveal());

    $time = $this->prophesize(TimeInterface::class);
    $time->getRequestTime()->willReturn($requestTime);

    return new ApcuDebounceGate($time->reveal(), $configFactory->reveal());
  }

  public function testFallsBackToAlwaysProceedWithoutApcu(): void {
    if (\function_exists('apcu_fetch')) {
      $this->markTestSkipped('APCu is available here; this test is about it being absent.');
    }

    $gate = $this->createGate();

    $this->assertTrue($gate->shouldProceed('test-key', 5));
    $this->assertTrue($gate->shouldProceed('test-key', 5));
    $this->assertTrue($gate->shouldProceed('test-key', 5));
  }

  public function testProceedsAlwaysWhenApcuLoadedButDisabled(): void {
    if (!\function_exists('apcu_enabled') || apcu_enabled()) {
      $this->markTestSkipped('Needs APCu loaded but disabled.');
    }
    $gate = $this->createGate();
    $this->assertTrue($gate->shouldProceed('disabled-key', 5));
    $this->assertTrue($gate->shouldProceed('disabled-key', 5));
  }

  public function testDebouncesWithinIntervalWhenApcuAvailable(): void {
    if (!\function_exists('apcu_fetch') || (\function_exists('apcu_enabled') && !apcu_enabled())) {
      $this->markTestSkipped('APCu is not available in this environment.');
    }

    $key = uniqid('vpr-test-', TRUE);

    $atT1000 = $this->createGate(1000);
    $this->assertTrue($atT1000->shouldProceed($key, 5), 'First call for a new key proceeds.');

    $atT1002 = $this->createGate(1002);
    $this->assertFalse($atT1002->shouldProceed($key, 5), '2 s later the 5 s window is still closed.');

    $atT1006 = $this->createGate(1006);
    $this->assertTrue($atT1006->shouldProceed($key, 5), '6 s later (past the 5 s window) it proceeds again.');

    $atT1007 = $this->createGate(1007);
    $this->assertFalse($atT1007->shouldProceed($key, 5), 'Right after a pass at t=1006 it is closed again.');
  }

  public function testZeroIntervalNeverBlocks(): void {
    if (!\function_exists('apcu_fetch') || (\function_exists('apcu_enabled') && !apcu_enabled())) {
      $this->markTestSkipped('APCu is unavailable or disabled.');
    }
    $key = uniqid('vpr-test-zero-', TRUE);
    $gate = $this->createGate(1000);
    $this->assertTrue($gate->shouldProceed($key, 0));
    $this->assertTrue($gate->shouldProceed($key, 0), 'With interval 0 a second call in the same second proceeds too.');
    $this->assertTrue($this->createGate(1001)->shouldProceed($key, 0));
  }

  public function testDifferentKeysAreIndependent(): void {
    if (!\function_exists('apcu_fetch') || (\function_exists('apcu_enabled') && !apcu_enabled())) {
      $this->markTestSkipped('APCu is unavailable or disabled.');
    }
    $a = uniqid('vpr-test-a-', TRUE);
    $b = uniqid('vpr-test-b-', TRUE);
    $gate = $this->createGate(1000);
    $this->assertTrue($gate->shouldProceed($a, 5));
    $this->assertTrue($gate->shouldProceed($b, 5), 'Key A must not block key B.');
    $this->assertFalse($gate->shouldProceed($a, 5));
    $this->assertFalse($gate->shouldProceed($b, 5));
  }

  public function testDifferentSitesDoNotShareDebounceWindow(): void {
    if (!\function_exists('apcu_fetch') || (\function_exists('apcu_enabled') && !apcu_enabled())) {
      $this->markTestSkipped('APCu is unavailable or disabled.');
    }
    $key = uniqid('vpr-test-site-', TRUE);
    $siteA = $this->createGate(1000, '11111111-1111-1111-1111-111111111111');
    $siteB = $this->createGate(1000, '22222222-2222-2222-2222-222222222222');
    $this->assertTrue($siteA->shouldProceed($key, 5));
    $this->assertTrue($siteB->shouldProceed($key, 5), 'Another site (another uuid) has its own window.');
    $this->assertFalse($siteA->shouldProceed($key, 5));
  }

  public function testAtomicClaimOfStaleKeyLetsOnlyOneCallerThrough(): void {
    if (!\function_exists('apcu_fetch') || (\function_exists('apcu_enabled') && !apcu_enabled())) {
      $this->markTestSkipped('APCu is unavailable or disabled.');
    }
    $key = uniqid('vpr-test-race-', TRUE);
    $first = $this->createGate(1000);
    $this->assertTrue($first->shouldProceed($key, 5));

    $winner = $this->createGate(1010);
    $loser = $this->createGate(1010);
    $results = [$winner->shouldProceed($key, 5), $loser->shouldProceed($key, 5)];
    $this->assertSame(1, count(array_filter($results)), 'Of two requests in the same second exactly one proceeds.');
  }

}
