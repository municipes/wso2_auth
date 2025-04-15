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
    if (!$config->get('enabled')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Auto-login is disabled'], 403);
    }

    // L'utente è già autenticato, non fare nulla.
    if (!$this->currentUser->isAnonymous()) {
      $response = new JsonResponse(['success' => TRUE, 'message' => 'Already authenticated']);
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    $response = new Response('Operation completed successfully', 200);
    return $response;
  }
}
