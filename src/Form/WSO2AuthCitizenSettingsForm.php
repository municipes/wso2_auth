<?php

namespace Drupal\wso2_auth\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wso2_auth\WSO2AuthService;
use Drupal\wso2_auth\Helper\CheckUserFieldExist;

/**
 * Citizen settings form for WSO2 authentication.
 */
class WSO2AuthCitizenSettingsForm extends ConfigFormBase {

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
    return 'wso2_auth_citizen_settings_form';
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

    // Add OAuth2 fieldset.
    $form['oauth2'] = [
      '#type' => 'details',
      '#title' => $this->t('OAuth2 information'),
      '#open' => TRUE,
    ];

    // OAuth2 client_id.
    $form['oauth2']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 Client ID'),
      '#default_value' => $config->get('citizen.client_id'),
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The OAuth2 client ID.'),
      '#required' => TRUE,
    ];

    // OAuth2 client_secret.
    $form['oauth2']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('OAuth2 Client Secret'),
      '#default_value' => $config->get('citizen.client_secret'),
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The OAuth2 client secret.'),
      '#required' => TRUE,
    ];

    $form['oauth2']['scope'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Scope'),
      '#default_value' => $config->get('citizen.scope') ?? 'openid',
      '#description' => $this->t('The OAuth2 scope to request (e.g., openid).'),
      '#required' => TRUE,
    ];

    // Add API Manager fieldset.
    $form['api_manager'] = [
      '#type' => 'details',
      '#title' => $this->t('API Manager'),
      '#open' => TRUE,
    ];

    // API Manager environment selection.
    $form['api_manager']['api_manager_environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#options' => [
        'staging' => $this->t('Staging'),
        'production' => $this->t('Production'),
      ],
      '#default_value' => $config->get('citizen.api_manager_environment') ?? 'staging',
      '#description' => $this->t('Select the API Manager environment to use.'),
      '#required' => TRUE,
    ];

    // API Manager client_id.
    $form['api_manager']['api_manager_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('citizen.api_manager_client_id'),
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The API Manager client ID.'),
      '#required' => FALSE,
    ];

    // API Manager client_secret.
    $form['api_manager']['api_manager_client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('citizen.api_manager_client_secret'),
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The API Manager client secret.'),
      '#required' => FALSE,
    ];

    $form['user_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('User Settings'),
      '#open' => TRUE,
    ];

    $form['user_settings']['auto_register'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-register users'),
      '#default_value' => $config->get('citizen.auto_register') ?? TRUE,
      '#description' => $this->t('Automatically register new users when they authenticate with WSO2.'),
    ];

    // Define the role for authenticated users
    $roles = array_map([
      '\\Drupal\\Component\\Utility\\Html',
      'escape',
    ], user_role_names(TRUE));
    unset($roles['authenticated']);
    $roles = ['none' => 'Nessuno (solo Autenticato)'] + $roles;

    $form['user_settings']['user_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Role to assign'),
      '#options' => $roles,
      '#default_value' => $config->get('citizen.user_role') ? $config->get('citizen.user_role') : 'none',
      '#description' => $this->t('Define the role assigned to users after registration.'),
    ];

    // Roles to exclude
    unset($roles['none']);
    $form['user_settings']['roles_to_exclude'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles to check'),
      '#options' => $roles,
      '#default_value' => $config->get('citizen.roles_to_exclude') ?? ['administrator'],
      '#description' => $this->t('Roles to check at login. If the user exists and has one of these roles, then the default role will not be assigned.'),
    ];

    $form['field_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Field Mapping'),
      '#open' => TRUE,
      '#description' => $this->t('Configure how WSO2 user fields map to Drupal user fields.'),
    ];

    $mapping = $config->get('citizen.mapping') ?? [];

    $form['field_mapping']['mapping']['user_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User ID field'),
      '#default_value' => $mapping['user_id'] ?? 'sub',
      '#description' => $this->t('The WSO2 field to use as the unique identifier for the user (default: sub).'),
    ];

    $form['field_mapping']['mapping']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username field'),
      '#default_value' => $mapping['username'] ?? 'email',
      '#description' => $this->t('The WSO2 field to use as the Drupal username (default: email).'),
    ];

    $form['field_mapping']['mapping']['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email field'),
      '#default_value' => $mapping['email'] ?? 'email',
      '#description' => $this->t('The WSO2 field to use as the Drupal email (default: email).'),
    ];

    if (CheckUserFieldExist::exist('field_user_firstname')) {
      $form['field_mapping']['mapping']['first_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('First name field'),
        '#default_value' => $mapping['first_name'] ?? 'given_name',
        '#description' => $this->t('The WSO2 field to use as the Drupal first name (default: given_name).'),
      ];
    }
    else {
      $form['field_mapping']['no_first_name'] = [
        '#type' => 'item',
        '#title' => $this->t('First name field'),
        '#description' => $this->t('The field field_user_firstname does not exist in the user profile. This field mapping will not be available until the field is created.'),
        '#markup' => '<div class="messages messages--warning">' . $this->t('Missing field: field_user_firstname') . '</div>',
      ];
      $form['field_mapping']['mapping']['first_name'] = [
        '#type' => 'hidden',
        '#value' => $mapping['first_name'] ?? 'given_name',
      ];
    }

    if (CheckUserFieldExist::exist('field_user_lastname')) {
      $form['field_mapping']['mapping']['last_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Last name field'),
        '#default_value' => $mapping['last_name'] ?? 'family_name',
        '#description' => $this->t('The WSO2 field to use as the Drupal last name (default: family_name).'),
      ];
    }
    else {
      $form['field_mapping']['no_last_name'] = [
        '#type' => 'item',
        '#title' => $this->t('Last name field'),
        '#description' => $this->t('The field field_user_lastname does not exist in the user profile. This field mapping will not be available until the field is created.'),
        '#markup' => '<div class="messages messages--warning">' . $this->t('Missing field: field_user_lastname') . '</div>',
      ];
      $form['field_mapping']['mapping']['last_name'] = [
        '#type' => 'hidden',
        '#value' => $mapping['last_name'] ?? 'family_name',
      ];
    }

    if (CheckUserFieldExist::exist('field_user_fiscalcode')) {
      $form['field_mapping']['mapping']['fiscal_code'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Fiscal code field'),
        '#default_value' => $mapping['fiscal_code'] ?? 'cn',
        '#description' => $this->t('The WSO2 field to use as the Drupal fiscal code (default: cn).'),
      ];
    }
    else {
      $form['field_mapping']['no_fiscal_code'] = [
        '#type' => 'item',
        '#title' => $this->t('Fiscal code field'),
        '#description' => $this->t('The field field_user_fiscalcode does not exist in the user profile. This field mapping will not be available until the field is created.'),
        '#markup' => '<div class="messages messages--warning">' . $this->t('Missing field: field_user_fiscalcode') . '</div>',
      ];
      $form['field_mapping']['mapping']['fiscal_code'] = [
        '#type' => 'hidden',
        '#value' => $mapping['fiscal_code'] ?? 'cn',
      ];
    }

    if (CheckUserFieldExist::exist('field_user_mobilephone')) {
      $form['field_mapping']['mapping']['mobile_phone'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Mobile phone field'),
        '#default_value' => $mapping['mobile_phone'] ?? '',
        '#description' => $this->t('The WSO2 field to use as the Drupal mobile phone.'),
      ];
    }
    else {
      $form['field_mapping']['no_mobile_phone'] = [
        '#type' => 'item',
        '#title' => $this->t('Mobile phone field'),
        '#description' => $this->t('The field field_user_mobilephone does not exist in the user profile. This field mapping will not be available until the field is created.'),
        '#markup' => '<div class="messages messages--warning">' . $this->t('Missing field: field_user_mobilephone') . '</div>',
      ];
      $form['field_mapping']['mapping']['mobile_phone'] = [
        '#type' => 'hidden',
        '#value' => $mapping['mobile_phone'] ?? '',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate the redirect URI.
    $redirect_uri = $form_state->getValue(['oauth2', 'redirect_uri']);
    if (!empty($redirect_uri) && !filter_var($redirect_uri, FILTER_VALIDATE_URL)) {
      $form_state->setError($form['oauth2']['redirect_uri'], $this->t('The redirect URI must be a valid URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('wso2_auth.settings');

    // Debug dei valori
    // if ($config->get('debug')) {
    //   $this->logger('wso2_auth')->debug('WSO2 Citizen Settings form values: @values', [
    //     '@values' => print_r($form_state->getValues(), TRUE),
    //   ]);
    // }

    $values = $form_state->getValues();

    // Save the OAuth2 settings
    $config->set('citizen.client_id', $values['client_id']);

    // Only set client_secret if a value was provided
    $client_secret = $values['client_secret'];
    if (!empty($client_secret)) {
      $config->set('citizen.client_secret', $client_secret);
    }

    $config->set('citizen.scope', $values['scope']);

    // Save API Manager settings
    $config->set('citizen.api_manager_environment', $values['api_manager_environment']);
    $api_manager_client_id = $values['api_manager_client_id'];
    if (!empty($api_manager_client_id)) {
      $config->set('citizen.api_manager_client_id', $api_manager_client_id);
    }

    $api_manager_client_secret = $values['api_manager_client_secret'];
    if (!empty($api_manager_client_secret)) {
      $config->set('citizen.api_manager_client_secret', $api_manager_client_secret);
    }

    // Save the user settings
    $config->set('citizen.auto_register', (bool) $values['auto_register']);
    $config->set('citizen.user_role', $values['user_role']);
    $config->set('citizen.roles_to_exclude', $values['roles_to_exclude']);

    // Save the field mappings
    $config->set('citizen.mapping', [
      'user_id' => $values['user_id'],
      'username' => $values['username'],
      'email' => $values['email'],
      'first_name' => $values['first_name'],
      'last_name' => $values['last_name'],
      'fiscal_code' => $values['fiscal_code'],
      'mobile_phone' => $values['mobile_phone'],
    ]);

    $config->save();

    // Pulisce la cache per assicurarsi che le modifiche vengano applicate immediatamente
    \Drupal::service('cache.config')->deleteAll();

    parent::submitForm($form, $form_state);
  }
}
