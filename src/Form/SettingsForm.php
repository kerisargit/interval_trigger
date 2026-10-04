<?php

namespace Drupal\interval_trigger\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SettingsForm extends ConfigFormBase {

  protected ModuleHandlerInterface $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

  protected function getEditableConfigNames() {
    return ['interval_trigger.settings'];
  }

  public function getFormId() {
    return 'interval_trigger_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('interval_trigger.settings');

    $form['debounce_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum interval between checks on the "regular site requests" channel (seconds)'),
      '#default_value' => (int) ($config->get('debounce_seconds') ?? 5),
      '#min' => 0,
      '#max' => 3600,
      '#description' => $this->t('The check fires on every request to the site; this value limits real task checks to at most once per N seconds. 0 disables the limit. Requires the PHP apcu extension; without it there is no limit.'),
    ];
    if ($this->moduleHandler->moduleExists('help')) {
      $form['debounce_seconds']['#description'] = $this->t('@text <a href=":url">More…</a>', [
        '@text' => $form['debounce_seconds']['#description'],
        ':url' => Url::fromRoute('help.help_topic', ['id' => 'interval_trigger.request_checks'])->toString(),
      ]);
    }

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $value = (int) $form_state->getValue('debounce_seconds');
    if ($value < 0 || $value > 3600) {
      $form_state->setErrorByName('debounce_seconds', $this->t('The value must be between 0 and 3600 seconds.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('interval_trigger.settings')
      ->set('debounce_seconds', (int) $form_state->getValue('debounce_seconds'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
