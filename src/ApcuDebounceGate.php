<?php

namespace Drupal\interval_trigger;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

class ApcuDebounceGate implements DebounceGateInterface {

  public function __construct(
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function shouldProceed(string $key, int $minIntervalSeconds): bool {
    if (!\function_exists('apcu_fetch') || (\function_exists('apcu_enabled') && !apcu_enabled())) {
      return TRUE;
    }

    // Any failure here behaves like "no APCu": proceed, never block tasks.
    try {
      return $this->check($this->scopedKey($key), max(0, $minIntervalSeconds));
    }
    catch (\Throwable) {
      return TRUE;
    }
  }

  protected function check(string $fullKey, int $minIntervalSeconds): bool {
    $now = $this->time->getRequestTime();
    $ttl = $minIntervalSeconds * 2;

    $last = apcu_fetch($fullKey, $success);
    if ($success && !\is_int($last)) {
      // Something else wrote this key; take it over.
      apcu_store($fullKey, $now, $ttl);
      return TRUE;
    }
    if (!$success) {
      if (apcu_add($fullKey, $now, $ttl)) {
        return TRUE;
      }
      // Lost the race to another request: skip. But if the key still is not
      // there, APCu could not store it (full) — do not block tasks forever.
      apcu_fetch($fullKey, $exists);
      return !$exists;
    }
    if (($now - $last) < $minIntervalSeconds) {
      return FALSE;
    }
    if (!apcu_cas($fullKey, $last, $now)) {
      return FALSE;
    }
    apcu_store($fullKey, $now, $ttl);
    return TRUE;
  }

  protected function scopedKey(string $key): string {
    $siteUuid = (string) $this->configFactory->get('system.site')->get('uuid');
    return 'interval_trigger:' . $siteUuid . ':' . $key;
  }

}
