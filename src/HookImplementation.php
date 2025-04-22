<?php

declare(strict_types=1);

namespace Drupal\wso2_auth;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides hook implementations for the WSO2 Auth module.
 *
 * This class uses the new Drupal 11.1+ Hook attribute system.
 * The module also maintains the traditional hook_* functions for compatibility.
 */
class HookImplementation implements ContainerInjectionInterface {

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
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new HookImplementation object.
   *
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    WSO2AuthService $wso2_auth,
    RequestStack $request_stack,
    ModuleHandlerInterface $module_handler,
    StateInterface $state,
    AccountInterface $current_user
  ) {
    $this->wso2Auth = $wso2_auth;
    $this->requestStack = $request_stack;
    $this->moduleHandler = $module_handler;
    $this->state = $state;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wso2_auth.authentication'),
      $container->get('request_stack'),
      $container->get('module_handler'),
      $container->get('state'),
      $container->get('current_user')
    );
  }

  /**
   * Implements hook_help().
   */
  #[Hook(hook: 'help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      case 'help.page.wso2_auth':
        $output = '';
        $output .= '<h3>' . t('About') . '</h3>';
        $output .= '<p>' . t('The WSO2 Authentication module provides integration with WSO2 Identity Server for user authentication.') . '</p>';
        return $output;
    }
    return NULL;
  }

  /**
   * Implements hook_theme().
   */
  #[Hook(hook: 'theme')]
  public function theme($existing, $type, $theme, $path) {
    $module_path = $this->moduleHandler->getModule('wso2_auth')->getPath();
    return [
      'wso2_auth_block' => [
        'variables' => [
          'title' => NULL,
          'module_path' => $module_path,
          'profile' => NULL,
          'requestUri' => NULL,
        ],
        'template' => 'block--wso2-auth-login',
      ],
    ];
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   */
  #[Hook(hook: 'form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) {
    if ($form_id !== 'user_login_form') {
      return;
    }

    // Check if WSO2 authentication is configured and enabled.
    if (!$this->wso2Auth->isConfigured()) {
      return;
    }

    // Get configuration
    $config = \Drupal::config('wso2_auth.settings');

    // Add buttons for citizen and operator login
    if ($config->get('general.citizen_enabled')) {
      $form['wso2_auth_citizen'] = [
        '#type' => 'link',
        '#title' => t('Login con SPID/CIE (Cittadino)'),
        '#url' => Url::fromRoute('wso2_auth.authorize', ['type' => 'citizen']),
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'me-5', 'mb-5', 'wso2-auth-button', 'citizen-login'],
        ],
        '#weight' => -100,
      ];
    }

    // Add operator button if operator auth is enabled
    if ($config->get('operator.enabled')) {
      $form['wso2_auth_operator'] = [
        '#type' => 'link',
        '#title' => t('Login come Operatore'),
        '#url' => Url::fromRoute('wso2_auth.authorize', ['type' => 'operator']),
        '#attributes' => [
          'class' => ['btn', 'btn-danger', 'me-5', 'mb-5', 'wso2-auth-button', 'operator-login'],
        ],
        '#weight' => -99,
      ];
    }

    // Add SPID logo if enabled
    if ($config->get('picture_enabled')) {
      $form['wso2_logo'] = [
        '#markup' => '<div class="wso2-auth-logo m-2"><img src="/' . \Drupal::service('extension.list.module')->getPath('wso2_auth') . '/images/Sign-in-with-WSO2-lighter-small.png" alt="SPID Login" /></div>',
        '#weight' => -110,
      ];
    }
  }

  /**
   * Implements hook_user_logout().
   */
  #[Hook(hook: 'user_logout')]
  public function userLogout($account) {
    // Get the session.
    $session = $this->requestStack->getCurrentRequest()->getSession();

    // Check if the user has a WSO2 session.
    $wso2_session = $session->get('wso2_auth_session');
    if (!empty($wso2_session) && !empty($wso2_session['id_token'])) {
      // Store the id_token in the state service so it can be used after the user is logged out.
      $this->state->set('wso2_auth_logout_token_' . $account->id(), $wso2_session['id_token']);
    }

    // Clear the WSO2 session.
    $session->remove('wso2_auth_session');
  }

}
