<?php

namespace Drupal\wso2_auth_check\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for WSO2 Authentication Check.
 */
class WSO2AuthCheckSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wso2_auth_check_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['wso2_auth_check.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('wso2_auth_check.settings');

    $form['info'] = [
      '#markup' => '<div class="messages messages--info">' . $this->t('Configure automatic WSO2 session check for users already authenticated with WSO2 Identity Server on other sites.') . '</div>',
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic session check'),
      '#default_value' => $config->get('enabled') ?? FALSE,
      '#description' => $this->t('Enable automatic detection of existing WSO2 sessions.'),
    ];

    $form['check_every_page'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check on every page load'),
      '#default_value' => $config->get('check_every_page') ?? FALSE,
      '#description' => $this->t('If enabled, the system will check on every page load if the time interval has passed. If disabled, it will only check once per session.'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['check_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Session check interval'),
      '#default_value' => $config->get('check_interval') ?? 300,
      '#min' => 10,
      '#step' => 1,
      '#description' => $this->t('Minimum time in seconds between session checks (only applies if checking on every page load).'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
          ':input[name="check_every_page"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['excluded_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded paths'),
      '#default_value' => $config->get('excluded_paths') ?? '',
      '#description' => $this->t('Enter one path per line. The session check will be skipped for these paths. Example: /node/1'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('wso2_auth_check.settings')
      ->set('enabled', (bool) $values['enabled'])
      ->set('check_every_page', (bool) $values['check_every_page'])
      ->set('check_interval', (int) $values['check_interval'])
      ->set('excluded_paths', $values['excluded_paths'])
      ->save();

    parent::submitForm($form, $form_state);

    // Pulisce la cache per assicurarsi che le modifiche vengano applicate immediatamente
    \Drupal::service('cache.config')->deleteAll();
  }

}
