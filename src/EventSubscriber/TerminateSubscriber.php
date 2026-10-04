<?php

namespace Drupal\interval_trigger\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\interval_trigger\DebounceGateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs due interval tasks after the response has been sent.
 */
class TerminateSubscriber implements EventSubscriberInterface {

  protected const DEFAULT_DEBOUNCE_SECONDS = 5;

  protected const DEBOUNCE_KEY = 'terminate_subscriber';

  public function __construct(
    protected ContainerInterface $container,
    protected DebounceGateInterface $debounceGate,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  public function onTerminate(TerminateEvent $event): void {
    // The response is already sent; nothing here may surface to the visitor
    // or stop other terminate listeners.
    try {
      $debounceSeconds = (int) ($this->configFactory->get('interval_trigger.settings')->get('debounce_seconds') ?? self::DEFAULT_DEBOUNCE_SECONDS);

      if (!$this->debounceGate->shouldProceed(self::DEBOUNCE_KEY, $debounceSeconds)) {
        return;
      }
      // Fetched only past the gate: building the runner builds every task and
      // its dependencies, which most requests never need. (!service_closure
      // would do this too, but needs Drupal 10.3+.)
      $this->container->get('interval_trigger.runner')->runDueTasks('request');
    }
    catch (\Throwable $e) {
      $this->logError($e);
    }
  }

  protected function logError(\Throwable $e): void {
    try {
      $this->container->get('logger.factory')->get('interval_trigger')
        ->error('Request channel failed: @class: @message', ['@class' => \get_class($e), '@message' => $e->getMessage()]);
    }
    catch (\Throwable) {
      // Logging itself is broken; there is nowhere left to report to.
    }
  }

}
