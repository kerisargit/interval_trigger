<?php

namespace Drupal\interval_trigger;

interface DebounceGateInterface {

  public function shouldProceed(string $key, int $minIntervalSeconds): bool;

}
