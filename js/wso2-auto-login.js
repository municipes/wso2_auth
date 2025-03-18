/**
 * @file
 * JavaScript for WSO2 Auto Login using Vanilla JS (no jQuery).
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Behavior for WSO2 Auto Login.
   */
  Drupal.behaviors.wso2AutoLogin = {
    attach: function (context, settings) {
      // Only run once on page load using once
      if (once('wso2-auto-login', 'body', context).length) {
        // Only check for anonymous users (not logged in yet)
        if (drupalSettings.user && drupalSettings.user.uid === 0) {
          // Check if we're already in a redirect process (to avoid loops)
          if (sessionStorage.getItem('wso2_auth_redirect_in_progress') === 'true') {
            console.log('WSO2 redirect already in progress, skipping check');
            // Clear the flag after a while (in case something went wrong)
            setTimeout(function() {
              sessionStorage.removeItem('wso2_auth_redirect_in_progress');
            }, 5000);
            return;
          }

          // Check the session status
          checkSessionStatus();
        } else {
          console.log('User is already authenticated in Drupal, skipping WSO2 session check');
        }
      }

      /**
       * Check if the user has an active session with WSO2.
       */
      function checkSessionStatus() {
        // If settings are not defined, exit
        if (!drupalSettings.wso2_auth) {
          console.log('WSO2 Auth settings not found');
          return;
        }

        // Get the check session URL from Drupal settings
        const checkSessionUrl = drupalSettings.wso2_auth.checkSessionUrl;
        const loginUrl = drupalSettings.wso2_auth.loginUrl;

        console.log('Checking WSO2 session status...');

        // Use fetch API instead of jQuery AJAX
        fetch(checkSessionUrl, {
          method: 'GET',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin'
        })
        .then(function(response) {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(function(response) {
          // If the user is authenticated with WSO2 but not with Drupal,
          // redirect to the login URL
          if (response.authenticated) {
            console.log('WSO2 session found. Redirecting to login.');
            // Add a session storage flag before redirecting
            sessionStorage.setItem('wso2_auth_redirect_in_progress', 'true');
            window.location.href = loginUrl;
          }
          else {
            console.log('No WSO2 session found. No redirection needed.');
            // Clear the session storage flag
            sessionStorage.removeItem('wso2_auth_redirect_in_progress');
          }
        })
        .catch(function(error) {
          console.error('Error checking WSO2 session:', error);
          // Clear the session storage flag on error
          sessionStorage.removeItem('wso2_auth_redirect_in_progress');
        });
      }
    }
  };

})(Drupal, drupalSettings, once);
