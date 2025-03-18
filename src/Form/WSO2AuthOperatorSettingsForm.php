<?php

namespace Drupal\wso2_auth\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wso2_auth\WSO2AuthService;

/**
 * Operator settings form for WSO2 authentication.
 */
class WSO2AuthOperatorSettingsForm extends ConfigFormBase {

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
    return 'wso2_auth_operator_settings_form';
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

    $form['info'] = [
      '#markup' => '<div class="messages messages--info">' . $this->t('Configure authentication settings for operators (internal users) using WSO2 Identity Server.') . '</div>',
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable operator authentication'),
      '#default_value' => $config->get('operator.enabled') ?? FALSE,
      '#description' => $this->t('Enable or disable authentication for operators.'),
    ];

    $form['oauth2'] = [
      '#type' => 'details',
      '#title' => $this->t('OAuth2 Information'),
      '#open' => TRUE,
    ];

    $form['oauth2']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 Client ID'),
      '#default_value' => $config->get('operator.client_id') ?? '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The OAuth2 client ID for operators.'),
    ];

    $form['oauth2']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('OAuth2 Client Secret'),
      '#default_value' => '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The OAuth2 client secret for operators. Leave blank to keep the existing value.'),
    ];

    $form['oauth2']['scope'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 Scope'),
      '#default_value' => $config->get('operator.scope') ?? 'openid',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The OAuth2 scope for operators.'),
    ];

    $form['oauth2']['ag_entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID (agEntityId)'),
      '#default_value' => $config->get('operator.ag_entity_id') ?? $config->get('ag_entity_id') ?? '',
      '#description' => $this->t('The entity ID to use for operator authentication.'),
    ];

    $form['params'] = [
      '#type' => 'details',
      '#title' => $this->t('Parameters'),
      '#open' => TRUE,
    ];

    $form['params']['ente'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity code'),
      '#default_value' => $config->get('operator.ente') ?? '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('Entity code for operator authentication.'),
    ];

    $form['params']['app'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application code'),
      '#default_value' => $config->get('operator.app') ?? '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('Application code for operator authentication.'),
    ];

    $form['login'] = [
      '#type' => 'details',
      '#title' => $this->t('Login information (for JWT token)'),
      '#open' => TRUE,
    ];

    $form['login']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $config->get('operator.username') ?? '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('JWT authentication username.'),
    ];

    $form['login']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#default_value' => '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('JWT authentication password. Leave blank to keep the existing value.'),
    ];

    $form['attributes'] = [
      '#type' => 'details',
      '#title' => $this->t('Role Mapping'),
      '#open' => TRUE,
    ];

    $form['attributes']['role_population'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Automatic role population from attributes'),
      '#default_value' => $config->get('operator.role_population') ?? '',
      '#description' => $this->t('A pipe separated list of rules. Each rule consists of a Drupal role id, a function name, separated by colon. <i>Example: editor:Redattore|admin:ResponsabileSito</i>'),
    ];

    $form['attributes']['auto_register'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically register new operators'),
      '#default_value' => $config->get('operator.auto_register') ?? TRUE,
      '#description' => $this->t('Automatically register operators when they authenticate for the first time.'),
    ];

    $form['attributes']['user_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Default role'),
      '#description' => $this->t('The default role to assign to new operator accounts.'),
      '#options' => $this->getRoleOptions(),
      '#default_value' => $config->get('operator.user_role') ?? 'authenticated',
    ];

    $form['service'] = [
      '#type' => 'details',
      '#title' => $this->t('Privileges Service'),
      '#open' => TRUE,
    ];

    $form['service']['privileges_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Privileges Service URL'),
      '#default_value' => $config->get('operator.privileges_url') ?? 'http://baseprivilegioperatore.cst:8080/baseprivilegioperatore/api',
      '#description' => $this->t('The URL of the privileges service for operators.'),
    ];

    $form['service']['privileges_stage_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Privileges Service URL (Staging)'),
      '#default_value' => $config->get('operator.privileges_stage_url') ?? 'http://baseprivilegioperatori-staging.cst:8080/baseprivilegioperatore/api',
      '#description' => $this->t('The URL of the staging privileges service for operators.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Get role options for the select field.
   *
   * @return array
   *   Array of role options.
   */
  protected function getRoleOptions() {
    $options = ['none' => $this->t('- None -')];

    // Get all roles except for anonymous.
    $roles = user_roles(TRUE);
    foreach ($roles as $role_id => $role) {
      $options[$role_id] = $role->label();
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('wso2_auth.settings');

    // Keep the existing client secret if the field is blank.
    if (empty($values['client_secret'])) {
      $values['client_secret'] = $config->get('operator.client_secret');
    }

    // Keep the existing password if the field is blank.
    if (empty($values['password'])) {
      $values['password'] = $config->get('operator.password');
    }

    $config
      ->set('operator.enabled', $values['enabled'])
      ->set('operator.client_id', $values['client_id'])
      ->set('operator.client_secret', $values['client_secret'])
      ->set('operator.scope', $values['scope'])
      ->set('operator.ag_entity_id', $values['ag_entity_id'])
      ->set('operator.ente', $values['ente'])
      ->set('operator.app', $values['app'])
      ->set('operator.username', $values['username'])
      ->set('operator.password', $values['password'])
      ->set('operator.role_population', $values['role_population'])
      ->set('operator.auto_register', $values['auto_register'])
      ->set('operator.user_role', $values['user_role'])
      ->set('operator.privileges_url', $values['privileges_url'])
      ->set('operator.privileges_stage_url', $values['privileges_stage_url'])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
