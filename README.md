# WSO2 Authentication

## Overview

The WSO2 Authentication module provides integration with WSO2 Identity Server for Drupal user authentication using OAuth2. It allows users to log in to your Drupal site using their WSO2 identity, with specific support for the SPID (Sistema Pubblico di Identità Digitale) authentication system.

## Features

- OAuth2 authentication with WSO2 Identity Server
- Support for both staging and production environments
- Automatic user registration
- Field mapping between WSO2 and Drupal user fields
- Mapping of custom fields like fiscal code and mobile phone
- Automatic role assignment for new users
- Single sign-on (SSO) capability
- Single logout (SLO) capability
- Integration with the Drupal login form
- Session validation and security checks
- SSL verification skip option for development environments

## Requirements

- Drupal 10 or 11
- [External Auth](https://www.drupal.org/project/externalauth) module
- WSO2 Identity Server with OAuth2 support

## Installation

1. Download and install the module as you would any Drupal module:

```bash
composer require municipes/wso2_auth
drush en wso2_auth
```

2. Configure the module settings at Administration » Configuration » People » WSO2 Authentication Settings (/admin/config/people/wso2-auth).

## Configuration

### WSO2 Identity Server Configuration

Before configuring the module, you need to set up an OAuth2 application in your WSO2 Identity Server:

1. Login to the WSO2 Identity Server management console.
2. Register a new OAuth2 application with the following settings:
   - Callback URL: `https://yourdrupal.site/wso2-auth/callback`
   - Grant Types: Authorization Code
   - Required Scopes: openid

3. Note the Client ID and Client Secret provided by WSO2.

### Module Configuration

1. Go to Administration » Configuration » People » WSO2 Authentication Settings (/admin/config/people/wso2-auth).
2. Enable the WSO2 Authentication module.
3. Choose whether to use staging or production environment.
4. Enter the WSO2 server information:
   - Authentication Server URL: The URL of your WSO2 Identity Server OAuth2 endpoint (e.g., https://id.055055.it:9443/oauth2)
   - Entity ID (agEntityId): The entity ID to use for authentication (e.g., FIRENZE)
   - Client ID: The client ID from your WSO2 application
   - Client Secret: The client secret from your WSO2 application
   - Redirect URI: The callback URL for your Drupal site (e.g., https://yourdrupal.site/wso2-auth/callback)
   - Scope: The required scope for authentication (e.g., openid)

5. Configure user settings:
   - Auto-register users: Automatically register new users when they authenticate with WSO2
   - Display SPID logo on login form: Enable or disable the SPID logo on the login form
   - Role to assign: The role to assign to newly registered users
   - Roles to check: Roles to check when a user logs in (users with these roles won't get the default role)

6. Configure field mappings:
   - Map WSO2 fields to Drupal user fields
   - Configure fiscal code and mobile phone field mappings if available

7. Configure advanced settings:
   - Auto-redirect to WSO2 login: Automatically redirect anonymous users to the WSO2 login page
   - Enable debug mode: Log additional information for debugging
   - Skip SSL verification: Skip SSL certificate verification (for development only)

8. Save the configuration.

## Usage

### User Login

Once configured, users can log in to your Drupal site using their WSO2 credentials in two ways:

1. Through the standard Drupal login form, which will include a "Log in with WSO2" button.
2. By visiting the `/wso2-auth/authorize` path, which will redirect them to the WSO2 login page.

### User Logout

When users log out of your Drupal site, they will also be logged out of their WSO2 session if they click the "Log out" link or visit the `/wso2-auth/logout` path.

## Extending the Module

The module provides several hooks that allow other modules to alter its behavior:

- `hook_wso2_auth_userinfo_alter(&$user_data)`: Alter user data received from WSO2 before authentication.
- `hook_wso2_auth_post_login($account, $user_data)`: React to a successful authentication.
- `hook_wso2_auth_authorization_url_alter(&$url, $params)`: Alter the authorization URL.
- `hook_wso2_auth_token_request_alter(&$params, $code)`: Alter token request parameters.
- `hook_wso2_auth_userinfo_request_alter(&$options, $access_token)`: Alter user info request.
- `hook_wso2_auth_logout_url_alter(&$url, $params)`: Alter the logout URL.

See `wso2_auth.api.php` for more information.

## Troubleshooting

If you encounter issues with the module, try the following:

1. Enable debug mode in the module settings to get more information in the Drupal logs.
2. Check the Drupal logs for errors (Administration » Reports » Recent log messages).
3. Verify that your WSO2 Identity Server is properly configured and accessible.
4. Ensure the callback URL in WSO2 matches the redirect URI configured in the module settings.
5. Check that the client ID and client secret are correct.
6. Try enabling the "Skip SSL verification" option if you're having SSL-related issues in a development environment.

## Security Considerations

- Always use HTTPS for your Drupal site and WSO2 Identity Server to prevent man-in-the-middle attacks.
- Regularly update the module and its dependencies to ensure you have the latest security patches.
- The module implements state validation to prevent CSRF attacks during the authentication process.
- Consider enabling auto-logout to ensure users are fully logged out from both systems.
- Disable "Skip SSL verification" in production environments.

## API

The module provides services that can be used by other modules:

```php
// Get the WSO2 Auth service
$wso2_auth = \Drupal::service('wso2_auth.authentication');

// Check if WSO2 authentication is configured
if ($wso2_auth->isConfigured()) {
  // Do something...
}

// Check if the user is authenticated with WSO2
if ($wso2_auth->isUserAuthenticated()) {
  // Do something...
}

// Get the environment helper service
$env_helper = \Drupal::service('wso2_auth.environment_helper');

// Check if using staging environment
if ($env_helper->isStaging()) {
  // Do something...
}
```

## Credits

This module was developed based on the OAuth2 protocol and WSO2 Identity Server integration specifications.
