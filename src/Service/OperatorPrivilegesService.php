<?php

namespace Drupal\wso2_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for managing operator privileges.
 */
class OperatorPrivilegesService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The JWT token.
   *
   * @var string
   */
  protected $jwtToken;

  /**
   * The endpoint URL.
   *
   * @var string
   */
  protected $endpoint;

  /**
   * Whether debug mode is enabled.
   *
   * @var bool
   */
  protected $debug;

  /**
   * Constructor for the operator privileges service.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('wso2_auth');
    $this->requestStack = $request_stack;

    // Set the correct endpoint based on the stage setting
    $config = $this->configFactory->get('wso2_auth.settings');
    if ($config->get('stage')) {
      $this->endpoint = $config->get('operator.privileges_stage_url');
    }
    else {
      $this->endpoint = $config->get('operator.privileges_url');
    }
    // Inizializza la variabile debug una sola volta
    $this->debug = $config->get('debug');
  }

  /**
   * Login to the privileges service and get a JWT token.
   *
   * @return bool
   *   TRUE if the login was successful.
   */
  public function login() {
    $config = $this->configFactory->get('wso2_auth.settings');

    // Already have a token
    if (!empty($this->jwtToken)) {
      return TRUE;
    }

    // Check if the service is configured
    if (empty($this->endpoint) || empty($config->get('operator.username')) || empty($config->get('operator.password'))) {
      $this->logger->error('Operator privileges service is not properly configured.');
      return FALSE;
    }

    $path = '/login';
    $credentials = [
      'username' => $config->get('operator.username'),
      'password' => $config->get('operator.password'),
    ];

    $options = [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'json' => $credentials,
    ];

    // Skip SSL verification if configured
    if ($config->get('skip_ssl_verification')) {
      $options['verify'] = FALSE;
    }

    try {
      $response = $this->httpClient->request('POST', $this->endpoint . $path, $options);
      $response_data = (string) $response->getBody();

      if (!empty($response_data)) {
        $this->jwtToken = $response_data;
        return TRUE;
      }
    }
    catch (RequestException $e) {
      $this->logger->error('Error during privileges service login: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * Get the JWT token.
   *
   * @return string|null
   *   The JWT token or NULL if not available.
   */
  public function getJwtToken() {
    return $this->jwtToken;
  }

  /**
   * Get the functions for an operator.
   *
   * @param string $operator
   *   The operator username.
   *
   * @return array|false
   *   An array of functions or FALSE if an error occurred.
   */
  public function getOperatorFunctions($operator) {
    if (!$this->login()) {
      return FALSE;
    }

    $config = $this->configFactory->get('wso2_auth.settings');
    $ente = $config->get('operator.ente');
    $app = $config->get('operator.app');

    if (empty($ente) || empty($app)) {
      $this->logger->error('Entity code or application code is not configured.');
      return FALSE;
    }

    $path = "/1.0/operatore-funzioni/{$operator}/{$app}/{$ente}";
    return $this->callMethod('listaFunzioneOperatore', $path);
  }

  /**
   * Check if an operator is authorized for a specific function.
   *
   * @param string $operator
   *   The operator username.
   * @param string $function
   *   The function name.
   *
   * @return bool|null
   *   TRUE if authorized, FALSE if not, NULL if an error occurred.
   */
  public function checkOperatorFunction($operator, $function) {
    if (!$this->login()) {
      return NULL;
    }

    $config = $this->configFactory->get('wso2_auth.settings');
    $ente = $config->get('operator.ente');
    $app = $config->get('operator.app');

    if (empty($ente) || empty($app)) {
      $this->logger->error('Entity code or application code is not configured.');
      return NULL;
    }

    $path = "/1.0/operatore-check/{$operator}/{$app}/{$function}/{$ente}";
    $result = $this->callMethod('operatoreAbilitato', $path);

    if ($result === FALSE) {
      return NULL;
    }

    return !empty($result);
  }

  /**
   * Get all triplets defined for the entity.
   *
   * @return array|false
   *   An array of triplets or FALSE if an error occurred.
   */
  public function getTriplets() {
    if (!$this->login()) {
      return FALSE;
    }

    $config = $this->configFactory->get('wso2_auth.settings');
    $ente = $config->get('operator.ente');

    if (empty($ente)) {
      $this->logger->error('Entity code is not configured.');
      return FALSE;
    }

    $path = "/1.0/triplette/{$ente}";
    return $this->callMethod('listaTriplette', $path);
  }

  /**
   * Get all triplets enabled for an operator.
   *
   * @param string $operator
   *   The operator username.
   *
   * @return array|false
   *   An array of triplets or FALSE if an error occurred.
   */
  public function getOperatorTriplets($operator) {
    if (!$this->login()) {
      return FALSE;
    }

    $config = $this->configFactory->get('wso2_auth.settings');
    $ente = $config->get('operator.ente');
    $app = $config->get('operator.app');

    if (empty($ente) || empty($app)) {
      $this->logger->error('Entity code or application code is not configured.');
      return FALSE;
    }

    $path = "/1.0/operatore-triplette/{$operator}/{$app}/{$ente}";
    return $this->callMethod('listaTriplettaOperatore', $path);
  }

  /**
   * Call a specific method on the privileges service.
   *
   * @param string $method
   *   The method name to extract from the response.
   * @param string $path
   *   The API path.
   *
   * @return mixed
   *   The result or FALSE if an error occurred.
   */
  protected function callMethod($method, $path) {
    $config = $this->configFactory->get('wso2_auth.settings');

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ];

    // Skip SSL verification if configured
    if ($config->get('skip_ssl_verification')) {
      $options['verify'] = FALSE;
    }

    try {
      $response = $this->httpClient->request('GET', $this->endpoint . $path, $options);
      $response_data = (string) $response->getBody();

      if (!empty($response_data)) {
        $data = json_decode($response_data);

        if (json_last_error() !== JSON_ERROR_NONE) {
          $this->logger->error('Error decoding JSON response: @error', [
            '@error' => json_last_error_msg(),
          ]);
          return FALSE;
        }

        if (isset($data->esito) && $data->esito === 'SUCCESS') {
          if (isset($data->$method)) {
            return $data->$method;
          }
          return TRUE;
        }
        else {
          $message = isset($data->messaggio) ? $data->messaggio : 'Unknown error';
          $this->logger->error('Error in privileges service call: @error', [
            '@error' => $message,
          ]);
        }
      }
    }
    catch (RequestException $e) {
      $this->logger->error('Error calling privileges service: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

}
