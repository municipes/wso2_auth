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

    $form['message'] = [
      '#markup' => '<div class="messages messages--info">' . $this->t('Operator authentication is not yet implemented. This form will be available in a future release.') . '</div>',
    ];

    $form['oauth2'] = [
      '#type' => 'details',
      '#title' => $this->t('OAuth2 Information'),
      '#open' => TRUE,
      '#disabled' => TRUE,
    ];

    $form['oauth2']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 Client ID'),
      '#default_value' => $config->get('operator_client_id') ?? '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The OAuth2 client ID for operators.'),
      '#disabled' => TRUE,
    ];

    $form['oauth2']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('OAuth2 Client Secret'),
      '#default_value' => '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('The OAuth2 client secret for operators.'),
      '#disabled' => TRUE,
    ];

    $form['params'] = [
      '#type' => 'details',
      '#title' => $this->t('Parameters'),
      '#open' => TRUE,
      '#disabled' => TRUE,
    ];

    $form['params']['ente'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity code'),
      '#default_value' => $config->get('operator_ente') ?? '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('Entity code.'),
      '#disabled' => TRUE,
    ];

    $form['params']['app'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application code'),
      '#default_value' => $config->get('operator_app') ?? '',
      '#size' => 25,
      '#maxlength' => 64,
      '#description' => $this->t('Application code.'),
      '#disabled' => TRUE,
    ];

    $form['attributes'] = [
      '#type' => 'details',
      '#title' => $this->t('Role Mapping'),
      '#open' => TRUE,
      '#disabled' => TRUE,
    ];

    $form['attributes']['role_population'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Automatic role population from attributes'),
      '#default_value' => $config->get('operator_role_population') ?? '',
      '#description' => $this->t('A pipe separated list of rules. Each rule consists of a Drupal role id, an attribute name, an operation and a value to match.'),
      '#disabled' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Since this form is disabled, we don't need to save anything.
    // But we'll keep the structure for future implementation.

    $this->messenger()->addWarning($this->t('Operator authentication is not yet implemented.'));

    parent::submitForm($form, $form_state);
  }
}
