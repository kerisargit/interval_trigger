<?php

declare(strict_types=1);

namespace Drupal\Tests\interval_trigger\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\interval_trigger\DebounceGateInterface;
use Drupal\interval_trigger\EventSubscriber\TerminateSubscriber;
use Drupal\interval_trigger\IntervalTaskRunner;
use Prophecy\Argument;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @group interval_trigger
 */
class TerminateSubscriberTest extends UnitTestCase {

  protected function createEvent(): TerminateEvent {
    return new TerminateEvent(
      $this->prophesize(HttpKernelInterface::class)->reveal(),
      Request::create('/'),
      new Response()
    );
  }

  protected function createConfigFactory(?int $debounceSeconds): ConfigFactoryInterface {
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('debounce_seconds')->willReturn($debounceSeconds);

    $configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $configFactory->get('interval_trigger.settings')->willReturn($config->reveal());
    return $configFactory->reveal();
  }

  protected function containerWith(IntervalTaskRunner $runner): ContainerInterface {
    $container = new ContainerBuilder();
    $container->set('interval_trigger.runner', $runner);
    return $container;
  }

  public function testRunnerCalledWhenGateAllows(): void {
    $runner = $this->prophesize(IntervalTaskRunner::class);
    $runner->runDueTasks('request')->shouldBeCalled();

    $gate = $this->prophesize(DebounceGateInterface::class);
    $gate->shouldProceed(Argument::type('string'), Argument::type('int'))->willReturn(TRUE);

    $subscriber = new TerminateSubscriber($this->containerWith($runner->reveal()), $gate->reveal(), $this->createConfigFactory(5));
    $subscriber->onTerminate($this->createEvent());
  }

  public function testRunnerNotCalledWhenGateDenies(): void {
    $runner = $this->prophesize(IntervalTaskRunner::class);

    $gate = $this->prophesize(DebounceGateInterface::class);
    $gate->shouldProceed(Argument::type('string'), Argument::type('int'))->willReturn(FALSE);

    $subscriber = new TerminateSubscriber($this->containerWith($runner->reveal()), $gate->reveal(), $this->createConfigFactory(5));
    $subscriber->onTerminate($this->createEvent());

    $this->addToAssertionCount(1);
  }

  public function testRunnerNotEvenBuiltWhenGateDenies(): void {
    $container = $this->prophesize(ContainerInterface::class);
    $container->get(Argument::any())->shouldNotBeCalled();

    $gate = $this->prophesize(DebounceGateInterface::class);
    $gate->shouldProceed(Argument::type('string'), Argument::type('int'))->willReturn(FALSE);

    (new TerminateSubscriber($container->reveal(), $gate->reveal(), $this->createConfigFactory(5)))
      ->onTerminate($this->createEvent());
  }

  public function testNothingEscapesEvenWithBrokenGateAndLogger(): void {
    $container = $this->prophesize(ContainerInterface::class);
    $container->get('logger.factory')->willThrow(new \RuntimeException('no logger'));

    $gate = $this->prophesize(DebounceGateInterface::class);
    $gate->shouldProceed(Argument::cetera())->willThrow(new \RuntimeException('gate broken'));

    (new TerminateSubscriber($container->reveal(), $gate->reveal(), $this->createConfigFactory(5)))
      ->onTerminate($this->createEvent());
    $this->addToAssertionCount(1);
  }

  public function testConfiguredDebounceSecondsIsPassedToGate(): void {
    $runner = $this->prophesize(IntervalTaskRunner::class);
    $runner->runDueTasks('request')->shouldBeCalled();

    $gate = $this->prophesize(DebounceGateInterface::class);
    $gate->shouldProceed(Argument::any(), 30)->willReturn(TRUE)->shouldBeCalled();

    $subscriber = new TerminateSubscriber($this->containerWith($runner->reveal()), $gate->reveal(), $this->createConfigFactory(30));
    $subscriber->onTerminate($this->createEvent());
  }

  public function testFallsBackToDefaultWhenConfigValueIsNull(): void {
    $runner = $this->prophesize(IntervalTaskRunner::class);
    $runner->runDueTasks('request')->shouldBeCalled();

    $gate = $this->prophesize(DebounceGateInterface::class);
    $gate->shouldProceed(Argument::any(), 5)->willReturn(TRUE)->shouldBeCalled();

    $subscriber = new TerminateSubscriber($this->containerWith($runner->reveal()), $gate->reveal(), $this->createConfigFactory(NULL));
    $subscriber->onTerminate($this->createEvent());
  }

}
