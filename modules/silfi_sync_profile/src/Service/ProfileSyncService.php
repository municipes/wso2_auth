<?php

namespace Drupal\silfi_sync_profile\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for synchronizing user profile data from OpenCity.
 */
class ProfileSyncService {

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
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Get API Manager base URL based on environment setting.
   *
   * @return string
   *   The API base URL.
   */
  protected function getApiBaseUrl(): string {
    $wso2_config = $this->configFactory->get('wso2_auth.settings');
    $environment = $wso2_config->get('citizen.api_manager_environment') ?? 'staging';

    return $environment === 'production' ? 'https://api.055055.it' : 'https://api-staging.055055.it';
  }

  /**
   * Constructor.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $logger,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    RequestStack $request_stack
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
  }

  /**
   * Check if user is authenticated.
   *
   * @return bool
   *   TRUE if user is authenticated, FALSE otherwise.
   */
  public function isUserAuthenticated(): bool {
    return !$this->currentUser->isAnonymous();
  }

  /**
   * Check if sync was already performed in the last 30 minutes.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return bool
   *   TRUE if sync was already performed recently, FALSE otherwise.
   */
  public function wasSyncPerformedRecently(int $user_id): bool {
    $last_sync_key = 'silfi_sync_profile.last_sync.' . $user_id;
    $last_sync = \Drupal::state()->get($last_sync_key);

    if (!$last_sync) {
      return FALSE;
    }

    // Check if last sync was within the last 30 minutes (1800 seconds)
    $threshold = time() - 1800;
    return $last_sync > $threshold;
  }

  /**
   * Mark sync as performed for the current time.
   *
   * @param int $user_id
   *   The user ID.
   */
  public function markSyncPerformed(int $user_id): void {
    $last_sync_key = 'silfi_sync_profile.last_sync.' . $user_id;
    \Drupal::state()->set($last_sync_key, time());
  }

  /**
   * Get API Manager token using client_credentials grant.
   *
   * @return string|null
   *   The access token or NULL if not available.
   */
  public function getApiManagerToken(): ?string {
    $wso2_config = $this->configFactory->get('wso2_auth.settings');
    if (!$wso2_config) {
      $this->logger->error('WSO2 configuration not available');
      return NULL;
    }

    $client_id = $wso2_config->get('citizen.api_manager_client_id');
    $client_secret = $wso2_config->get('citizen.api_manager_client_secret');

    if (empty($client_id) || empty($client_secret)) {
      $this->logger->error('API Manager client credentials not configured');
      return NULL;
    }

    $api_base_url = $this->getApiBaseUrl();
    $token_url = $api_base_url . '/token?grant_type=client_credentials&client_id=' . urlencode($client_id) . '&client_secret=' . urlencode($client_secret);

    $options = [
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
      ],
      'timeout' => 30,
      'connect_timeout' => 10,
    ];

    // Skip SSL verification if configured
    if ($wso2_config->get('skip_ssl_verification')) {
      $options['verify'] = FALSE;
    }

    try {
      $this->logger->debug('Requesting API Manager token from: @url', ['@url' => $token_url]);

      $response = $this->httpClient->post($token_url, $options);
      $data = json_decode((string) $response->getBody(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('Error decoding token response: @error', [
          '@error' => json_last_error_msg(),
        ]);
        return NULL;
      }

      if (!empty($data['access_token'])) {
        $this->logger->info('Successfully obtained API Manager token');
        return $data['access_token'];
      }
      else {
        $this->logger->warning('Token response did not contain access_token: @data', [
          '@data' => print_r($data, TRUE),
        ]);
        return NULL;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error requesting API Manager token: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get user's fiscal code.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return string|null
   *   The fiscal code or NULL if not available.
   */
  public function getUserFiscalCode(int $user_id): ?string {
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);

      if ($user && $user->hasField('field_user_fiscalcode')) {
        $fiscal_code_value = $user->get('field_user_fiscalcode')->value;
        return !empty($fiscal_code_value) ? $fiscal_code_value : NULL;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading user or fiscal code: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Fetch user data from OpenCity service.
   *
   * @param string $fiscal_code
   *   The user's fiscal code.
   * @param string $auth_token
   *   The API Manager auth token.
   *
   * @return array|null
   *   The user data from OpenCity or NULL on failure.
   */
  public function fetchUserDataFromOpenCity(string $fiscal_code, string $auth_token): ?array {
    $url = $this->getApiBaseUrl() . '/opencity/1.0/utente/dati-base/' . urlencode($fiscal_code);

    $options = [
      'headers' => [
        'accept' => 'application/json',
        'Authorization' => 'Bearer ' . $auth_token,
      ],
      'timeout' => 30,
      'connect_timeout' => 10,
    ];

    // Skip SSL verification if configured in WSO2 auth
    $wso2_config = $this->configFactory->get('wso2_auth.settings');
    if ($wso2_config && $wso2_config->get('skip_ssl_verification')) {
      $options['verify'] = FALSE;
      $this->logger->debug('SSL verification disabled for OpenCity API call');
    }

    try {
      // Debug logging for request details
      $this->logger->info('Calling OpenCity API with details:', [
        'url' => $url,
        'fiscal_code' => $fiscal_code,
        'auth_token_length' => strlen($auth_token),
        'auth_token_prefix' => substr($auth_token, 0, 10) . '...',
        'headers' => [
          'accept' => $options['headers']['accept'],
          'Authorization' => 'Bearer ' . substr($auth_token, 0, 10) . '...',
        ],
        'ssl_verify' => $options['verify'] ?? TRUE,
        'timeout' => $options['timeout'],
        'connect_timeout' => $options['connect_timeout'],
      ]);

      $response = $this->httpClient->get($url, $options);

      // Debug response details
      $this->logger->info('OpenCity API response received:', [
        'status_code' => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'body_length' => strlen($response->getBody()),
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('Error decoding OpenCity response: @error, Raw response: @response', [
          '@error' => json_last_error_msg(),
          '@response' => substr((string) $response->getBody(), 0, 500),
        ]);
        return NULL;
      }

      // Check if the response indicates success
      if (isset($data['esito']) && $data['esito'] === 'SUCCESS') {
        $this->logger->info('Successfully fetched user data from OpenCity');
        return $data;
      }
      else {
        $this->logger->warning('OpenCity API returned non-success response: @data', [
          '@data' => print_r($data, TRUE),
        ]);
        return NULL;
      }
    }
    catch (RequestException $e) {
      // Enhanced error logging with more details
      $error_details = [
        'error_message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'url' => $url,
        'fiscal_code' => $fiscal_code,
      ];

      // Add response details if available
      if ($e->hasResponse()) {
        $response = $e->getResponse();
        $error_details['response_status'] = $response->getStatusCode();
        $error_details['response_headers'] = $response->getHeaders();
        $error_details['response_body'] = substr((string) $response->getBody(), 0, 500);
      }

      // Add request details if available
      if ($e->getRequest()) {
        $request = $e->getRequest();
        $error_details['request_method'] = $request->getMethod();
        $error_details['request_uri'] = (string) $request->getUri();
        $error_details['request_headers'] = $request->getHeaders();
      }

      $this->logger->error('Error calling OpenCity API with full details: @details', [
        '@details' => print_r($error_details, TRUE),
      ]);

      return NULL;
    }
  }

  /**
   * Update user profile with data from OpenCity.
   *
   * @param int $user_id
   *   The user ID.
   * @param array $opencity_data
   *   The data from OpenCity service.
   *
   * @return bool
   *   TRUE if update was successful, FALSE otherwise.
   */
  public function updateUserProfile(int $user_id, array $opencity_data): bool {
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);

      if (!$user) {
        $this->logger->error('User not found: @user_id', ['@user_id' => $user_id]);
        return FALSE;
      }

      $updated = FALSE;

      // Update mobile phone
      if (isset($opencity_data['cellulare']) &&
          !empty($opencity_data['cellulare']) &&
          $user->hasField('field_user_mobilephone')) {

        $current_mobile = $user->get('field_user_mobilephone')->value;
        $new_mobile = $opencity_data['cellulare'];

        if ($current_mobile !== $new_mobile) {
          $user->set('field_user_mobilephone', $new_mobile);
          $updated = TRUE;
          $this->logger->info('Updated mobile phone for user @user_id: @old -> @new', [
            '@user_id' => $user_id,
            '@old' => $current_mobile ?: 'empty',
            '@new' => $new_mobile,
          ]);
        }
      }

      // Update email
      if (isset($opencity_data['email']) &&
          !empty($opencity_data['email']) &&
          $user->hasField('field_user_mail')) {

        $current_email = $user->get('field_user_mail')->value;
        $new_email = $opencity_data['email'];

        if ($current_email !== $new_email) {
          $user->set('field_user_mail', $new_email);
          $updated = TRUE;
          $this->logger->info('Updated email for user @user_id: @old -> @new', [
            '@user_id' => $user_id,
            '@old' => $current_email ?: 'empty',
            '@new' => $new_email,
          ]);
        }
      }

      // Update first name
      if (isset($opencity_data['nome']) &&
          !empty($opencity_data['nome']) &&
          $user->hasField('field_user_firstname')) {

        $current_firstname = $user->get('field_user_firstname')->value;
        $new_firstname = $opencity_data['nome'];

        if ($current_firstname !== $new_firstname) {
          $user->set('field_user_firstname', $new_firstname);
          $updated = TRUE;
          $this->logger->info('Updated first name for user @user_id: @old -> @new', [
            '@user_id' => $user_id,
            '@old' => $current_firstname ?: 'empty',
            '@new' => $new_firstname,
          ]);
        }
      }

      // Update last name
      if (isset($opencity_data['cognome']) &&
          !empty($opencity_data['cognome']) &&
          $user->hasField('field_user_lastname')) {

        $current_lastname = $user->get('field_user_lastname')->value;
        $new_lastname = $opencity_data['cognome'];

        if ($current_lastname !== $new_lastname) {
          $user->set('field_user_lastname', $new_lastname);
          $updated = TRUE;
          $this->logger->info('Updated last name for user @user_id: @old -> @new', [
            '@user_id' => $user_id,
            '@old' => $current_lastname ?: 'empty',
            '@new' => $new_lastname,
          ]);
        }
      }

      // Save the user if any updates were made
      if ($updated) {
        $user->save();
        $this->logger->info('Successfully updated user profile for user @user_id', [
          '@user_id' => $user_id,
        ]);
        return TRUE;
      }
      else {
        $this->logger->info('No profile updates needed for user @user_id', [
          '@user_id' => $user_id,
        ]);
        return TRUE;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating user profile: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Perform the complete sync process.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return bool
   *   TRUE if sync was successful or not needed, FALSE on error.
   */
  public function performSync(int $user_id): bool {
    // Check if user is authenticated
    if (!$this->isUserAuthenticated()) {
      $this->logger->debug('User not authenticated, skipping sync');
      return FALSE;
    }

    // Check if sync was already performed recently
    if ($this->wasSyncPerformedRecently($user_id)) {
      $this->logger->debug('Sync already performed recently for user @user_id, skipping', [
        '@user_id' => $user_id,
      ]);
      return TRUE;
    }

    // Get API Manager auth token
    $auth_token = $this->getApiManagerToken();
    if (!$auth_token) {
      $this->logger->warning('API Manager auth token not available for user @user_id', [
        '@user_id' => $user_id,
      ]);
      return FALSE;
    }

    // Get user's fiscal code
    $fiscal_code = $this->getUserFiscalCode($user_id);
    if (!$fiscal_code) {
      $this->logger->warning('Fiscal code not available for user @user_id', [
        '@user_id' => $user_id,
      ]);
      return FALSE;
    }

    // Fetch data from OpenCity
    $opencity_data = $this->fetchUserDataFromOpenCity($fiscal_code, $auth_token);
    if (!$opencity_data) {
      $this->logger->warning('Failed to fetch data from OpenCity for user @user_id', [
        '@user_id' => $user_id,
      ]);
      return FALSE;
    }

    // Update user profile
    $update_success = $this->updateUserProfile($user_id, $opencity_data);

    // Mark sync as performed regardless of update success to avoid repeated attempts
    $this->markSyncPerformed($user_id);

    return $update_success;
  }

}
