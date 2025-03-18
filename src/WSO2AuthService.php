<?php

namespace Drupal\wso2_auth;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\State\StateInterface;
use Drupal\externalauth\ExternalAuthInterface;
use Drupal\wso2_auth\Helper\WSO2EnvironmentHelper;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Service for handling WSO2 authentication.
 */
class WSO2AuthService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The external auth service.
   *
   * @var \Drupal\externalauth\ExternalAuthInterface
   */
  protected $externalAuth;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The session.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * The environment helper.
   *
   * @var \Drupal\wso2_auth\Helper\WSO2EnvironmentHelper
   */
  protected $environmentHelper;

  /**
   * Constructor for the WSO2 authentication service.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\externalauth\ExternalAuthInterface $external_auth
   *   The external auth service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session.
   * @param \Drupal\wso2_auth\Helper\WSO2EnvironmentHelper $environment_helper
   *   The environment helper.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    LoggerChannelInterface $logger,
    ExternalAuthInterface $external_auth,
    AccountInterface $current_user,
    MessengerInterface $messenger,
    RequestStack $request_stack,
    SessionInterface $session,
    WSO2EnvironmentHelper $environment_helper = NULL
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->logger = $logger;
    $this->externalAuth = $external_auth;
    $this->currentUser = $current_user;
    $this->messenger = $messenger;
    $this->requestStack = $request_stack;
    $this->session = $session;
    $this->environmentHelper = $environment_helper ?: new WSO2EnvironmentHelper($config_factory);
  }

  /**
   * Check if WSO2 authentication is configured and enabled.
   *
   * @return bool
   *   TRUE if WSO2 authentication is configured and enabled.
   */
  public function isConfigured() {
    $config = $this->configFactory->get('wso2_auth.settings');
    return $config->get('enabled') &&
      !empty($config->get('citizen.client_id')) &&
      !empty($config->get('citizen.client_secret'));
  }

  /**
   * Generate a random state parameter for OAuth2 requests.
   *
   * @return string
   *   A random state string.
   */
  public function generateState() {
    $state = bin2hex(random_bytes(16));
    $this->session->set('wso2_auth_state', $state);
    return $state;
  }

  /**
   * Verify the state parameter returned from the OAuth2 server.
   *
   * @param string $returned_state
   *   The state parameter returned from the server.
   *
   * @return bool
   *   TRUE if the state is valid.
   */
  public function verifyState($returned_state) {
    $original_state = $this->session->get('wso2_auth_state');
    $this->session->remove('wso2_auth_state');

    if (empty($original_state) || empty($returned_state) || $original_state !== $returned_state) {
      $this->logger->error('WSO2 Auth: Invalid state parameter. Expected @expected, got @returned', [
        '@expected' => $original_state ?? 'empty',
        '@returned' => $returned_state ?? 'empty',
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Get the redirect URI for OAuth2 callbacks.
   *
   * @return string
   *   The absolute URL for the redirect URI.
   */
  public function getRedirectUri() {
    return Url::fromRoute('wso2_auth.callback')
      ->setAbsolute()
      ->toString();
  }

  /**
   * Get the authorization URL.
   *
   * @param string $destination
   *   The internal path to redirect to after authentication.
   *
   * @return string
   *   The authorization URL.
   */
  public function getAuthorizationUrl($destination = '') {
    $config = $this->configFactory->get('wso2_auth.settings');

    // Store the destination in the session.
    if (!empty($destination)) {
      $this->session->set('wso2_auth_destination', $destination);

      // Debug log the destination being stored
      if ($config->get('debug')) {
        $this->logger->debug('WSO2 Auth: Storing destination in session: @destination', [
          '@destination' => $destination,
        ]);
      }
    }

    // Generate the state parameter.
    $state = $this->generateState();

    // Get the authentication server URL from the environment helper
    $auth_server_url = $this->environmentHelper->getAuthServerUrl();

    // Get the auth endpoint from the environment helper and append it to the base URL
    $auth_endpoint = $this->environmentHelper->getAuthEndpoint();
    $full_auth_url = $auth_server_url . $auth_endpoint;

    // Get the redirect URI
    $redirect_uri = $this->getRedirectUri();

    // Build the authorization URL.
    $params = [
      'agEntityId' => $config->get('ag_entity_id'),
      'client_id' => $config->get('citizen.client_id'),
      'client_secret' => $config->get('citizen.client_secret'),
      'redirect_uri' => $redirect_uri,
      'response_type' => 'code',
      'scope' => $config->get('citizen.scope'),
      'state' => $state,
    ];

    // Allow other modules to alter the authorization URL.
    $built_url = $full_auth_url . '?' . http_build_query($params);
    \Drupal::moduleHandler()->alter('wso2_auth_authorization_url', $built_url, $params);

    return $built_url;
  }

  /**
   * Exchange authorization code for access token.
   *
   * @param string $code
   *   The authorization code.
   *
   * @return array|bool
   *   The token data or FALSE on failure.
   */
  public function getTokens($code) {
    $config = $this->configFactory->get('wso2_auth.settings');

    // Get the redirect URI
    $redirect_uri = $this->getRedirectUri();

    // Prepare the token request parameters
    $params = [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $redirect_uri,
      'client_id' => $config->get('citizen.client_id'),
      'client_secret' => $config->get('citizen.client_secret'),
    ];

    // Allow other modules to alter the token request
    \Drupal::moduleHandler()->alter('wso2_auth_token_request', $params, $code);

    try {
      $options = [
        'form_params' => $params,
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
      ];

      // Skip SSL verification if configured
      if ($config->get('skip_ssl_verification')) {
        $options['verify'] = FALSE;
      }

      // Get the authentication server URL from the environment helper
      $auth_server_url = $this->environmentHelper->getAuthServerUrl();
      $token_endpoint = $this->environmentHelper->getTokenEndpoint();

      $response = $this->httpClient->post($auth_server_url . $token_endpoint, $options);

      $data = json_decode((string) $response->getBody(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('WSO2 Auth: Error decoding token response: @error', [
          '@error' => json_last_error_msg(),
        ]);
        return FALSE;
      }

      return $data;
    }
    catch (RequestException $e) {
      $this->logger->error('WSO2 Auth: Error requesting access token: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get user info from WSO2.
   *
   * @param string $access_token
   *   The access token.
   *
   * @return array|bool
   *   The user data or FALSE on failure.
   */
  public function getUserInfo($access_token) {
    $config = $this->configFactory->get('wso2_auth.settings');

    // Prepare the request options
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $access_token,
      ],
    ];

    // Skip SSL verification if configured
    if ($config->get('skip_ssl_verification')) {
      $options['verify'] = FALSE;
    }

    // Allow other modules to alter the userinfo request
    \Drupal::moduleHandler()->alter('wso2_auth_userinfo_request', $options, $access_token);

    try {
      // Get the authentication server URL from the environment helper
      $auth_server_url = $this->environmentHelper->getAuthServerUrl();
      $userinfo_endpoint = $this->environmentHelper->getUserinfoEndpoint();

      $response = $this->httpClient->get($auth_server_url . $userinfo_endpoint, $options);

      $data = json_decode((string) $response->getBody(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('WSO2 Auth: Error decoding user info response: @error', [
          '@error' => json_last_error_msg(),
        ]);
        return FALSE;
      }

      // Debug log the user data if debug is enabled
      if ($config->get('debug')) {
        $this->logger->debug('WSO2 Auth: User data received: @data', [
          '@data' => print_r($data, TRUE),
        ]);
      }

      // Allow other modules to alter the user data before authentication
      \Drupal::moduleHandler()->alter('wso2_auth_userinfo', $data);

      return $data;
    }
    catch (RequestException $e) {
      $this->logger->error('WSO2 Auth: Error requesting user info: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Authenticate a user with WSO2.
   *
   * @param array $user_data
   *   The user data from WSO2.
   *
   * @return \Drupal\user\UserInterface|bool
   *   The authenticated user or FALSE on failure.
   */
  public function authenticateUser(array $user_data) {
    $config = $this->configFactory->get('wso2_auth.settings');
    $mapping = $config->get('citizen.mapping');

    // Get the unique identifier.
    $id_key = !empty($mapping['user_id']) ? $mapping['user_id'] : 'sub';
    if (empty($user_data[$id_key])) {
      $this->logger->error('WSO2 Auth: User ID field @field not found in user data', [
        '@field' => $id_key,
      ]);
      return FALSE;
    }

    $authname = $user_data[$id_key];

    // Try to load the user by external ID.
    $account = $this->externalAuth->load($authname, 'wso2_auth');

    // If account is not found by external authentication, we need to check if the user
    // already exists in Drupal by checking the email/username
    if (!$account) {
      // Check for existing email or username to avoid duplicate registration errors
      $email_key = !empty($mapping['email']) ? $mapping['email'] : 'email';
      $username_key = !empty($mapping['username']) ? $mapping['username'] : 'email';

      $email = !empty($user_data[$email_key]) ? $user_data[$email_key] : '';
      $username = !empty($user_data[$username_key]) ? $user_data[$username_key] : '';

      if (!empty($email)) {
        // Try to find an existing user with the same email
        $existing_users = \Drupal::entityTypeManager()
          ->getStorage('user')
          ->loadByProperties(['mail' => $email]);

        if (!empty($existing_users)) {
          // Take the first user that matches the email
          $existing_user = reset($existing_users);

          // Link this user to the WSO2 authname
          try {
            $this->externalAuth->linkExistingAccount($authname, 'wso2_auth', $existing_user);
            $this->logger->notice('WSO2 Auth: Linked existing user @user with WSO2 ID @id', [
              '@user' => $existing_user->getAccountName(),
              '@id' => $authname,
            ]);

            // Update the account variable to use this existing account
            $account = $existing_user;
          }
          catch (\Exception $e) {
            $this->logger->error('WSO2 Auth: Error linking existing account: @error', [
              '@error' => $e->getMessage(),
            ]);
          }
        }
        elseif (!empty($username)) {
          // If no user with this email exists, try to find by username
          $existing_users = \Drupal::entityTypeManager()
            ->getStorage('user')
            ->loadByProperties(['name' => $username]);

          if (!empty($existing_users)) {
            // Take the first user that matches the username
            $existing_user = reset($existing_users);

            // Link this user to the WSO2 authname
            try {
              $this->externalAuth->linkExistingAccount($authname, 'wso2_auth', $existing_user);
              $this->logger->notice('WSO2 Auth: Linked existing user @user with WSO2 ID @id', [
                '@user' => $existing_user->getAccountName(),
                '@id' => $authname,
              ]);

              // Update the account variable to use this existing account
              $account = $existing_user;
            }
            catch (\Exception $e) {
              $this->logger->error('WSO2 Auth: Error linking existing account: @error', [
                '@error' => $e->getMessage(),
              ]);
            }
          }
        }
      }
    }

    // If still no account is found and auto-registration is enabled, register a new user.
    if (!$account && $config->get('citizen.auto_register')) {
      // Prepare user data.
      $user_info = [];

      // Map email.
      $email_key = !empty($mapping['email']) ? $mapping['email'] : 'email';
      if (!empty($user_data[$email_key])) {
        $user_info['mail'] = $user_data[$email_key];
      }

      // Map username.
      $username_key = !empty($mapping['username']) ? $mapping['username'] : 'email';
      if (!empty($user_data[$username_key])) {
        $user_info['name'] = $user_data[$username_key];
      }
      else {
        // Generate a username based on the ID if no mapping is available.
        $user_info['name'] = 'wso2_' . substr($authname, 0, 20);
      }

      // Check if the username already exists and modify it if necessary
      if (user_load_by_name($user_info['name'])) {
        $base_name = $user_info['name'];
        $i = 1;
        while (user_load_by_name($user_info['name'] . '_' . $i)) {
          $i++;
        }
        $user_info['name'] = $base_name . '_' . $i;
        $this->logger->notice('WSO2 Auth: Username @base already exists, using @new instead', [
          '@base' => $base_name,
          '@new' => $user_info['name'],
        ]);
      }

      try {
        // Register the user.
        $account = $this->externalAuth->register($authname, 'wso2_auth', $user_info);

        // Set additional user fields.
        if ($account) {
          $this->updateUserFields($account, $user_data);

          // Assign the default role if configured
          $this->assignUserRole($account);

          $this->logger->notice('WSO2 Auth: New user registered with ID @id', [
            '@id' => $account->id(),
          ]);
        }
      }
      catch (\Exception $e) {
        $this->logger->error('WSO2 Auth: Error registering user: @error', [
          '@error' => $e->getMessage(),
        ]);
        return FALSE;
      }
    }
    elseif ($account) {
      // Update existing user fields if needed.
      $this->updateUserFields($account, $user_data);
    }

    if ($account) {
      // Login the user.
      try {
        $this->externalAuth->userLoginFinalize($account, $authname, 'wso2_auth');

        // Invoke hook_wso2_auth_post_login().
        \Drupal::moduleHandler()->invokeAll('wso2_auth_post_login', [$account, $user_data]);

        return $account;
      }
      catch (\Exception $e) {
        $this->logger->error('WSO2 Auth: Error logging in user: @error', [
          '@error' => $e->getMessage(),
        ]);
        return FALSE;
      }
    }

    return FALSE;
  }

  /**
   * Update user fields based on WSO2 data.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   * @param array $user_data
   *   The user data from WSO2.
   */
  protected function updateUserFields($account, array $user_data) {
    $config = $this->configFactory->get('wso2_auth.settings');
    $mapping = $config->get('citizen.mapping');
    $updated = FALSE;

    // Update first name if available.
    $first_name_key = !empty($mapping['first_name']) ? $mapping['first_name'] : 'given_name';
    if (!empty($user_data[$first_name_key]) && $account->hasField('field_user_firstname')) {
      $account->set('field_user_firstname', $user_data[$first_name_key]);
      $updated = TRUE;
    }

    // Update last name if available.
    $last_name_key = !empty($mapping['last_name']) ? $mapping['last_name'] : 'family_name';
    if (!empty($user_data[$last_name_key]) && $account->hasField('field_user_lastname')) {
      $account->set('field_user_lastname', $user_data[$last_name_key]);
      $updated = TRUE;
    }

    // Update fiscal code if available.
    $fiscal_code_key = !empty($mapping['fiscal_code']) ? $mapping['fiscal_code'] : 'cn';
    if (!empty($user_data[$fiscal_code_key]) && $account->hasField('field_user_fiscalcode')) {
      $account->set('field_user_fiscalcode', $user_data[$fiscal_code_key]);
      $updated = TRUE;
    }

    // Update mobile phone if available.
    $mobile_phone_key = !empty($mapping['mobile_phone']) ? $mapping['mobile_phone'] : '';
    if (!empty($mobile_phone_key) && !empty($user_data[$mobile_phone_key]) && $account->hasField('field_user_mobilephone')) {
      $account->set('field_user_mobilephone', $user_data[$mobile_phone_key]);
      $updated = TRUE;
    }

    // Save the account if it was updated.
    if ($updated) {
      $account->save();
    }
  }

  /**
   * Assign the configured role to a user.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   */
  protected function assignUserRole($account) {
    $config = $this->configFactory->get('wso2_auth.settings');
    $role = $config->get('citizen.user_role');

    // Only assign a role if a non-default role is configured
    if (!empty($role) && $role != 'none') {
      // Check if user already has any of the excluded roles
      $roles_to_exclude = $config->get('citizen.roles_to_exclude');

      $has_excluded_role = FALSE;
      if (!empty($roles_to_exclude)) {
        foreach ($roles_to_exclude as $role_id => $enabled) {
          if ($enabled && $account->hasRole($role_id)) {
            $has_excluded_role = TRUE;
            break;
          }
        }
      }

      // If user doesn't have any excluded role, assign the configured role
      if (!$has_excluded_role && !$account->hasRole($role)) {
        $account->addRole($role);
        $account->save();

        $this->logger->notice('WSO2 Auth: Role @role assigned to user @user', [
          '@role' => $role,
          '@user' => $account->getAccountName(),
        ]);
      }
    }
  }

  /**
   * Get the logout URL.
   *
   * @param string $id_token
   *   The ID token from the authentication.
   * @param string $destination
   *   The destination to redirect to after logout.
   *
   * @return string
   *   The logout URL.
   */
  public function getLogoutUrl($id_token, $destination = '') {
    $config = $this->configFactory->get('wso2_auth.settings');

    // Generate the state parameter.
    $state = $this->generateState();

    // Use the site base URL if no destination is provided.
    $redirect_uri = !empty($destination)
      ? $destination
      : Url::fromRoute('<front>')->setAbsolute()->toString();

    // Get the logout URL from the environment helper or build it from the auth server URL
    $logout_url = $this->environmentHelper->getLogoutUrl();
    if (empty($logout_url)) {
      // Convert oauth2 URL to oidc URL for logout
      $auth_server_url = $this->environmentHelper->getAuthServerUrl();
      $logout_url = str_replace('/oauth2', '/oidc', $auth_server_url) . '/logout';
    }

    $params = [
      'id_token_hint' => $id_token,
      'post_logout_redirect_uri' => $redirect_uri,
      'state' => $state,
    ];

    // Allow other modules to alter the logout URL.
    $built_url = $logout_url . '?' . http_build_query($params);
    \Drupal::moduleHandler()->alter('wso2_auth_logout_url', $built_url, $params);

    return $built_url;
  }

  /**
   * Check if a user is already authenticated with WSO2.
   *
   * @return bool
   *   TRUE if the user is already authenticated with WSO2.
   */
  public function isUserAuthenticated() {
    // Check if the user has a valid WSO2 session.
    $wso2_session = $this->session->get('wso2_auth_session');
    $config = $this->configFactory->get('wso2_auth.settings');

    if ($config->get('debug')) {
      $this->logger->debug('WSO2 Auth: Checking if user is authenticated. Session: @session', [
        '@session' => !empty($wso2_session) ? 'exists' : 'not exists',
      ]);
    }

    if (!empty($wso2_session) && !empty($wso2_session['access_token']) && !empty($wso2_session['expires'])) {
      // Check if the token has expired.
      if ($wso2_session['expires'] > time()) {
        if ($config->get('debug')) {
          $this->logger->debug('WSO2 Auth: User has a valid session.');
        }
        return TRUE;
      }
      else {
        if ($config->get('debug')) {
          $this->logger->debug('WSO2 Auth: Token has expired.');
        }
      }
    }

    // Check if we can silently authenticate with WSO2 (cookie-based session)
    // This would require checking for WSO2 cookies or other session indicators
    // Alternatively, we can just attempt the authorization flow and see if it works

    return FALSE;
  }
}
