<?php

namespace Drupal\wso2_auth_check;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\wso2_auth\WSO2AuthService;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Helper class for WSO2 session checking.
 */
class WSO2SessionCheckHelper {

  use StringTranslationTrait;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new WSO2SessionCheckHelper.
   *
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    WSO2AuthService $wso2_auth,
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $logger
  ) {
    $this->wso2Auth = $wso2_auth;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Checks if automatic session checking is enabled.
   *
   * @return bool
   *   TRUE if automatic session checking is enabled.
   */
  public function isEnabled() {
    $config = $this->configFactory->get('wso2_auth_check.settings');
    $wso2_config = $this->configFactory->get('wso2_auth.settings');

    return $config->get('enabled') &&
           $wso2_config->get('enabled') &&
           $this->wso2Auth->isConfigured();
  }

  /**
   * Checks if a path should be excluded from session checking.
   *
   * @param string $path
   *   The path to check.
   *
   * @return bool
   *   TRUE if the path should be excluded.
   */
  public function isPathExcluded($path) {
    // Get the excluded paths from the helper function
    $excluded_paths = wso2_auth_check_get_excluded_paths();

    // Check if path matches any excluded path.
    foreach ($excluded_paths as $excluded_path) {
      if (!empty($excluded_path) && strpos($path, trim($excluded_path)) === 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if we should perform a session check based on timing constraints.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session.
   *
   * @return bool
   *   TRUE if we should check the session.
   */
  public function shouldCheckSession(SessionInterface $session) {
    return wso2_auth_check_should_check_session($session);
  }

  /**
   * Marks that a session check has occurred.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session.
   */
  public function markSessionChecked(SessionInterface $session) {
    $session->set('wso2_auth_check.checked', TRUE);
    $session->set('wso2_auth_check.last_check', time());
  }

  /**
   * Marks that a redirect is in progress.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session.
   * @param bool $in_progress
   *   Whether a redirect is in progress.
   */
  public function markRedirectInProgress(SessionInterface $session, $in_progress = TRUE) {
    if ($in_progress) {
      $session->set('wso2_auth_check.redirect_in_progress', TRUE);
    }
    else {
      $session->remove('wso2_auth_check.redirect_in_progress');
    }
  }

  /**
   * Resets all session check flags.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session.
   */
  public function resetSessionFlags(SessionInterface $session) {
    $session->remove('wso2_auth_check.checked');
    $session->remove('wso2_auth_check.last_check');
    $session->remove('wso2_auth_check.redirect_in_progress');
  }

  /**
   * Checks if the user has an active WSO2 session using prompt=none parameter.
   *
   * This method creates a silent authentication request to WSO2 with prompt=none
   * and checks the response. If the response contains a 'code' parameter, the user
   * is already authenticated. If it contains an 'error' parameter with value
   * 'login_required', the user is not authenticated.
   *
   * @param string $return_url
   *   The URL to return to after the check.
   *
   * @return bool|null
   *   TRUE if the user has an active session, FALSE if not, NULL if undetermined.
   */
  public function checkWSO2SessionSilently($return_url) {
    // Get the WSO2 configuration.
    $wso2_config = $this->configFactory->get('wso2_auth.settings');

    // Get service to use helper methods from WSO2AuthService.
    $wso2_auth = \Drupal::service('wso2_auth.authentication');

    // Generate a state parameter.
    $state = bin2hex(random_bytes(16));

    // Build the authorization URL with prompt=none.
    $auth_url = $wso2_auth->getAuthorizationUrl($return_url);
    $auth_url .= '&prompt=none';

    // Log the request if debug is enabled.
    if ($wso2_config->get('debug')) {
      $this->logger->debug('Sending silent authentication check to @url', [
        '@url' => $auth_url,
      ]);
    }

    // Make the request to the authorization endpoint.
    try {
      $client = \Drupal::httpClient();
      $options = [
        'allow_redirects' => FALSE,
        'timeout' => 5, // Short timeout since this is a passive check.
      ];

      // Skip SSL verification if configured.
      if ($wso2_config->get('skip_ssl_verification')) {
        $options['verify'] = FALSE;
      }

      $response = $client->get($auth_url, $options);

      // Get the Location header for the redirect.
      $location = $response->getHeader('Location');

      if (empty($location)) {
        // No redirect means the user is not authenticated.
        return FALSE;
      }

      $location = reset($location);

      // If the location contains a 'code' parameter, the user is authenticated.
      if (strpos($location, 'code=') !== FALSE) {
        return TRUE;
      }

      // If the location contains 'error=login_required', the user is not authenticated.
      if (strpos($location, 'error=login_required') !== FALSE) {
        return FALSE;
      }

      // If we can't determine the state, return NULL.
      return NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Error during silent authentication check: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }
}
