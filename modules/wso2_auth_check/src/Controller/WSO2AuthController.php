<?php

namespace Drupal\wso2_auth_check\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller per la gestione dell'autenticazione WSO2.
 */
class WSO2AuthController extends ControllerBase {

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
    // if (!$config->get('enable_auto_login')) {
    //   return new JsonResponse(['success' => FALSE, 'message' => 'Auto-login is disabled'], 403);
    // }

    // L'utente è già autenticato, non fare nulla.
    if (!$this->currentUser->isAnonymous()) {
      $response = new JsonResponse(['success' => TRUE, 'message' => 'Already authenticated']);
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    if ($request->isMethod('POST') || $request->query->has('id_token')) {
      $data = [];

      // Check JSON content for POST
      if ($request->isMethod('POST')) {
        if ($request->getContentType() === 'application/json') {
          $data = json_decode($request->getContent(), TRUE) ?: [];
        } else {
          // Handle form data for POST
          $data = $request->request->all();
        }
      }

      // Check query parameters if not found in POST data
      if (empty($data['id_token'])) {
        $data['id_token'] = $request->query->get('id_token');
      }

      if (empty($data['id_token'])) {
        $response = new JsonResponse(['success' => FALSE, 'message' => 'No ID token provided'], 400);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
      }

      // Invece di gestire il login qui, reindirizza al modulo esistente
      $loginPath = $config->get('login_path') ?: '/wso2silfi/connect/cittadino';

      // Se è una chiamata AJAX, rispondi con JSON
      if ($request->isXmlHttpRequest()) {
        $response = new JsonResponse([
          'success' => TRUE,
          'redirect' => $loginPath
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
      }

      // Altrimenti, reindirizza direttamente
      $response = new RedirectResponse($loginPath);
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    // Gestione delle chiamate GET con parametri di errore o token nell'URL.
    if ($request->isMethod('GET')) {
      $error = $request->query->get('error');
      $id_token = $request->query->get('id_token');

      if ($error === 'login_required') {
        // L'utente non è autenticato nell'IdP, non fare nulla.
        $response = new Response('Authentication required', 200);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
      }

      if ($id_token) {
        // L'IdP ha reindirizzato con un token, renderizza una pagina che invia il token al parent.
        // O reindirizza direttamente al modulo di login esistente.
        $login_path = $config->get('login_path') ?: '/wso2silfi/connect/cittadino';

        $content = '
          <html>
          <head><title>Authentication</title></head>
          <body>
            <script>
              console.log("Pagina di callback caricata con token");
              try {
                if (window.opener) {
                  console.log("Invio token alla finestra opener");
                  window.opener.postMessage(JSON.stringify({id_token: "' . $id_token . '"}), "*");
                  window.close();
                } else if (window.parent) {
                  console.log("Invio token alla finestra parent");
                  window.parent.postMessage(JSON.stringify({id_token: "' . $id_token . '"}), "*");
                } else {
                  // Fallback: reindirizza direttamente
                  console.log("Nessuna finestra opener o parent, reindirizzo direttamente");
                  const loginPath = "' . $login_path . '";
                  setTimeout(function() {
                    window.location.href = loginPath;
                  }, 500);
                }
              } catch(e) {
                console.error("Error posting message", e);
                // In caso di errore, reindirizza comunque
                setTimeout(function() {
                  window.location.href = "' . $login_path . '";
                }, 500);
              }
            </script>
            <p>Autenticazione completata. Verrai reindirizzato automaticamente...</p>
            <p><a href="' . $login_path . '">Clicca qui se non vieni reindirizzato automaticamente</a></p>
          </body>
          </html>
        ';

        $response = new Response($content, 200, ['Content-Type' => 'text/html']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
      }
    }

    // Risposta predefinita.
    $response = new Response('Invalid request', 400);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }
}
