<?php

namespace Drupal\wso2_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\wso2_auth\WSO2AuthService;
use Drupal\wso2_auth\Service\OperatorPrivilegesService;

/**
 * Controller for WSO2 authentication.
 */
class WSO2AuthController extends ControllerBase {

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
   */
  public function __construct(
    WSO2AuthService $wso2_auth,
    RequestStack $request_stack,
    OperatorPrivilegesService $privileges_service
  ) {
    $this->wso2Auth = $wso2_auth;
    $this->requestStack = $request_stack;
    $this->privilegesService = $privileges_service;

    // Inizializza la variabile debug una sola volta
    $this->debug = \Drupal::config('wso2_auth.settings')->get('debug');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wso2_auth.authentication'),
      $container->get('request_stack'),
      $container->get('wso2_auth.operator_privileges')
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
  public function authorize($type = 'citizen') {
    // Get the current request.
    $request = $this->requestStack->getCurrentRequest();

    // Check if WSO2 authentication is configured.
    if (!$this->wso2Auth->isConfigured()) {
      $this->messenger()->addError($this->t('WSO2 authentication is not properly configured.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // For operator authentication, check if it's enabled
    if ($type === 'operator' && !$this->config('wso2_auth.settings')->get('operator.enabled')) {
      $this->messenger()->addError($this->t('Operator authentication is not enabled.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Get the destination from the request if available.
    $destination = $request->query->get('destinazione');

    // Log the destination parameter for debugging
    if ($destination && $this->debug) {
      $this->getLogger('wso2_auth')->debug('Destination parameter found in request: @destination', [
        '@destination' => $destination,
      ]);
    }

    // Generate the authorization URL.
    $url = $this->wso2Auth->getAuthorizationUrl($destination, $type);
    // Aggiungi un timestamp o un numero casuale all'URL per evitare la cache
    $url = $this->wso2Auth->getAuthorizationUrl($destination, $type);
    $url .= (strpos($url, '?') !== false ? '&' : '?') . 'nocache=' . time();

    // Log the final URL we're redirecting to
    if ($this->debug) {
      $this->getLogger('wso2_auth')->debug('Redirecting to WSO2 authorization URL: @url', [
        '@url' => $url,
      ]);
    }

    // Try to use a standard RedirectResponse as a test
    // in case there's an issue with TrustedRedirectResponse
    try {
      if ($this->debug) {
        $this->getLogger('wso2_auth')->debug('Using TrustedRedirectResponse for WSO2 redirect');
        return new TrustedRedirectResponse($url);
      }
    }
    catch (\Exception $e) {
        $this->getLogger('wso2_auth')->error('Error with TrustedRedirectResponse: @error', [
            '@error' => $e->getMessage(),
        ]);
        // As a fallback, attempt to use standard RedirectResponse
        $this->getLogger('wso2_auth')->debug('Falling back to standard RedirectResponse');
        return new RedirectResponse($url);
    }
  }

  /**
   * Handles the OAuth2 callback from WSO2.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response after handling the callback.
   */
  public function callback() {
    $code = \Drupal::request()->query->get('code');
    $error = \Drupal::request()->query->get('error');
    $state = \Drupal::request()->query->get('state');

    // return [
    //   '#markup' => '<script>
    //     window.parent.postMessage({
    //       wso2Auth: {
    //         code: "' . $code . '",
    //         error: "' . $error . '",
    //         error_description: "' . \Drupal::request()->query->get('error_description') . '",
    //         state: "' . $state . '"
    //       }
    //     }, window.location.origin);
    //   </script>'
    // ];

    // Prendi la richiesta corrente
    $request = $this->requestStack->getCurrentRequest();

    // Prendi il codice di autorizzazione e lo state dalla richiesta
    $code = $request->query->get('code');
    $state = $request->query->get('state');
    $session_state = $request->query->get('session_state');
    if ($this->debug) {
      $this->getLogger('wso2_auth')->notice('Received code. Code: @code, State: @state, SessionState: @session_state', [
        '@code' => $code,
        '@state' => $state,
        '@session_state' => $session_state,
      ]);
    }

    // Prendi la sessione
    $session = $request->getSession();

    // Controlla se il codice e lo state sono disponibili
    if (empty($code) || empty($state)) {
      $this->messenger()->addError($this->t('Risposta di autorizzazione non valida.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Verifica il parametro state
    if (!$this->wso2Auth->verifyState($state)) {
      $this->messenger()->addError($this->t('Parametro state non valido.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Prendi il tipo di autenticazione dalla sessione
    $authType = $session->get('wso2_auth_type', 'citizen');

    // Scambia il codice di autorizzazione per i token
    $tokens = $this->wso2Auth->getTokens($code);
    if (!$tokens) {
      $this->messenger()->addError($this->t('Impossibile ottenere il token di accesso.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Ottieni le informazioni dell'utente
    $user_info = $this->wso2Auth->getUserInfo($tokens['access_token']);
    if (!$user_info) {
      $this->messenger()->addError($this->t('Impossibile ottenere le informazioni utente.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Ottieni la destinazione dalla sessione
    $destination = $session->get('wso2_auth_destination');
    $session->remove('wso2_auth_destination');

    // Gestisci l'autenticazione in base al tipo
    if ($authType === 'operator') {
      $account = $this->authenticateOperator($user_info, $tokens);
    } else {
      $account = $this->authenticateUser($user_info);
    }

    if (!$account) {
      $this->messenger()->addError($this->t('Autenticazione fallita.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    $session->set('wso2_auth_state', $state);

    // Store token information in the session.
    $session->set('wso2_auth_session', [
      'access_token' => $tokens['access_token'],
      'refresh_token' => $tokens['refresh_token'] ?? null,
      'id_token' => $tokens['id_token'],
      'expires' => time() + $tokens['expires_in'],
    ]);

    // Reindirizza alla destinazione o alla pagina principale
    $this->getLogger('wso2_auth')->notice('Redirect con destination: @type', [
      '@type' => $destination,
    ]);
    if (!empty($destination)) {
      // Dopo aver autenticato l'utente
      $destination = Url::fromUserInput($destination)->setAbsolute()->toString();
      return new RedirectResponse($destination);
    }

    return new RedirectResponse(Url::fromRoute('<front>')->toString());
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
  public function logout() {
    // Prendi la richiesta corrente
    $request = $this->requestStack->getCurrentRequest();
    $config = $this->configFactory->get('wso2_auth.settings');

    // Prendi la sessione
    $session = $request->getSession();

    // Controlla se l'utente ha una sessione WSO2
    $wso2_session = $session->get('wso2_auth_session');

    // Se non c'è una sessione WSO2, fai solo il logout da Drupal
    if (empty($wso2_session) || empty($wso2_session['id_token'])) {
      user_logout();
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
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
    user_logout();

    // Reindirizza all'URL di logout WSO2
    return new TrustedRedirectResponse($logout_url);
  }
}
