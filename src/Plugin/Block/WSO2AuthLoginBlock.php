<?php

namespace Drupal\wso2_auth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'WSO2 Auth Login' Block.
 *
 * @Block(
 *   id = "wso2_auth_login_block",
 *   admin_label = @Translation("WSO2 Auth Login"),
 *   category = @Translation("WSO2 Authentication"),
 * )
 */
class WSO2AuthLoginBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new WSO2AuthLoginBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'label_display' => FALSE,
      'display_citizen' => TRUE,
      'display_operator' => FALSE,
      'citizen_label' => $this->t('Login with SPID/CIE'),
      'operator_label' => $this->t('Operator Login'),
      'show_logo' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['display_citizen'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display citizen login button'),
      '#default_value' => $config['display_citizen'],
    ];

    $form['citizen_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Citizen login button label'),
      '#default_value' => $config['citizen_label'],
      '#states' => [
        'visible' => [
          ':input[name="settings[display_citizen]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['display_operator'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display operator login button'),
      '#default_value' => $config['display_operator'],
    ];

    $form['operator_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Operator login button label'),
      '#default_value' => $config['operator_label'],
      '#states' => [
        'visible' => [
          ':input[name="settings[display_operator]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['show_logo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show SPID/CIE logo'),
      '#default_value' => $config['show_logo'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['display_citizen'] = $form_state->getValue('display_citizen');
    $this->configuration['citizen_label'] = $form_state->getValue('citizen_label');
    $this->configuration['display_operator'] = $form_state->getValue('display_operator');
    $this->configuration['operator_label'] = $form_state->getValue('operator_label');
    $this->configuration['show_logo'] = $form_state->getValue('show_logo');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // If user is already logged in, return empty array.
    if (!\Drupal::currentUser()->isAnonymous()) {
      return [];
    }

    $config = $this->getConfiguration();
    $module_config = $this->configFactory->get('wso2_auth.settings');

    // Check if WSO2 Auth is enabled.
    if (!$module_config->get('enabled')) {
      return [];
    }

    $build = [
      '#theme' => 'wso2_auth_login_block',
      '#attached' => [
        'library' => ['wso2_auth/wso2_auth.login'],
      ],
      '#show_logo' => $config['show_logo'] && $module_config->get('picture_enabled'),
    ];

    // Current route as destination after login.
    $current_route = $this->requestStack->getCurrentRequest()->getRequestUri();

    // Build citizen login button if enabled.
    if ($config['display_citizen']) {
      $citizen_url = Url::fromRoute('wso2_auth.authorize', ['type' => 'citizen'], [
        // 'query' => ['destination' => $current_route],
      ])->toString();

      $build['#citizen_login'] = [
        'url' => $citizen_url,
        'label' => $config['citizen_label'],
      ];
    }

    // Build operator login button if enabled and configured.
    if ($config['display_operator'] && $module_config->get('operator.enabled')) {
      $operator_url = Url::fromRoute('wso2_auth.authorize', ['type' => 'operator'], [
        'query' => ['destination' => $current_route],
      ])->toString();

      $build['#operator_login'] = [
        'url' => $operator_url,
        'label' => $config['operator_label'],
      ];
    }

    // Return empty array if no buttons are displayed.
    if (empty($build['#citizen_login']) && empty($build['#operator_login'])) {
      return [];
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0; // No caching.
  }

}
