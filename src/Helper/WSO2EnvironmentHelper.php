<?php

namespace Drupal\wso2_auth\Helper;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Helper class to manage WSO2 environments (production/staging).
 */
class WSO2EnvironmentHelper {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Environment configuration (production).
   *
   * @var array
   */
  protected $productionConfig = [
    'auth_server_url' => 'https://id.055055.it:9443/oauth2',
    'auth_endpoint' => '/authorize',
    'token_endpoint' => '/token',
    'userinfo_endpoint' => '/userinfo',
    'logout_url' => 'https://id.055055.it:9443/oidc/logout',
  ];

  /**
   * Environment configuration (staging).
   *
   * @var array
   */
  protected $stagingConfig = [
    'auth_server_url' => 'https://id-staging.055055.it:9443/oauth2',
    'auth_endpoint' => '/authorize',
    'token_endpoint' => '/token',
    'userinfo_endpoint' => '/userinfo',
    'logout_url' => 'https://id-staging.055055.it:9443/oidc/logout',
  ];

  /**
   * Constructs a new WSO2EnvironmentHelper object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get the correct environment configuration.
   *
   * @return array
   *   The environment configuration.
   */
  public function getEnvironmentConfig() {
    $config = $this->configFactory->get('wso2_auth.settings');

    // Check if staging environment is enabled
    if ($config->get('stage')) {
      return $this->stagingConfig;
    }

    return $this->productionConfig;
  }

  /**
   * Get the authentication server URL.
   *
   * @return string
   *   The authentication server URL.
   */
  public function getAuthServerUrl() {
    $config = $this->configFactory->get('wso2_auth.settings');

    // If a specific URL is configured, use that one
    $configured_url = $config->get('auth_server_url');
    if (!empty($configured_url)) {
      return $configured_url;
    }

    // Otherwise, use the environment-specific URL
    $env_config = $this->getEnvironmentConfig();
    return $env_config['auth_server_url'];
  }

  /**
   * Get the authentication endpoint.
   *
   * @return string
   *   The authentication endpoint.
   */
  public function getAuthEndpoint() {
    $env_config = $this->getEnvironmentConfig();
    return $env_config['auth_endpoint'];
  }

  /**
   * Get the token endpoint.
   *
   * @return string
   *   The token endpoint.
   */
  public function getTokenEndpoint() {
    $env_config = $this->getEnvironmentConfig();
    return $env_config['token_endpoint'];
  }

  /**
   * Get the userinfo endpoint.
   *
   * @return string
   *   The userinfo endpoint.
   */
  public function getUserinfoEndpoint() {
    $env_config = $this->getEnvironmentConfig();
    return $env_config['userinfo_endpoint'];
  }

  /**
   * Get the logout URL.
   *
   * @return string
   *   The logout URL.
   */
  public function getLogoutUrl() {
    $env_config = $this->getEnvironmentConfig();
    return $env_config['logout_url'];
  }

  /**
   * Check if the current environment is staging.
   *
   * @return bool
   *   TRUE if the current environment is staging, FALSE otherwise.
   */
  public function isStaging() {
    $config = $this->configFactory->get('wso2_auth.settings');
    return (bool) $config->get('stage');
  }

}
