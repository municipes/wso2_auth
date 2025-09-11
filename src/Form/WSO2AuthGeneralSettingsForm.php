<?php

namespace Drupal\wso2_auth\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wso2_auth\WSO2AuthService;

/**
 * General settings form for WSO2 authentication.
 */
class WSO2AuthGeneralSettingsForm extends ConfigFormBase {

  /**
   * The WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService
   */
  protected $wso2Auth;

  /**
   * Constructor for the WSO2 authentication settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, WSO2AuthService $wso2_auth) {
    parent::__construct($config_factory);
    $this->wso2Auth = $wso2_auth;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('wso2_auth.authentication')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wso2_auth_general_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['wso2_auth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('wso2_auth.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable WSO2 Authentication'),
      '#default_value' => $config->get('enabled') ?? FALSE,
      '#description' => $this->t('Enable or disable authentication with WSO2 Identity Server.'),
    ];

    $form['stage'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use staging environment'),
      '#default_value' => $config->get('stage') ?? FALSE,
      '#description' => $this->t('Use staging environment instead of production.'),
      '#required' => FALSE,
    ];

    $form['skip_ssl_verification'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skip SSL verification'),
      '#default_value' => $config->get('skip_ssl_verification') ?? FALSE,
      '#description' => $this->t('ONLY FOR DEVELOPMENT. Skip SSL certificate verification.'),
    ];

    $form['note'] = [
      '#markup' => $this->t('The path to enable in WSO2 as redirect is always "oauth2/callback". <br>'),
    ];

    $form['wso2_config'] = [
      '#type' => 'details',
      '#title' => $this->t('WSO2 Configuration'),
      '#open' => TRUE,
    ];

    $form['wso2_config']['ag_entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID (agEntityId)'),
      '#default_value' => $config->get('ag_entity_id') ?? 'FIRENZE',
      '#description' => $this->t('The entity ID to use for authentication (e.g., FIRENZE).'),
      '#required' => TRUE,
    ];

    $form['wso2_config']['com_entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID (comEntityId)'),
      '#default_value' => $config->get('com_entity_id') ?? 'FIRENZE',
      '#description' => $this->t('The additional parameter comEntityId.'),
      '#required' => TRUE,
    ];

    $form['appearance'] = [
      '#type' => 'details',
      '#title' => $this->t('Appearance'),
      '#open' => TRUE,
    ];

    $form['appearance']['citizen_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Abilita login cittadino su pagina user'),
      '#default_value' => $config->get('citizen_enabled') ?? FALSE,
      // '#description' => $this->t('Display identity provider logo on the login form.'),
    ];

    $form['appearance']['picture_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Abilita login operatore su pagina user'),
      '#default_value' => $config->get('picture_enabled') ?? FALSE,
      '#description' => $this->t('Display identity provider logo on the login form.'),
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['external_domains_whitelist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('External domains whitelist'),
      '#default_value' => $config->get('external_domains_whitelist') ?? '',
      '#description' => $this->t('List of external domains allowed for post-authentication redirects. One domain per line (e.g., example.com, trusted-site.org). Leave empty to disable external redirects.'),
      '#rows' => 5,
      '#placeholder' => "example.com\ntrusted-site.org\npartner.domain.it",
    ];

    $form['advanced']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#default_value' => $config->get('debug') ?? FALSE,
      '#description' => $this->t('Enable debugging of WSO2 authentication. This will log additional information.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Debug dei valori
    // if ($this->configFactory->get('wso2_auth.settings')->get('debug')) {
    //   $this->logger('wso2_auth')->debug('WSO2 General Settings form values: @values', [
    //     '@values' => print_r($form_state->getValues(), TRUE),
    //   ]);
    // }

    $values = $form_state->getValues();

    $this->config('wso2_auth.settings')
      ->set('enabled', (bool) $values['enabled'])
      ->set('stage', (bool) $values['stage'])
      ->set('skip_ssl_verification', (bool) $values['skip_ssl_verification'])
      ->set('ag_entity_id', $values['ag_entity_id'])
      ->set('com_entity_id', $values['com_entity_id'])
      ->set('picture_enabled', (bool) $values['picture_enabled'])
      ->set('citizen_enabled', (bool) $values['citizen_enabled'])
      ->set('external_domains_whitelist', $values['external_domains_whitelist'])
      ->set('debug', (bool) $values['debug'])
      ->save();

    // Pulisce la cache per assicurarsi che le modifiche vengano applicate immediatamente
    \Drupal::service('cache.config')->deleteAll();

    parent::submitForm($form, $form_state);
  }
}
