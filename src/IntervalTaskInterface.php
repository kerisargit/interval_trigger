<?php

namespace Drupal\interval_trigger;

/**
 * A task with its own schedule. Register as a service tagged
 * 'interval_trigger.task'.
 */
interface IntervalTaskInterface {

  /**
   * Identifier used in log messages.
   */
  public function getTaskId(): string;

  /**
   * Whether the task wants to be checked on this channel.
   *
   * @param string $trigger
   *   'cron' or 'request' (after the response has been sent).
   */
  public function respondsToTrigger(string $trigger): bool;

  /**
   * Whether the task should run now; also covers "is it enabled at all".
   */
  public function isDue(): bool;

  public function run(string $trigger): void;

}
