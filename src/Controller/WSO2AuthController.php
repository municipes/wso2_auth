<?php

namespace Drupal\wso2_auth\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\wso2_auth\WSO2AuthService;
use Drupal\wso2_auth\Service\OperatorPrivilegesService;
use Drupal\wso2_auth\Service\SecureRedirectService;

/**
 * Controller for WSO2 authentication.
 */
class WSO2AuthController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

  /**
   * The WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService
   */
  protected $wso2Auth;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The operator privileges service.
   *
   * @var \Drupal\wso2_auth\Service\OperatorPrivilegesService
   */
  protected $privilegesService;

  /**
   * The config factory service.
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
   * The secure redirect service.
   *
   * @var \Drupal\wso2_auth\Service\SecureRedirectService
   */
  protected $secureRedirect;

  /**
   * Whether debug mode is enabled.
   *
   * @var bool
   */
  protected $debug;

  /**
   * Constructor for the WSO2 authentication controller.
   *
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\wso2_auth\Service\OperatorPrivilegesService $privileges_service
   *   The operator privileges service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\wso2_auth\Service\SecureRedirectService $secure_redirect
   *   The secure redirect service.
   */
  public function __construct(
    WSO2AuthService $wso2_auth,
    RequestStack $request_stack,
    OperatorPrivilegesService $privileges_service,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    SecureRedirectService $secure_redirect
  ) {
    $this->wso2Auth = $wso2_auth;
    $this->requestStack = $request_stack;
    $this->privilegesService = $privileges_service;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->secureRedirect = $secure_redirect;

    // Inizializza la variabile debug una sola volta
    $this->debug = $this->configFactory->get('wso2_auth.settings')->get('debug');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wso2_auth.authentication'),
      $container->get('request_stack'),
      $container->get('wso2_auth.operator_privileges'),
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('wso2_auth.secure_redirect')
    );
  }

  /**
   * Redirects the user to the WSO2 authorization page.
   *
   * @param string $type
   *   The authentication type (citizen or operator).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the WSO2 authorization page.
   */
  public function authorize($type = 'citizen'): RedirectResponse {
    // Get the current request.
    $request = $this->requestStack->getCurrentRequest();

    // Check if WSO2 authentication is configured.
    if (!$this->wso2Auth->isConfigured()) {
      $this->messenger()->addError($this->t('Authorize: WSO2 authentication is not properly configured.'));
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    // For operator authentication, check if it's enabled
    if ($type === 'operator' && !$this->config('wso2_auth.settings')->get('operator.enabled')) {
      $this->messenger()->addError($this->t('Authorize: Operator authentication is not enabled.'));
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    // Get the destination from the request if available.
    $destination = $request->query->get('destinazione');

    // Se non c'è una destinazione, usa la proprietà referer come fallback
    if (empty($destination)) {
      $referer = $request->headers->get('referer');

      // Evita che l'URL di login sia usato come destinazione
      if (!empty($referer) &&
          strpos($referer, 'wso2-auth/authorize') === FALSE &&
          strpos($referer, 'user/login') === FALSE &&
          strpos($referer, 'user/logout') === FALSE) {
        $destination = $referer;
      }
    }

    // Log the destination parameter for debugging
    if ($this->debug) {
      $this->getLogger('wso2_auth')->debug('Authorize: Destination param: @destination (from query: @from_query, referer: @referer)', [
        '@destination' => $destination ?: 'null',
        '@from_query' => $request->query->get('destinazione') ?: 'null',
        '@referer' => $request->headers->get('referer') ?: 'null',
      ]);
    }

    // Generate the authorization URL (chiamata una sola volta).
    $url = $this->wso2Auth->getAuthorizationUrl($destination, $type);
    // Aggiungi un timestamp o un numero casuale all'URL per evitare la cache
    $url .= (strpos($url, '?') !== false ? '&' : '?') . 'nocache=' . time();

    // Log the final URL we're redirecting to
    if ($this->debug) {
      $this->getLogger('wso2_auth')->debug('Authorize: Redirecting to WSO2 authorization URL: @url', [
        '@url' => $url,
      ]);
    }

    // Try to use a standard RedirectResponse as a test
    // in case there's an issue with TrustedRedirectResponse
    try {
      if ($this->debug) {
        $this->getLogger('wso2_auth')->debug('Authorize: Using TrustedRedirectResponse for WSO2 redirect');
      }
      $response = new TrustedRedirectResponse($url);
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      $response->setMaxAge(0); // Disabilita cache browser
      return $response;
    }
    catch (\Exception $e) {
      $this->getLogger('wso2_auth')->error('Authorize: Error with TrustedRedirectResponse: @error', [
          '@error' => $e->getMessage(),
      ]);
      // As a fallback, attempt to use standard RedirectResponse
      $this->getLogger('wso2_auth')->debug('Authorize: Falling back to standard RedirectResponse');
      $response = new RedirectResponse($url);
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }
  }

  /**
   * Handles the OAuth2 callback from WSO2.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response after handling the callback.
   */
  public function callback(): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $code = $request->query->get('code');
    $error = $request->query->get('error');
    $state = $request->query->get('state');

    // Prendi il codice di autorizzazione e lo state dalla richiesta
    $code = $request->query->get('code');
    $state = $request->query->get('state');
    $session_state = $request->query->get('session_state');
    if ($this->debug) {
      $this->getLogger('wso2_auth')->notice('Callback: Received code. Code: @code, State: @state, SessionState: @session_state', [
        '@code' => $code,
        '@state' => $state,
        '@session_state' => $session_state,
      ]);
    }

    // Prendi la sessione
    $session = $request->getSession();
    if ($this->debug) {
      $state_key = $this->wso2Auth->getStateKey('state');
      $stored_state = $this->state->get($state_key);

      $this->getLogger('wso2_auth')->notice('Callback: Verifica stato: state_key=@key, stato=@stato', [
        '@key' => $state_key,
        '@stato' => $stored_state ?: 'null',
      ]);
    }

    // Controlla se il codice e lo state sono disponibili
    if (empty($code) || empty($state)) {
      $this->messenger()->addError($this->t('Callback: Risposta di autorizzazione non valida.'));
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    // Verifica il parametro state
    if (!$this->wso2Auth->verifyState($state)) {
      $this->messenger()->addError($this->t('Callback: Parametro state non valido.'));
      if ($this->debug) {
        $stored_state_session = $request->getSession()->get('wso2_auth_state');
        $stored_state_temp = $this->wso2Auth->getTempStorageValue('wso2_auth_state');
        $this->getLogger('wso2_auth')->notice('Callback: Parametro state non valido: ricevuto @state, in temp/sessione @stored_temp/@stored_sess', [
          '@state' => $state,
          '@stored_temp' => $stored_state_temp ?: 'null',
          '@stored_sess' => $stored_state_session ?: 'null',
        ]);
      }
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      $response->setMaxAge(0); // Disabilita cache browser
      return $response;
    }

    // Prendi il tipo di autenticazione dallo State API
    $authType = $this->state->get('wso2_auth.auth_type') ?: 'citizen';
    if ($this->debug) {
      $this->getLogger('wso2_auth')->notice('Callback: Tipo di autenticazione da State API: @auth_type', [
        '@auth_type' => $authType,
      ]);
    }

    // Scambia il codice di autorizzazione per i token
    $tokens = $this->wso2Auth->getTokens($code);
    if (!$tokens) {
      $this->messenger()->addError($this->t('Callback: Impossibile ottenere il token di accesso.'));
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      $response->setMaxAge(0); // Disabilita cache browser
      return $response;
    }

    // Ottieni le informazioni dell'utente
    $user_info = $this->wso2Auth->getUserInfo($tokens['access_token']);
    if (!$user_info) {
      $this->messenger()->addError($this->t('Callback: Impossibile ottenere le informazioni utente.'));
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      $response->setMaxAge(0); // Disabilita cache browser
      return $response;
    }

    // Ottieni la destinazione dallo State API
    $destination = $this->state->get('wso2_auth.destination');

    $this->getLogger('wso2_auth')->notice('Callback: Destinazione recuperata da State API: @dest', [
      '@dest' => $destination ?: 'null',
    ]);

    // Rimuovi dalla State API dopo l'uso
    $this->state->delete('wso2_auth.destination');

    // Gestisci l'autenticazione in base al tipo
    if ($authType === 'operator') {
      $account = $this->authenticateOperator($user_info, $tokens);
    } else {
      $account = $this->authenticateUser($user_info);
    }

    if (!$account) {
      $this->messenger()->addError($this->t('Callback: Autenticazione fallita.'));
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      $response->setMaxAge(0); // Disabilita cache browser
      return $response;
    }

    // Salva lo stato nello State API di Drupal
    $state_key = $this->wso2Auth->getStateKey('state');
    $this->state->set($state_key, $state);

    // Store token information in the session.
    $session->set('wso2_auth_session', [
      'access_token' => $tokens['access_token'],
      'refresh_token' => $tokens['refresh_token'] ?? null,
      'id_token' => $tokens['id_token'],
      'expires' => time() + $tokens['expires_in'],
    ]);

    // MODIFICA PRINCIPALE: Usa il servizio di redirect sicuro
    $this->getLogger('wso2_auth')->notice('Callback: Elaborazione redirect con destination: @dest', [
      '@dest' => $destination ?: 'homepage',
    ]);

    if (!empty($destination)) {
      // Usa il servizio di redirect sicuro
      $safe_url = $this->secureRedirect->getSafeRedirectUrl($destination, '<front>');

      if ($this->debug) {
        $this->getLogger('wso2_auth')->debug('Callback: Redirect sicuro da @original a @safe', [
          '@original' => $destination,
          '@safe' => $safe_url,
        ]);
      }

      $response = new RedirectResponse($safe_url);
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      $response->setMaxAge(0); // Disabilita cache browser
      return $response;
    }

    // Fallback alla homepage
    $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    $response->setMaxAge(0); // Disabilita cache browser
    return $response;
  }

  /**
   * Authenticate a regular user (citizen).
   *
   * @param array $user_data
   *   The user data from WSO2.
   *
   * @return \Drupal\user\UserInterface|false
   *   The authenticated user or FALSE on failure.
   */
  protected function authenticateUser(array $user_data) {
    return $this->wso2Auth->authenticateUser($user_data);
  }

  /**
   * Authenticate an operator user and set up their roles based on privileges.
   *
   * @param array $user_data
   *   The user data from WSO2.
   * @param array $tokens
   *   The token data.
   *
   * @return \Drupal\user\UserInterface|false
   *   The authenticated user or FALSE on failure.
   */
  protected function authenticateOperator(array $user_data, array $tokens) {
    // Extract identifier for operator (usually 'cn' or 'sub')
    $config = $this->config('wso2_auth.settings');
    $mapping = $config->get('citizen.mapping');
    $id_key = !empty($mapping['user_id']) ? $mapping['user_id'] : 'sub';

    if (empty($user_data[$id_key])) {
      $this->getLogger('wso2_auth')->error('WSO2 Auth: User ID field @field not found in operator data', [
        '@field' => $id_key,
      ]);
      return FALSE;
    }

    $authname = $user_data[$id_key];

    // First, authenticate the user using the regular process
    $account = $this->wso2Auth->authenticateUser($user_data, 'operator');

    if (!$account) {
      return FALSE;
    }

    // Now, fetch operator privileges and assign roles accordingly
    try {
      // Get operator functions from the privileges service
      $functions = $this->privilegesService->getOperatorFunctions($authname);

      if ($functions) {
        // Get role mapping from configuration
        $role_mapping = $this->getRoleMapping();

        // Remove existing operator roles first
        $this->removeOperatorRoles($account);

        // Assign roles based on functions
        if ($role_mapping) {
          foreach ($role_mapping as $role_id => $function_name) {
            foreach ($functions as $function) {
              // Check if the function matches any of our mapped functions
              if (isset($function->funzione) && $function->funzione === $function_name) {
                $account->addRole($role_id);
                if ($this->debug) {
                  $this->getLogger('wso2_auth')->debug('Added role @role to operator @operator', [
                    '@role' => $role_id,
                    '@operator' => $authname,
                  ]);
                }
                break;
              }
            }
          }
          $account->save();
        }
      }

      return $account;
    }
    catch (\Exception $e) {
      $this->getLogger('wso2_auth')->error('Error processing operator privileges: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $account; // Return the account anyway, just without special privileges
    }
  }

  /**
   * Get role mapping for operators.
   *
   * @return array
   *   Associative array of role ID => function name.
   */
  protected function getRoleMapping() {
    $roles = [];
    $config = $this->config('wso2_auth.settings');

    // Get the role population rules
    $rolemap = $config->get('operator.role_population');

    if (!empty($rolemap)) {
      foreach (explode('|', $rolemap) as $rolerule) {
        if (strpos($rolerule, ':') !== FALSE) {
          list($role_id, $function_name) = explode(':', $rolerule, 2);
          $roles[$role_id] = $function_name;
        }
      }
    }

    return $roles;
  }

  /**
   * Remove operator-specific roles from an account.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   */
  protected function removeOperatorRoles($account) {
    $role_mapping = $this->getRoleMapping();

    // Keep track of which roles we've removed
    $removed_roles = [];

    // Remove mapped roles
    foreach (array_keys($role_mapping) as $role_id) {
      if ($account->hasRole($role_id)) {
        $account->removeRole($role_id);
        $removed_roles[] = $role_id;
      }
    }

    if (!empty($removed_roles)) {
      $this->getLogger('wso2_auth')->debug('Removed operator roles from @user: @roles', [
        '@user' => $account->getAccountName(),
        '@roles' => implode(', ', $removed_roles),
      ]);
    }
  }

  /**
   * Logs the user out from WSO2 and Drupal.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response after logout.
   */
  public function logout(): RedirectResponse {
    // Prendi la richiesta corrente
    $request = $this->requestStack->getCurrentRequest();
    $config = $this->configFactory->get('wso2_auth.settings');

    // Prendi la sessione
    $session = $request->getSession();

    // Controlla se l'utente ha una sessione WSO2
    $wso2_session = $session->get('wso2_auth_session');

    // Se non c'è una sessione WSO2, fai solo il logout da Drupal
    if (empty($wso2_session) || empty($wso2_session['id_token'])) {
      $this->userLogout();
      $response = new TrustedRedirectResponse(Url::fromRoute('<front>')->setAbsolute()->toString());
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    // Ottieni il token ID
    $id_token = $wso2_session['id_token'];

    // Log per debug
    if ($config->get('debug')) {
      $this->getLogger('wso2_auth')->debug('Avvio logout WSO2 con id_token: @token', [
        '@token' => substr($id_token, 0, 20) . '...',
      ]);
    }

    // Rimuovi la sessione WSO2
    $session->remove('wso2_auth_session');

    // Ottieni l'URL di logout
    $logout_url = $this->wso2Auth->getLogoutUrl($id_token, Url::fromRoute('<front>')->setAbsolute()->toString());

    // Log per debug
    if ($config->get('debug')) {
      $this->getLogger('wso2_auth')->debug('URL di logout generato: @url', [
        '@url' => $logout_url,
      ]);
    }

    // Fai il logout da Drupal
    $this->userLogout();

    // Reindirizza all'URL di logout WSO2
    $response = new TrustedRedirectResponse($logout_url);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

  /**
   * Helper method to logout a user.
   *
   * Replaces the deprecated user_logout() function.
   */
  protected function userLogout(): void {
    $this->currentUser()->logout();
  }

  /**
   * Check if a URL is external.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is external.
   */
  protected function isExternalUrl(string $url): bool {
    // Se inizia con / ed è un percorso interno
    if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
      return FALSE;
    }

    // Se non è un URL valido, assumiamo sia interno
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return FALSE;
    }

    $parsed_url = parse_url($url);

    // Se non ha host, è interno
    if (!isset($parsed_url['host'])) {
      return FALSE;
    }

    // Ottieni l'host corrente del sito
    $current_request = $this->requestStack->getCurrentRequest();
    $current_host = $current_request ? $current_request->getHttpHost() : '';

    // Se l'host è diverso da quello corrente, è esterno
    return $parsed_url['host'] !== $current_host;
  }
}
