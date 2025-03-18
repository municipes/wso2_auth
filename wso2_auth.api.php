<?php

/**
 * @file
 * Hooks provided by the WSO2 Authentication module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the user data received from WSO2 before authentication.
 *
 * This hook allows modules to modify the user data received from WSO2
 * before it is used to authenticate or register the user in Drupal.
 *
 * @param array $user_data
 *   The user data received from WSO2.
 */
function hook_wso2_auth_userinfo_alter(array &$user_data) {
  // Normalize email addresses to lowercase.
  if (isset($user_data['email'])) {
    $user_data['email'] = strtolower($user_data['email']);
  }
}

/**
 * React to a successful WSO2 authentication.
 *
 * This hook is invoked after a user has been successfully authenticated
 * with WSO2 and logged into Drupal.
 *
 * @param \Drupal\user\UserInterface $account
 *   The user account that was authenticated.
 * @param array $user_data
 *   The user data received from WSO2.
 */
function hook_wso2_auth_post_login($account, array $user_data) {
  // Log successful authentication.
  \Drupal::logger('mymodule')->notice('User @name authenticated via WSO2.', [
    '@name' => $account->getAccountName(),
  ]);

  // Check if the user has specific roles in WSO2.
  if (isset($user_data['roles']) && is_array($user_data['roles'])) {
    // Map WSO2 roles to Drupal roles.
    $role_mapping = [
      'admin' => 'administrator',
      'editor' => 'editor',
      'contributor' => 'contributor',
    ];

    foreach ($user_data['roles'] as $wso2_role) {
      if (isset($role_mapping[$wso2_role]) && !$account->hasRole($role_mapping[$wso2_role])) {
        $account->addRole($role_mapping[$wso2_role]);
      }
    }

    // Save the account if roles were added.
    if ($account->isNew()) {
      $account->save();
    }
  }
}

/**
 * Alter the authorization URL used for WSO2 authentication.
 *
 * This hook allows modules to modify the URL that users are redirected to
 * for WSO2 authentication.
 *
 * @param string $url
 *   The authorization URL.
 * @param array $params
 *   The parameters used to build the URL.
 */
function hook_wso2_auth_authorization_url_alter(&$url, array $params) {
  // Add additional parameters to the URL.
  $url .= '&custom_param=custom_value';
}

/**
 * Alter the token request parameters.
 *
 * This hook allows modules to modify the parameters sent when requesting
 * an access token from WSO2.
 *
 * @param array $params
 *   The parameters to be sent in the token request.
 * @param string $code
 *   The authorization code received from WSO2.
 */
function hook_wso2_auth_token_request_alter(array &$params, $code) {
  // Add additional parameters to the token request.
  $params['custom_param'] = 'custom_value';
}

/**
 * Alter the user information request.
 *
 * This hook allows modules to modify the request sent to WSO2 to get
 * user information.
 *
 * @param array $options
 *   The options to be sent in the request.
 * @param string $access_token
 *   The access token received from WSO2.
 */
function hook_wso2_auth_userinfo_request_alter(array &$options, $access_token) {
  // Add additional headers to the request.
  $options['headers']['X-Custom-Header'] = 'custom_value';
}

/**
 * Alter the logout URL used for WSO2 authentication.
 *
 * This hook allows modules to modify the URL that users are redirected to
 * for WSO2 logout.
 *
 * @param string $url
 *   The logout URL.
 * @param array $params
 *   The parameters used to build the URL.
 */
function hook_wso2_auth_logout_url_alter(&$url, array $params) {
  // Add additional parameters to the URL.
  $url .= '&custom_param=custom_value';
}

/**
 * @} End of "addtogroup hooks".
 */
