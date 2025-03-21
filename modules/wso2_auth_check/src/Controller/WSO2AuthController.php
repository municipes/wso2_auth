<?php

namespace Drupal\wso2_auth_check\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Drupal\Core\Url;

/**
 * Controller per la gestione dell'autenticazione WSO2.
 */
class WSO2AuthController extends ControllerBase {

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
   * Gestisce il callback di autenticazione da WSO2.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La richiesta HTTP.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   La risposta HTTP.
   */
  public function handleCallback(Request $request) {
    // Verifica se è abilitato l'auto-login.
    $config = $this->config('wso2_auth_check.settings');
    if (!$config->get('enable_auto_login')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Auto-login is disabled'], 403);
    }

    // L'utente è già autenticato, non fare nulla.
    if (!$this->currentUser->isAnonymous()) {
      return new JsonResponse(['success' => TRUE, 'message' => 'Already authenticated']);
    }

    // Gestione delle chiamate POST (da JavaScript) con token JWT.
    if ($request->isMethod('POST') && $request->getContentType() === 'json') {
      $data = json_decode($request->getContent(), TRUE);

      if (empty($data['id_token'])) {
        return new JsonResponse(['success' => FALSE, 'message' => 'No ID token provided'], 400);
      }

      // Invece di gestire il login qui, reindirizza al modulo esistente
      $loginPath = $config->get('login_path') ?: '/wso2silfi/connect/cittadino';
      return new JsonResponse([
        'success' => TRUE,
        'redirect' => $loginPath
      ]);
    }

    // Gestione delle chiamate GET con parametri di errore o token nell'URL.
    if ($request->isMethod('GET')) {
      $error = $request->query->get('error');
      $id_token = $request->query->get('id_token');

      if ($error === 'login_required') {
        // L'utente non è autenticato nell'IdP, non fare nulla.
        return new Response('Authentication required', 200);
      }

      if ($id_token) {
        // L'IdP ha reindirizzato con un token, renderizza una pagina che invia il token al parent.
        // O reindirizza direttamente al modulo di login esistente.
        $content = '
          <html>
          <head><title>Authentication</title></head>
          <body>
            <script>
              try {
                if (window.opener) {
                  window.opener.postMessage(JSON.stringify({id_token: "' . $id_token . '"}), "*");
                  window.close();
                } else if (window.parent) {
                  window.parent.postMessage(JSON.stringify({id_token: "' . $id_token . '"}), "*");
                } else {
                  // Fallback: reindirizza direttamente
                  const loginPath = "' . $config->get('login_path') . '";
                  window.location.href = loginPath || "/wso2silfi/connect/cittadino";
                }
              } catch(e) {
                console.error("Error posting message", e);
                // In caso di errore, reindirizza comunque
                const loginPath = "' . $config->get('login_path') . '";
                window.location.href = loginPath || "/wso2silfi/connect/cittadino";
              }
            </script>
            <p>Autenticazione completata. Verrai reindirizzato automaticamente...</p>
          </body>
          </html>
        ';

        return new Response($content, 200, ['Content-Type' => 'text/html']);
      }
    }

    // Risposta predefinita.
    return new Response('Invalid request', 400);
  }
}
