<?php

namespace Drupal\wso2_auth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wso2_auth\WSO2AuthService;
use Drupal\wso2_auth\Helper\WSO2EnvironmentHelper;

/**
 * Provides a 'WSO2 Authentication Status' Block.
 *
 * @Block(
 *   id = "wso2_auth_status_block",
 *   admin_label = @Translation("WSO2 Authentication Status"),
 *   category = @Translation("User")
 * )
 */
class WSO2AuthStatusBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService
   */
  protected $wso2Auth;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The environment helper.
   *
   * @var \Drupal\wso2_auth\Helper\WSO2EnvironmentHelper
   */
  protected $environmentHelper;

  /**
   * Constructs a new WSO2AuthStatusBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\wso2_auth\Helper\WSO2EnvironmentHelper $environment_helper
   *   The environment helper.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    WSO2AuthService $wso2_auth,
    AccountInterface $current_user,
    WSO2EnvironmentHelper $environment_helper
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->wso2Auth = $wso2_auth;
    $this->currentUser = $current_user;
    $this->environmentHelper = $environment_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('wso2_auth.authentication'),
      $container->get('current_user'),
      $container->get('wso2_auth.environment_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // If WSO2 Auth is not configured, don't show anything.
    if (!$this->wso2Auth->isConfigured()) {
      return [
        '#markup' => $this->t('WSO2 Authentication is not configured.'),
        '#access' => \Drupal::currentUser()->hasPermission('administer wso2 authentication'),
      ];
    }

    // Build the block content.
    $build = [
      '#theme' => 'wso2_auth_status_block',
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    // Add information about the current environment.
    $build['#environment'] = $this->environmentHelper->isStaging() ? 'staging' : 'production';

    // Add information about the user's authentication status.
    $build['#authenticated'] = $this->wso2Auth->isUserAuthenticated();

    // Add login/logout links.
    if ($this->currentUser->isAnonymous()) {
      $build['#login_url'] = Url::fromRoute('wso2_auth.authorize')->toString();
    }
    else {
      $build['#logout_url'] = Url::fromRoute('user.logout')->toString();
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Block should not be cached as it depends on user's login state.
    return 0;
  }

}
