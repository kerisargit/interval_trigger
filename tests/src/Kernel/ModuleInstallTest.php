<?php

declare(strict_types=1);

namespace Drupal\Tests\interval_trigger\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\interval_trigger\ApcuDebounceGate;
use Drupal\interval_trigger\DebounceGateInterface;
use Drupal\interval_trigger\EventSubscriber\TerminateSubscriber;
use Drupal\interval_trigger\IntervalTaskRunner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @group interval_trigger
 */
class ModuleInstallTest extends KernelTestBase {

  protected static $modules = ['interval_trigger'];

  public function testRunnerServiceResolves(): void {
    $runner = $this->container->get('interval_trigger.runner');
    $this->assertInstanceOf(IntervalTaskRunner::class, $runner);

    $runner->runDueTasks('cron');
    $runner->runDueTasks('request');
    $this->addToAssertionCount(1);
  }

  public function testCronHookDoesNotThrow(): void {
    \Drupal::moduleHandler()->invoke('interval_trigger', 'cron');
    $this->addToAssertionCount(1);
  }

  public function testDebounceGateServicesResolveThroughContainer(): void {
    $gate = $this->container->get('interval_trigger.debounce_gate');
    $this->assertInstanceOf(ApcuDebounceGate::class, $gate);

    $viaInterface = $this->container->get(DebounceGateInterface::class);
    $this->assertInstanceOf(ApcuDebounceGate::class, $viaInterface);

    $subscriber = $this->container->get('interval_trigger.terminate_subscriber');
    $this->assertInstanceOf(TerminateSubscriber::class, $subscriber);
  }

  public function testRealTerminateEventDispatchDoesNotThrow(): void {
    $event = new TerminateEvent(
      $this->container->get('http_kernel'),
      Request::create('/'),
      new Response()
    );
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::TERMINATE);
    $this->addToAssertionCount(1);
  }

}
