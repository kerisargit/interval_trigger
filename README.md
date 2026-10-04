# Interval Trigger

Runs tasks that have their own schedule, on cron and, if a task wants it, also
right after regular page requests (once the response has been sent). Useful
when a site's cron runs less often than a task's interval.

## Requirements

- Drupal 10.0+ or 11, PHP 8.1+, any core-supported database.
- APCu is recommended: it limits how often the request channel checks tasks
  (default: at most once every 5 seconds). Without it the site works, but the
  check runs on every request. The status report shows whether it is active.

## Writing a task

Implement `Drupal\interval_trigger\IntervalTaskInterface` and register it as a
service tagged `interval_trigger.task`:

```yaml
my_module.my_task:
  class: Drupal\my_module\MyTask
  arguments: ['@my_module.manager']
  tags:
    - { name: interval_trigger.task }
```

`respondsToTrigger('cron'|'request')` decides which channels the task joins,
`isDue()` whether it should run now, `run()` does the work. A failing task is
logged and does not affect the others.

Settings: *Configuration → System → Interval Trigger* (request debounce).

## Tests

```
SIMPLETEST_DB=sqlite://localhost/tmp/it.sqlite vendor/bin/phpunit -c core modules/custom/interval_trigger/tests
```

`ApcuDebounceGateTest` is skipped unless APCu is enabled for the CLI.
