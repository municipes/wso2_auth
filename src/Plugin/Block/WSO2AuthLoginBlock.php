<?php

namespace Drupal\wso2_auth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wso2_auth\WSO2AuthService;

/**
 * Provides a 'WSO2 Authentication Login' Block.
 *
 * @Block(
 *   id = "wso2_auth_login_block",
 *   admin_label = @Translation("WSO2 Authentication Login"),
 *   category = @Translation("User")
 * )
 */
class WSO2AuthLoginBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Constructs a new WSO2AuthLoginBlock.
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    WSO2AuthService $wso2_auth,
    AccountInterface $current_user
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->wso2Auth = $wso2_auth;
    $this->currentUser = $current_user;
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
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // If user is already logged in or WSO2 Auth is not configured, don't show the block.
    if (!$this->wso2Auth->isConfigured() || !$this->currentUser->isAnonymous()) {
      return [];
    }

    // Get the current path to use as the destination after login.
    $destination = \Drupal::request()->getRequestUri();

    // Build the login URL with destination.
    $url = Url::fromRoute('wso2_auth.authorize', ['destination' => $destination]);

    // Build the block content.
    $build = [
      '#theme' => 'wso2_auth_login_button',
      '#url' => $url->toString(),
      '#label' => $this->t('Log in with WSO2'),
      '#attached' => [
        'library' => ['wso2_auth/wso2_auth.login'],
      ],
    ];

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
