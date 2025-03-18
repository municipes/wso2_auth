<?php

namespace Drupal\wso2_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\wso2_auth\WSO2AuthService;

/**
 * Controller for checking WSO2 session status.
 */
class CheckSessionController extends ControllerBase {

  /**
   * The WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService
   */
  protected $wso2Auth;

  /**
   * Constructor for the check session controller.
   *
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   */
  public function __construct(WSO2AuthService $wso2_auth) {
    $this->wso2Auth = $wso2_auth;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wso2_auth.authentication')
    );
  }

  /**
   * Check if the user is authenticated with WSO2.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with authentication status.
   */
  public function checkSession() {
    // Check if the module is configured
    if (!$this->wso2Auth->isConfigured()) {
      return new JsonResponse(['authenticated' => FALSE, 'error' => 'WSO2 authentication is not properly configured.']);
    }

    // Check if the user has a valid WSO2 session
    $isAuthenticated = $this->wso2Auth->isUserAuthenticated();

    if ($this->config('wso2_auth.settings')->get('debug')) {
      $this->getLogger('wso2_auth')->debug('Check Session: authenticated=@auth', [
        '@auth' => $isAuthenticated ? 'yes' : 'no',
      ]);
    }

    // Return the status
    return new JsonResponse(['authenticated' => $isAuthenticated]);
  }

}
