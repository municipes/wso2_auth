# Extending the WSO2 Authentication Module

This document provides guidance on how to extend and customize the WSO2 Authentication module for more advanced use cases.

## Table of Contents

1. [Altering the Authentication Process](#altering-the-authentication-process)
2. [Adding Custom User Fields](#adding-custom-user-fields)
3. [Integrating with Other Modules](#integrating-with-other-modules)
4. [Creating Custom Event Subscribers](#creating-custom-event-subscribers)
5. [Modifying the WSO2 Login Flow](#modifying-the-wso2-login-flow)

## Altering the Authentication Process

You can use Drupal's hook system to alter how the WSO2 Authentication module handles the authentication process.

### Example: Altering the User Data Before Authentication

```php
/**
 * Implements hook_wso2_auth_userinfo_alter().
 */
function mymodule_wso2_auth_userinfo_alter(array &$user_data) {
  // Modify the user data as needed.
  if (isset($user_data['email'])) {
    // Transform email to lowercase.
    $user_data['email'] = strtolower($user_data['email']);
  }

  // Add additional fields.
  $user_data['custom_field'] = 'custom value';
}
```

### Example: Reacting to Successful Authentication

```php
/**
 * Implements hook_wso2_auth_post_login().
 */
function mymodule_wso2_auth_post_login($account, array $user_data) {
  // Do something after the user has been authenticated.
  \Drupal::logger('mymodule')->notice('User @name logged in via WSO2.', [
    '@name' => $account->getAccountName(),
  ]);

  // Update user roles based on WSO2 data.
  if (isset($user_data['roles']) && in_array('admin', $user_data['roles'])) {
    $account->addRole('administrator');
    $account->save();
  }
}
```

## Adding Custom User Fields

If you need to store additional WSO2 user data in Drupal user fields, you can create custom fields and map them in your custom module.

### Example: Adding and Mapping Custom Fields

1. First, create the custom fields in your module's install or update hook:

```php
/**
 * Implements hook_install().
 */
function mymodule_install() {
  // Create a custom field for storing the WSO2 user ID.
  $field_storage = FieldStorageConfig::create([
    'field_name' => 'field_wso2_user_id',
    'entity_type' => 'user',
    'type' => 'string',
    'settings' => [],
    'cardinality' => 1,
  ]);
  $field_storage->save();

  // Create the field instance.
  $field = FieldConfig::create([
    'field_storage' => $field_storage,
    'bundle' => 'user',
    'label' => 'WSO2 User ID',
    'description' => 'Stores the WSO2 user ID.',
  ]);
  $field->save();
}
```

2. Then, update the fields when users authenticate:

```php
/**
 * Implements hook_wso2_auth_post_login().
 */
function mymodule_wso2_auth_post_login($account, array $user_data) {
  if (isset($user_data['sub'])) {
    $account->set('field_wso2_user_id', $user_data['sub']);
    $account->save();
  }
}
```

## Integrating with Other Modules

The WSO2 Authentication module can be integrated with other Drupal modules to enhance functionality.

### Example: Integration with Group Module

```php
/**
 * Implements hook_wso2_auth_post_login().
 */
function mymodule_wso2_auth_post_login($account, array $user_data) {
  // Check if the Group module is enabled.
  if (\Drupal::moduleHandler()->moduleExists('group')) {
    // If the WSO2 user has a department attribute, add them to the corresponding group.
    if (isset($user_data['department'])) {
      $department = $user_data['department'];

      // Load groups with matching department name.
      $groups = \Drupal::entityTypeManager()
        ->getStorage('group')
        ->loadByProperties(['field_department' => $department]);

      if (!empty($groups)) {
        $group = reset($groups);
        // Add the user to the group if they're not already a member.
        if (!$group->getMember($account)) {
          $group->addMember($account);
        }
      }
    }
  }
}
```

## Creating Custom Event Subscribers

You can create custom event subscribers to react to various events in the authentication process.

### Example: Custom Event Subscriber

1. Create a custom event subscriber class:

```php
namespace Drupal\mymodule\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Custom event subscriber for WSO2 authentication.
 */
class MyModuleWSO2AuthSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['checkUserPermissions', 100],
    ];
  }

  /**
   * Checks user permissions after WSO2 authentication.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function checkUserPermissions(RequestEvent $event) {
    // Only act on the main request.
    if (!$event->isMainRequest()) {
      return;
    }

    // Check if the user is authenticated.
    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    // Get the current request.
    $request = $event->getRequest();

    // Check if this is a response from WSO2 authentication.
    if ($request->getPathInfo() === '/wso2-auth/callback') {
      // Do something special after WSO2 authentication.
      \Drupal::logger('mymodule')->notice('User authenticated via WSO2.');
    }
  }
}
```

2. Register the event subscriber in your module's services.yml file:

```yaml
services:
  mymodule.wso2_auth_subscriber:
    class: Drupal\mymodule\EventSubscriber\MyModuleWSO2AuthSubscriber
    arguments: ['@current_user']
    tags:
      - { name: event_subscriber }
```

## Modifying the WSO2 Login Flow

You can modify the WSO2 login flow by overriding the default controller or creating your own routes.

### Example: Custom Controller Extending the Default Controller

```php
namespace Drupal\mymodule\Controller;

use Drupal\Core\Url;
use Drupal\wso2_auth\Controller\WSO2AuthController;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Custom controller for WSO2 authentication.
 */
class CustomWSO2AuthController extends WSO2AuthController {

  /**
   * Custom authorize method with additional parameters.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function customAuthorize() {
    // Get the current request.
    $request = $this->requestStack->getCurrentRequest();

    // Check if WSO2 authentication is configured.
    if (!$this->wso2Auth->isConfigured()) {
      $this->messenger()->addError($this->t('WSO2 authentication is not properly configured.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Get the destination from the request if available.
    $destination = $request->query->get('destination');

    // Add custom logic here.
    $custom_param = $request->query->get('custom_param');
    if ($custom_param) {
      // Store the custom parameter in the session for later use.
      $request->getSession()->set('wso2_auth_custom_param', $custom_param);
    }

    // Generate the authorization URL.
    $url = $this->wso2Auth->getAuthorizationUrl($destination);

    // Redirect to the authorization URL.
    return new TrustedRedirectResponse($url);
  }
}
```

Then register the custom route in your module's routing.yml file:

```yaml
mymodule.wso2_auth_custom_authorize:
  path: '/wso2-auth/custom-authorize'
  defaults:
    _controller: '\Drupal\mymodule\Controller\CustomWSO2AuthController::customAuthorize'
    _title: 'Custom Authorize with WSO2'
  requirements:
    _access: 'TRUE'
```

This document provides a starting point for extending the WSO2 Authentication module. For more advanced customizations, you may need to override or extend the core services and classes of the module.
