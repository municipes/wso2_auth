<?php

namespace Drupal\wso2_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\wso2_auth\WSO2AuthService;

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
   * Constructor for the WSO2 authentication controller.
   *
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(WSO2AuthService $wso2_auth, RequestStack $request_stack) {
    $this->wso2Auth = $wso2_auth;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wso2_auth.authentication'),
      $container->get('request_stack')
    );
  }

  /**
   * Redirects the user to the WSO2 authorization page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the WSO2 authorization page.
   */
  public function authorize() {
    // Get the current request.
    $request = $this->requestStack->getCurrentRequest();

    // Check if WSO2 authentication is configured.
    if (!$this->wso2Auth->isConfigured()) {
      $this->messenger()->addError($this->t('WSO2 authentication is not properly configured.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Get the destination from the request if available.
    $destination = $request->query->get('destination');

    // Log the destination parameter for debugging
    if ($destination) {
      \Drupal::logger('wso2_auth')->debug('Destination parameter found in request: @destination', [
        '@destination' => $destination,
      ]);
    }

    // Generate the authorization URL.
    $url = $this->wso2Auth->getAuthorizationUrl($destination);

    // Redirect to the authorization URL.
    return new TrustedRedirectResponse($url);
  }

  /**
   * Handles the OAuth2 callback from WSO2.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response after handling the callback.
   */
  public function callback() {
    // Get the current request.
    $request = $this->requestStack->getCurrentRequest();

    // Get the authorization code and state from the request.
    $code = $request->query->get('code');
    $state = $request->query->get('state');

    // Get the session.
    $session = $request->getSession();

    // Clear the auto-login attempt flag so we can try again later if needed
    $session->remove('wso2_auth_auto_login_attempt');

    // Check if the code and state are available.
    if (empty($code) || empty($state)) {
      $this->messenger()->addError($this->t('Invalid authorization response.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Verify the state parameter.
    if (!$this->wso2Auth->verifyState($state)) {
      $this->messenger()->addError($this->t('Invalid state parameter.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Exchange the authorization code for tokens.
    $tokens = $this->wso2Auth->getTokens($code);
    if (!$tokens) {
      $this->messenger()->addError($this->t('Failed to get access token.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Get the user info.
    $user_info = $this->wso2Auth->getUserInfo($tokens['access_token']);
    if (!$user_info) {
      $this->messenger()->addError($this->t('Failed to get user information.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Authenticate the user.
    $account = $this->wso2Auth->authenticateUser($user_info);
    if (!$account) {
      $this->messenger()->addError($this->t('Authentication failed.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Store token information in the session.
    $session->set('wso2_auth_session', [
      'access_token' => $tokens['access_token'],
      'refresh_token' => $tokens['refresh_token'],
      'id_token' => $tokens['id_token'],
      'expires' => time() + $tokens['expires_in'],
    ]);

    // Get the destination from the session.
    $destination = $session->get('wso2_auth_destination');
    $session->remove('wso2_auth_destination');

    // Reset the auto-login checked status so the system knows we're already logged in
    $session->set('wso2_auth_auto_login_checked', TRUE);
    $session->set('wso2_auth_last_check_time', time());

    // Debug log
    if ($this->config('wso2_auth.settings')->get('debug')) {
      $this->getLogger('wso2_auth')->debug('Successful login for @username. Redirecting to: @destination', [
        '@username' => $account->getAccountName(),
        '@destination' => $destination ?? 'home page',
      ]);
    }

    // Redirect to the destination or the front page.
    if (!empty($destination)) {
      // Check if this is an internal path or an external URL
      if (strpos($destination, '/') === 0) {
        // It's an internal path, let's handle it safely
        return new RedirectResponse($destination);
      }
      else {
        // Try to handle as a Drupal route
        try {
          $url = Url::fromUserInput($destination)->toString();
          return new RedirectResponse($url);
        }
        catch (\Exception $e) {
          // If there's an error, just redirect to the URL directly
          return new RedirectResponse($destination);
        }
      }
    }

    return new RedirectResponse(Url::fromRoute('<front>')->toString());
  }

  /**
   * Logs the user out from WSO2 and Drupal.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response after logout.
   */
  public function logout() {
    // Get the current request.
    $request = $this->requestStack->getCurrentRequest();

    // Get the session.
    $session = $request->getSession();

    // Check if the user has a WSO2 session.
    $wso2_session = $session->get('wso2_auth_session');
    if (empty($wso2_session) || empty($wso2_session['id_token'])) {
      // Just log out from Drupal if no WSO2 session exists.
      user_logout();
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Get the ID token.
    $id_token = $wso2_session['id_token'];

    // Remove the WSO2 session.
    $session->remove('wso2_auth_session');

    // Get the logout URL.
    $logout_url = $this->wso2Auth->getLogoutUrl($id_token, Url::fromRoute('<front>')->setAbsolute()->toString());

    // Log out from Drupal.
    user_logout();

    // Redirect to the WSO2 logout URL.
    return new TrustedRedirectResponse($logout_url);
  }
}
