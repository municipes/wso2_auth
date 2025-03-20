<?php

namespace Drupal\wso2_auth_check\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Path\PathAliasManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\wso2_auth\WSO2AuthService;
use Drupal\wso2_auth_check\WSO2SessionCheckHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber for checking WSO2 session status.
 */
class WSO2SessionCheckSubscriber implements EventSubscriberInterface {

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
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * The path alias manager.
   *
   * @var \Drupal\Core\Path\PathAliasManagerInterface
   */
  protected $pathAliasManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The WSO2 session check helper.
   *
   * @var \Drupal\wso2_auth_check\WSO2SessionCheckHelper
   */
  protected $sessionCheckHelper;

  /**
   * Constructs a new WSO2SessionCheckSubscriber.
   *
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path.
   * @param \Drupal\Core\Path\PathAliasManagerInterface $path_alias_manager
   *   The path alias manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\wso2_auth_check\WSO2SessionCheckHelper $session_check_helper
   *   The WSO2 session check helper.
   */
  public function __construct(
    WSO2AuthService $wso2_auth,
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory,
    CurrentPathStack $current_path,
    PathAliasManagerInterface $path_alias_manager,
    LoggerChannelInterface $logger,
    MessengerInterface $messenger,
    RequestStack $request_stack,
    WSO2SessionCheckHelper $session_check_helper
  ) {
    $this->wso2Auth = $wso2_auth;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->currentPath = $current_path;
    $this->pathAliasManager = $path_alias_manager;
    $this->logger = $logger;
    $this->messenger = $messenger;
    $this->requestStack = $request_stack;
    $this->sessionCheckHelper = $session_check_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // High priority to run before other subscribers.
    return [
      KernelEvents::REQUEST => ['checkSession', 280],
    ];
  }

  /**
   * Checks if the user already has a WSO2 session and logs them in if needed.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function checkSession(RequestEvent $event) {
    // Only act on the main request.
    if (!$event->isMainRequest()) {
      return;
    }

    // Skip if module is not enabled or WSO2 Auth is not configured.
    if (!$this->sessionCheckHelper->isEnabled()) {
      return;
    }

    // Skip for authenticated users.
    if (!$this->currentUser->isAnonymous()) {
      return;
    }

    // Get the current path and request.
    $request = $this->requestStack->getCurrentRequest();
    $current_path = $this->currentPath->getPath();

    // Skip for excluded paths.
    if ($this->sessionCheckHelper->isPathExcluded($current_path)) {
      return;
    }

    // Don't check for AJAX requests.
    if ($request->isXmlHttpRequest()) {
      return;
    }

    // Don't check POST requests.
    if ($request->isMethod('POST')) {
      return;
    }

    // Get session and check timing constraints.
    $session = $request->getSession();
    if (!$this->sessionCheckHelper->shouldCheckSession($session)) {
      return;
    }

    // Mark that we've checked the session.
    $this->sessionCheckHelper->markSessionChecked($session);

    // Get the current URL for the redirect back
    $current_url = $request->getRequestUri();

    // Perform a silent check to see if the user has a WSO2 session
    $has_session = $this->sessionCheckHelper->checkWSO2SessionSilently($current_url);

    // Debug log.
    $wso2_config = $this->configFactory->get('wso2_auth.settings');
    if ($wso2_config->get('debug')) {
      $this->logger->debug('Silent WSO2 session check result for path @path: @result', [
        '@path' => $current_path,
        '@result' => $has_session === TRUE ? 'authenticated' : ($has_session === FALSE ? 'not authenticated' : 'undetermined'),
      ]);
    }

    // If the user has a WSO2 session, initiate the authentication.
    if ($has_session === TRUE) {
      // Mark that we're in a redirect process.
      $this->sessionCheckHelper->markRedirectInProgress($session);

      // Initiate the authorization flow, passing the current path as destination.
      $url = $this->wso2Auth->getAuthorizationUrl($request->getRequestUri());

      // Set the response to redirect to the WSO2 login.
      $event->setResponse(new RedirectResponse($url));
    }
  }
}
