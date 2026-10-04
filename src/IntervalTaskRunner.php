<?php

namespace Drupal\interval_trigger;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

class IntervalTaskRunner {

  protected LoggerInterface $logger;

  /**
   * @var \Drupal\interval_trigger\IntervalTaskInterface[]
   */
  protected array $tasks = [];

  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->logger = $loggerFactory->get('interval_trigger');
  }

  public function addTask(IntervalTaskInterface $task, string $id): void {
    $this->tasks[$id] = $task;
  }

  public function runDueTasks(string $trigger): void {
    foreach ($this->tasks as $id => $task) {
      try {
        if (!$task->respondsToTrigger($trigger)) {
          continue;
        }
      }
      catch (\Throwable $e) {
        $this->logFailure($task, $id, 'respondsToTrigger()', $e);
        continue;
      }

      try {
        $due = $task->isDue();
      }
      catch (\Throwable $e) {
        $this->logFailure($task, $id, 'isDue()', $e);
        continue;
      }

      if (!$due) {
        continue;
      }

      try {
        $task->run($trigger);
      }
      catch (\Throwable $e) {
        $this->logFailure($task, $id, 'run()', $e);
      }
    }
  }

  protected function logFailure(IntervalTaskInterface $task, string $id, string $stage, \Throwable $e): void {
    try {
      $name = $task->getTaskId();
    }
    catch (\Throwable) {
      $name = $id;
    }

    try {
      $this->logger->error('Task @task (@id): @stage failed with an exception: @class: @message', [
        '@task' => $name,
        '@id' => $id,
        '@stage' => $stage,
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
    }
    catch (\Throwable) {
      // A broken logger must not stop the remaining tasks.
    }
  }

}
