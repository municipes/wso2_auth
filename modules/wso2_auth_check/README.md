# WSO2 Authentication Check

## Overview

The WSO2 Authentication Check module is a submodule for the WSO2 Authentication module that provides automatic session detection and transparent login for users who have already authenticated with WSO2 Identity Server on other sites.

## Functionality

This module enables the following scenario:
- If a user has authenticated with the WSO2 Identity Provider on a third-party site
- When they arrive on your Drupal site, they will be automatically logged in without any user intervention
- The process is completely transparent to the user
- No automatic login happens if the user has no active WSO2 session

## Requirements

- Drupal 10 or 11
- [WSO2 Authentication](https://github.com/municipes/wso2_auth) module

## Installation

1. Download and install the module as you would any Drupal module:

```bash
composer require municipes/wso2_auth_check
drush en wso2_auth_check
```

2. Configure the module settings at Administration » Configuration » People » WSO2 Authentication Settings » Session Check Settings.

## Configuration

The module provides the following configuration options:

- **Enable automatic session check**: Master switch to enable or disable the automatic session detection.
- **Check on every page load**: If enabled, the system will check on every page load if the check interval has passed. If disabled, it will only check once per session.
- **Session check interval**: Minimum time in seconds between session checks (only applies if checking on every page load).
- **Excluded paths**: Paths to exclude from automatic session checking.

## How it works

The module uses an event subscriber that runs on each page request to detect if:

1. The user is anonymous (not logged in to Drupal)
2. The user has an active session with WSO2 Identity Provider
3. The current request is not a path that should be excluded

If all conditions are met, the module checks for an active WSO2 session by making a silent authentication request to the WSO2 Identity Server with the `prompt=none` parameter. This parameter tells the IdP to:

- Return a code if the user is already authenticated
- Return an error message if authentication is required

If the silent check indicates the user is authenticated, the module redirects to the WSO2 authentication process, passing the current path as the destination. After successful authentication, the user is redirected back to the original page they were trying to access.

## Performance considerations

The module is designed to minimize performance impact:
- It only checks for anonymous users
- It can be configured to check only once per session
- It allows setting a minimum interval between checks
- It skips AJAX requests and POST requests
- It allows excluding specific paths
- It uses the `prompt=none` parameter for non-intrusive checks

## API and hooks

The module provides several hooks and API functions for extending functionality:

```php
/**
 * Alter the list of paths excluded from session checking.
 */
function hook_wso2_auth_check_paths_alter(array &$paths) {
  // Add custom excluded paths.
  $paths[] = '/my/custom/path';
}

/**
 * Alter whether a session check should be performed.
 */
function hook_wso2_auth_check_should_check_alter(&$should_check, $session) {
  // Add custom logic for determining if a check should be performed.
  if (\Drupal::state()->get('my_module.disable_wso2_checks')) {
    $should_check = FALSE;
  }
}

/**
 * React to a successful session verification and login.
 */
function hook_wso2_auth_callback_alter(array &$context) {
  // The $context array contains account, tokens, user_info, and session.
  \Drupal::logger('my_module')->info('User @name auto-logged in', [
    '@name' => $context['account']->getAccountName(),
  ]);
}
```

## Troubleshooting

If you encounter issues with the automatic login:

1. Check if the WSO2 Authentication module is properly configured and working
2. Verify that the user has an active session with WSO2 Identity Provider
3. Check the Drupal logs for any errors related to the module
4. Try adjusting the excluded paths if specific pages cause issues
5. Enable the debug mode in the WSO2 Authentication module to see detailed log messages

## Security considerations

The module does not store any sensitive information. It simply detects if a user has an active WSO2 session and initiates the standard authentication flow if needed.

- It can be configured to check only once per session
- It allows setting a minimum interval between checks
- It skips AJAX requests and POST requests
- It allows excluding specific paths
- It uses the `prompt=none` parameter for non-intrusive checks

## API and hooks

The module provides several hooks and API functions for extending functionality:

```php
/**
 * Alter the list of paths excluded from session checking.
 */
function hook_wso2_auth_check_paths_alter(array &$paths) {
  // Add custom excluded paths.
  $paths[] = '/my/custom/path';
}

/**
 * Alter whether a session check should be performed.
 */
function hook_wso2_auth_check_should_check_alter(&$should_check, $session) {
  // Add custom logic for determining if a check should be performed.
  if (\Drupal::state()->get('my_module.disable_wso2_checks')) {
    $should_check = FALSE;
  }
}

/**
 * React to a successful session verification and login.
 */
function hook_wso2_auth_callback_alter(array &$context) {
  // The $context array contains account, tokens, user_info, and session.
  \Drupal::logger('my_module')->info('User @name auto-logged in', [
    '@name' => $context['account']->getAccountName(),
  ]);
}
```

## Troubleshooting

If you encounter issues with the automatic login:

1. Check if the WSO2 Authentication module is properly configured and working
2. Verify that the user has an active session with WSO2 Identity Provider
3. Check the Drupal logs for any errors related to the module
4. Try adjusting the excluded paths if specific pages cause issues
5. Enable the debug mode in the WSO2 Authentication module to see detailed log messages

## Security considerations

The module does not store any sensitive information. It simply detects if a user has an active WSO2 session and initiates the standard authentication flow if needed.


- It can be configured to check only once per session
- It allows setting a minimum interval between checks
- It skips AJAX requests and POST requests
- It allows excluding specific paths

## Troubleshooting

If you encounter issues with the automatic login:

1. Check if the WSO2 Authentication module is properly configured and working
2. Verify that the user has an active session with WSO2 Identity Provider
3. Check the Drupal logs for any errors related to the module
4. Try adjusting the excluded paths if specific pages cause issues

## Security considerations

The module does not store any sensitive information. It simply detects if a user has an active WSO2 session and initiates the standard authentication flow if needed.
