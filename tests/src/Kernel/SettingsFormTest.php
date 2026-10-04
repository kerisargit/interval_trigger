<?php

declare(strict_types=1);

namespace Drupal\Tests\interval_trigger\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\interval_trigger\Form\SettingsForm;
use Drupal\KernelTests\KernelTestBase;

/**
 * @group interval_trigger
 */
class SettingsFormTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'interval_trigger',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['interval_trigger']);
  }

  protected function createForm(): SettingsForm {
    return SettingsForm::create($this->container);
  }

  /**
   * @dataProvider outOfBoundsProvider
   */
  public function testRejectsOutOfBoundsValue(int $value): void {
    $form_object = $this->createForm();
    $form = [];
    $form_state = new FormState();
    $form_state->setValues(['debounce_seconds' => $value]);
    $form_object->validateForm($form, $form_state);

    $this->assertNotEmpty($form_state->getErrors());
  }

  public static function outOfBoundsProvider(): array {
    return [
      'negative' => [-1],
      'too large' => [3601],
    ];
  }

  public function testAcceptsBoundaryValues(): void {
    foreach ([0, 3600] as $value) {
      $form_object = $this->createForm();
      $form = [];
      $form_state = new FormState();
      $form_state->setValues(['debounce_seconds' => $value]);
      $form_object->validateForm($form, $form_state);

      $this->assertEmpty($form_state->getErrors(), "Граничное значение $value должно проходить валидацию.");
    }
  }

  public function testSubmitPersistsValue(): void {
    $form_object = $this->createForm();
    $form = [];
    $form_state = new FormState();
    $form_state->setValues(['debounce_seconds' => 42]);
    $form_object->submitForm($form, $form_state);

    $this->assertSame(42, $this->container->get('config.factory')->get('interval_trigger.settings')->get('debounce_seconds'));
  }

}
