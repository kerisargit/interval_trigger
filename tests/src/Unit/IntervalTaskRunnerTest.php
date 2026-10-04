<?php

declare(strict_types=1);

namespace Drupal\Tests\interval_trigger\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\interval_trigger\IntervalTaskInterface;
use Drupal\interval_trigger\IntervalTaskRunner;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\interval_trigger\IntervalTaskRunner
 * @group interval_trigger
 */
class IntervalTaskRunnerTest extends UnitTestCase {

  protected function createRunner(): IntervalTaskRunner {
    $logger = $this->prophesize(LoggerInterface::class);
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('interval_trigger')->willReturn($logger->reveal());
    return new IntervalTaskRunner($loggerFactory->reveal());
  }

  public function testTaskNotRespondingToTriggerIsSkippedEntirely(): void {
    $task = $this->prophesize(IntervalTaskInterface::class);
    $task->getTaskId()->willReturn('t1');
    $task->respondsToTrigger('cron')->willReturn(FALSE);

    $runner = $this->createRunner();
    $runner->addTask($task->reveal(), 't1');
    $runner->runDueTasks('cron');

    $this->addToAssertionCount(1);
  }

  public function testDueTaskRuns(): void {
    $task = $this->prophesize(IntervalTaskInterface::class);
    $task->getTaskId()->willReturn('t1');
    $task->respondsToTrigger('cron')->willReturn(TRUE);
    $task->isDue()->willReturn(TRUE);
    $task->run('cron')->shouldBeCalled();

    $runner = $this->createRunner();
    $runner->addTask($task->reveal(), 't1');
    $runner->runDueTasks('cron');
  }

  public function testNotDueTaskDoesNotRun(): void {
    $task = $this->prophesize(IntervalTaskInterface::class);
    $task->getTaskId()->willReturn('t1');
    $task->respondsToTrigger('cron')->willReturn(TRUE);
    $task->isDue()->willReturn(FALSE);

    $runner = $this->createRunner();
    $runner->addTask($task->reveal(), 't1');
    $runner->runDueTasks('cron');

    $this->addToAssertionCount(1);
  }

  public function testFailingTaskDoesNotBlockOthers(): void {
    $failing = $this->prophesize(IntervalTaskInterface::class);
    $failing->getTaskId()->willReturn('failing');
    $failing->respondsToTrigger('cron')->willReturn(TRUE);
    $failing->isDue()->willReturn(TRUE);
    $failing->run('cron')->willThrow(new \RuntimeException('Simulated task failure.'));

    $healthy = $this->prophesize(IntervalTaskInterface::class);
    $healthy->getTaskId()->willReturn('healthy');
    $healthy->respondsToTrigger('cron')->willReturn(TRUE);
    $healthy->isDue()->willReturn(TRUE);
    $healthy->run('cron')->shouldBeCalled();

    $runner = $this->createRunner();
    $runner->addTask($failing->reveal(), 'failing');
    $runner->addTask($healthy->reveal(), 'healthy');
    $runner->runDueTasks('cron');
  }

  public function testBrokenLoggerDoesNotBlockOthers(): void {
    $logger = $this->prophesize(LoggerInterface::class);
    $logger->error(Argument::cetera())->willThrow(new \RuntimeException('log storage is down'));
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('interval_trigger')->willReturn($logger->reveal());
    $runner = new IntervalTaskRunner($loggerFactory->reveal());

    $failing = $this->prophesize(IntervalTaskInterface::class);
    $failing->getTaskId()->willReturn('failing');
    $failing->respondsToTrigger('cron')->willReturn(TRUE);
    $failing->isDue()->willReturn(TRUE);
    $failing->run('cron')->willThrow(new \RuntimeException('task failed'));

    $healthy = $this->prophesize(IntervalTaskInterface::class);
    $healthy->getTaskId()->willReturn('healthy');
    $healthy->respondsToTrigger('cron')->willReturn(TRUE);
    $healthy->isDue()->willReturn(TRUE);
    $healthy->run('cron')->shouldBeCalled();

    $runner->addTask($failing->reveal(), 'failing');
    $runner->addTask($healthy->reveal(), 'healthy');
    $runner->runDueTasks('cron');
  }

  public function testFailingIsDueDoesNotBlockOthers(): void {
    $failing = $this->prophesize(IntervalTaskInterface::class);
    $failing->getTaskId()->willReturn('failing');
    $failing->respondsToTrigger('cron')->willReturn(TRUE);
    $failing->isDue()->willThrow(new \RuntimeException('Simulated isDue() failure.'));

    $healthy = $this->prophesize(IntervalTaskInterface::class);
    $healthy->getTaskId()->willReturn('healthy');
    $healthy->respondsToTrigger('cron')->willReturn(TRUE);
    $healthy->isDue()->willReturn(TRUE);
    $healthy->run('cron')->shouldBeCalled();

    $runner = $this->createRunner();
    $runner->addTask($failing->reveal(), 'failing');
    $runner->addTask($healthy->reveal(), 'healthy');
    $runner->runDueTasks('cron');
  }

  public function testDifferentTriggersAreIndependent(): void {
    $task = $this->prophesize(IntervalTaskInterface::class);
    $task->getTaskId()->willReturn('t1');
    $task->respondsToTrigger('cron')->willReturn(TRUE);
    $task->respondsToTrigger('request')->willReturn(FALSE);
    $task->isDue()->willReturn(TRUE);
    $task->run('cron')->shouldBeCalled();

    $runner = $this->createRunner();
    $runner->addTask($task->reveal(), 't1');
    $runner->runDueTasks('request');
    $runner->runDueTasks('cron');
  }

  public function testFailingRespondsToTriggerDoesNotBlockOthers(): void {
    $failing = $this->prophesize(IntervalTaskInterface::class);
    $failing->getTaskId()->willReturn('failing');
    $failing->respondsToTrigger('cron')->willThrow(new \RuntimeException('Simulated respondsToTrigger() failure.'));

    $healthy = $this->prophesize(IntervalTaskInterface::class);
    $healthy->getTaskId()->willReturn('healthy');
    $healthy->respondsToTrigger('cron')->willReturn(TRUE);
    $healthy->isDue()->willReturn(TRUE);
    $healthy->run('cron')->shouldBeCalled();

    $runner = $this->createRunner();
    $runner->addTask($failing->reveal(), 'failing');
    $runner->addTask($healthy->reveal(), 'healthy');
    $runner->runDueTasks('cron');
  }

  public function testFailureIsLogged(): void {
    $failing = $this->prophesize(IntervalTaskInterface::class);
    $failing->getTaskId()->willReturn('failing');
    $failing->respondsToTrigger('cron')->willReturn(TRUE);
    $failing->isDue()->willReturn(TRUE);
    $failing->run('cron')->willThrow(new \RuntimeException('boom'));

    $logger = $this->prophesize(LoggerInterface::class);
    $logger->error(\Prophecy\Argument::any(), \Prophecy\Argument::any())->shouldBeCalledTimes(1);
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('interval_trigger')->willReturn($logger->reveal());

    $runner = new IntervalTaskRunner($loggerFactory->reveal());
    $runner->addTask($failing->reveal(), 'failing');
    $runner->runDueTasks('cron');
  }

  public function testBrokenGetTaskIdWhileLoggingDoesNotBlockOthers(): void {
    $broken = $this->prophesize(IntervalTaskInterface::class);
    $broken->getTaskId()->willThrow(new \LogicException('getTaskId() fails too.'));
    $broken->respondsToTrigger('cron')->willReturn(TRUE);
    $broken->isDue()->willThrow(new \RuntimeException('isDue() failure'));

    $healthy = $this->prophesize(IntervalTaskInterface::class);
    $healthy->getTaskId()->willReturn('healthy');
    $healthy->respondsToTrigger('cron')->willReturn(TRUE);
    $healthy->isDue()->willReturn(TRUE);
    $healthy->run('cron')->shouldBeCalled();

    $runner = $this->createRunner();
    $runner->addTask($broken->reveal(), 'broken');
    $runner->addTask($healthy->reveal(), 'healthy');
    $runner->runDueTasks('cron');
  }

  public function testTasksRunInRegistrationOrderAndSameIdReplacesTask(): void {
    $first = $this->prophesize(IntervalTaskInterface::class);
    $first->getTaskId()->willReturn('same');
    $second = $this->prophesize(IntervalTaskInterface::class);
    $second->getTaskId()->willReturn('same');
    $second->respondsToTrigger('cron')->willReturn(TRUE);
    $second->isDue()->willReturn(TRUE);
    $second->run('cron')->shouldBeCalledTimes(1);

    $runner = $this->createRunner();
    $runner->addTask($first->reveal(), 'same');
    $runner->addTask($second->reveal(), 'same');
    $runner->runDueTasks('cron');
  }

}
