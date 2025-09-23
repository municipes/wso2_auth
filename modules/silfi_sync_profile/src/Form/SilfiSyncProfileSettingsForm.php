<?php

namespace Drupal\silfi_sync_profile\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Silfi Sync Profile module.
 */
class SilfiSyncProfileSettingsForm extends ConfigFormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'silfi_sync_profile_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['silfi_sync_profile.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('silfi_sync_profile.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable profile synchronization'),
      '#default_value' => $config->get('enabled') ?? TRUE,
      '#description' => $this->t('Enable automatic synchronization of user profile data from OpenCity service.'),
    ];

    // API Manager Settings
    $form['api_manager'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenCity API Configuration'),
      '#open' => TRUE,
      '#description' => $this->t('Configure the connection to OpenCity API for profile synchronization.'),
    ];

    $form['api_manager']['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#options' => [
        'staging' => $this->t('Staging (api-staging.055055.it)'),
        'production' => $this->t('Production (api.055055.it)'),
      ],
      '#default_value' => $config->get('api_manager.environment') ?? 'staging',
      '#description' => $this->t('Select the OpenCity API environment to use.'),
      '#required' => TRUE,
    ];

    $form['api_manager']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('api_manager.client_id') ?? '',
      '#size' => 50,
      '#maxlength' => 128,
      '#description' => $this->t('The client ID for OpenCity API authentication.'),
      '#required' => TRUE,
    ];

    $form['api_manager']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('api_manager.client_secret') ?? '',
      '#size' => 50,
      '#maxlength' => 128,
      '#description' => $this->t('The client secret for OpenCity API authentication. Leave empty to keep the current value.'),
      '#attributes' => [
        'placeholder' => $config->get('api_manager.client_secret') ? '••••••••••••••••' : '',
      ],
    ];

    // Sync Settings
    $form['sync'] = [
      '#type' => 'details',
      '#title' => $this->t('Synchronization Settings'),
      '#open' => TRUE,
    ];

    $form['sync']['sync_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Sync interval'),
      '#options' => [
        1800 => $this->t('30 minutes'),
        3600 => $this->t('1 hour'),
        7200 => $this->t('2 hours'),
        14400 => $this->t('4 hours'),
        43200 => $this->t('12 hours'),
        86400 => $this->t('24 hours'),
      ],
      '#default_value' => $config->get('sync.interval') ?? 1800,
      '#description' => $this->t('How often to sync profile data for each user. Sync will only occur when user accesses booking appointments.'),
      '#required' => TRUE,
    ];

    $form['sync']['fields_to_sync'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Fields to synchronize'),
      '#options' => [
        'firstname' => $this->t('First name (nome)'),
        'lastname' => $this->t('Last name (cognome)'),
        'email' => $this->t('Email'),
        'mobile' => $this->t('Mobile phone (cellulare)'),
      ],
      '#default_value' => $config->get('sync.fields') ?? ['firstname', 'lastname', 'email', 'mobile'],
      '#description' => $this->t('Select which user fields should be synchronized from OpenCity.'),
    ];

    // Debug Settings
    $form['debug'] = [
      '#type' => 'details',
      '#title' => $this->t('Debug Settings'),
      '#open' => FALSE,
    ];

    $form['debug']['enable_debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug logging'),
      '#default_value' => $config->get('debug.enabled') ?? FALSE,
      '#description' => $this->t('Enable detailed logging for sync operations. Only enable for troubleshooting.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('silfi_sync_profile.settings');

    // General settings
    $config->set('enabled', (bool) $values['enabled']);

    // API Manager settings
    $config->set('api_manager.environment', $values['environment']);
    $config->set('api_manager.client_id', $values['client_id']);

    // Only save client secret if a new one was provided
    $client_secret = $values['client_secret'];
    if (!empty($client_secret)) {
      $config->set('api_manager.client_secret', $client_secret);
    }

    // Sync settings
    $config->set('sync.interval', (int) $values['sync_interval']);
    $config->set('sync.fields', array_filter($values['fields_to_sync']));

    // Debug settings
    $config->set('debug.enabled', (bool) $values['enable_debug']);

    $config->save();

    // Clear cache to ensure changes are applied
    \Drupal::service('cache.config')->deleteAll();

    parent::submitForm($form, $form_state);
  }

}