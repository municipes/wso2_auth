<?php

namespace Drupal\wso2_auth\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\wso2_auth\WSO2AuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber for auto-redirecting to WSO2 login.
 */
class WSO2AuthUserLoginSubscriber implements EventSubscriberInterface {

  /**
   * The WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService
   */
  protected $wso2Auth;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructor for the WSO2 user login subscriber.
   *
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    WSO2AuthService $wso2_auth,
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory
  ) {
    $this->wso2Auth = $wso2_auth;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['checkAuthentication', 300],
    ];
  }

  /**
   * Checks if the user needs to be redirected to WSO2 login.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function checkAuthentication(RequestEvent $event) {
    // Only act on the main request.
    if (!$event->isMainRequest()) {
      return;
    }

    // Get the current request.
    $request = $event->getRequest();

    // Get the current path.
    $current_path = $request->getPathInfo();

    // Skip redirect for these paths.
    $skip_paths = [
      '/wso2-auth/authorize',
      '/wso2-auth/callback',
      '/wso2-auth/logout',
      '/user/login',
      '/user/logout',
      '/admin',
    ];

    foreach ($skip_paths as $path) {
      if (strpos($current_path, $path) === 0) {
        return;
      }
    }

    // Get the WSO2 authentication settings.
    $config = $this->configFactory->get('wso2_auth.settings');

    // Check if WSO2 authentication is configured and enabled.
    if (!$config->get('enabled') || !$this->wso2Auth->isConfigured()) {
      return;
    }

    // Check if auto-redirect is enabled.
    if (!$config->get('auto_redirect')) {
      return;
    }

    // Check if the user is anonymous.
    if ($this->currentUser->isAnonymous()) {
      // Check if the user already has a valid WSO2 session.
      if (!$this->wso2Auth->isUserAuthenticated()) {
        // Get the current path as the destination.
        $destination = $request->getRequestUri();

        // Create the authorization URL.
        $url = Url::fromRoute('wso2_auth.authorize', ['destination' => $destination]);

        // Set the redirect response.
        $event->setResponse(new RedirectResponse($url->toString()));
      }
    }
  }
}
