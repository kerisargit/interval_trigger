<?php

declare(strict_types=1);

namespace Drupal\Tests\interval_trigger\Kernel;

/**
 * @group interval_trigger
 */
class TranslationTest extends ModuleTranslationTestBase {

  protected function moduleName(): string {
    return 'interval_trigger';
  }

  public function testKnownStrings(): void {
    $this->assertSame('Значение должно быть от 0 до 3600 секунд.', $this->ru('The value must be between 0 and 3600 seconds.'));
    $this->assertSame('Администрирование Interval Trigger', $this->ru('Administer Interval Trigger'));
  }

}
