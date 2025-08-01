<?php

namespace Drupal\wso2_auth_check\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller per la gestione dell'autenticazione WSO2.
 */
class WSO2AuthCheckController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   L'utente corrente.
   */
  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user')
    );
  }

  /**
   * Gestisce il callback di probe SSO da popup.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La richiesta HTTP.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   La risposta HTTP con il template callback.
   */
  public function probeCallback(Request $request) {
    // Restituisce il template HTML per il callback popup
    $build = [
      '#theme' => 'sso_probe_callback',
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    $response = new Response();
    $response->setContent(\Drupal::service('renderer')->renderRoot($build));
    $response->headers->set('Content-Type', 'text/html');
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    $response->setMaxAge(0);

    return $response;
  }

  /**
   * Gestisce lo scambio del codice di autorizzazione.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La richiesta HTTP.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   La risposta JSON.
   */
  public function exchangeCode(Request $request) {
    // Verifica se il controllo automatico è abilitato
    $config = $this->config('wso2_auth_check.settings');
    if (!$config->get('enabled')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Auto-login is disabled'], 403);
    }

    // L'utente è già autenticato, non fare nulla
    if (!$this->currentUser->isAnonymous()) {
      return new JsonResponse([
        'success' => TRUE,
        'logged_in' => TRUE,
        'message' => 'Already authenticated'
      ]);
    }

    // Ottieni i dati della richiesta
    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['code'])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'No authorization code provided'], 400);
    }

    $code = $data['code'];
    $state = $data['state'] ?? '';

    // Log per debug
    if ($config->get('debug')) {
      $this->getLogger('wso2_auth_check')->debug('Exchange code request: code=@code, state=@state', [
        '@code' => substr($code, 0, 20) . '...',
        '@state' => substr($state, 0, 20) . '...',
      ]);
    }

    try {
      // Controlla se esiste il servizio WSO2 Auth
      if (\Drupal::hasService('wso2_auth.authentication')) {
        $wso2_auth = \Drupal::service('wso2_auth.authentication');

        // Verifica lo stato se fornito
        if (!empty($state)) {
          if (!$wso2_auth->verifyState($state)) {
            if ($config->get('debug')) {
              $this->getLogger('wso2_auth_check')->warning('Invalid state in exchange code: @state', ['@state' => $state]);
            }
            return new JsonResponse(['success' => FALSE, 'message' => 'Invalid state parameter'], 400);
          }
        }

        // Scambia il codice per i token
        $tokens = $wso2_auth->getTokens($code);

        if (!$tokens) {
          return new JsonResponse(['success' => FALSE, 'message' => 'Failed to exchange code for tokens'], 400);
        }

        // Ottieni le informazioni utente
        $user_info = $wso2_auth->getUserInfo($tokens['access_token']);

        if (!$user_info) {
          return new JsonResponse(['success' => FALSE, 'message' => 'Failed to get user info'], 400);
        }

        // Autentica l'utente
        $account = $wso2_auth->authenticateUser($user_info);

        if ($account) {
          // Salva le informazioni della sessione
          $session = $request->getSession();
          $session->set('wso2_auth_session', [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'id_token' => $tokens['id_token'],
            'expires' => time() + $tokens['expires_in'],
          ]);

          if ($config->get('debug')) {
            $this->getLogger('wso2_auth_check')->info('SSO exchange successful for user: @user', [
              '@user' => $account->getAccountName(),
            ]);
          }

          return new JsonResponse([
            'success' => TRUE,
            'logged_in' => TRUE,
            'user_id' => $account->id(),
            'username' => $account->getAccountName(),
            'message' => 'Authentication successful'
          ]);
        }
      }

      // Fallback: reindirizza al login manuale
      return new JsonResponse([
        'success' => FALSE,
        'logged_in' => FALSE,
        'message' => 'Authentication service not available',
        'redirect_to_login' => TRUE
      ]);

    } catch (\Exception $e) {
      $this->getLogger('wso2_auth_check')->error('Error during code exchange: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'logged_in' => FALSE,
        'message' => 'Authentication error: ' . $e->getMessage()
      ], 500);
    }
  }

  /**
   * Gestisce il callback legacy (per compatibilità).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La richiesta HTTP.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   La risposta HTTP.
   */
  public function handleCallback(Request $request) {
    // Reindirizza al nuovo callback probe
    return $this->probeCallback($request);
  }
}
