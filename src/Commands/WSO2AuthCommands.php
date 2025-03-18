<?php

namespace Drupal\wso2_auth\Commands;

use Drupal\wso2_auth\WSO2AuthService;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drush\Drush;

/**
 * Drush commands for the WSO2 Authentication module.
 */
class WSO2AuthCommands extends DrushCommands {

  /**
   * The WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService
   */
  protected $wso2Auth;

  /**
   * Constructs a WSO2AuthCommands object.
   *
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   */
  public function __construct(WSO2AuthService $wso2_auth) {
    parent::__construct();
    $this->wso2Auth = $wso2_auth;
  }

  /**
   * Creates a WSO2AuthCommands instance.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   *
   * @return static
   *   The commands instance.
   */
  public static function createInstance(ContainerInterface $container) {
    return new static(
      $container->get('wso2_auth.authentication')
    );
  }

  /**
   * Verifies the WSO2 authentication configuration.
   *
   * @command wso2:verify-config
   * @aliases wso2-verify
   * @usage drush wso2:verify-config
   *   Verifies the WSO2 authentication configuration.
   */
  public function verifyConfig() {
    if (!$this->wso2Auth->isConfigured()) {
      $this->logger()->error('WSO2 authentication is not properly configured.');
      return;
    }

    $this->logger()->success('WSO2 authentication is properly configured.');

    // Get the configuration.
    $config = \Drupal::config('wso2_auth.settings');

    // Print the configuration.
    $this->output()->writeln('Configuration:');
    $this->output()->writeln('- Enabled: ' . ($config->get('enabled') ? 'Yes' : 'No'));
    $this->output()->writeln('- Authentication Server URL: ' . $config->get('auth_server_url'));
    $this->output()->writeln('- Entity ID: ' . $config->get('ag_entity_id'));
    $this->output()->writeln('- Client ID: ' . $config->get('client_id'));
    $this->output()->writeln('- Redirect URI: ' . $config->get('redirect_uri'));
    $this->output()->writeln('- Scope: ' . $config->get('scope'));
    $this->output()->writeln('- Auto-register users: ' . ($config->get('auto_register') ? 'Yes' : 'No'));
    $this->output()->writeln('- Auto-redirect to WSO2 login: ' . ($config->get('auto_redirect') ? 'Yes' : 'No'));
    $this->output()->writeln('- Debug mode: ' . ($config->get('debug') ? 'Yes' : 'No'));
  }

  /**
   * Generates an authorization URL for testing.
   *
   * @command wso2:generate-auth-url
   * @aliases wso2-auth-url
   * @usage drush wso2:generate-auth-url
   *   Generates an authorization URL for testing.
   */
  public function generateAuthUrl() {
    if (!$this->wso2Auth->isConfigured()) {
      $this->logger()->error('WSO2 authentication is not properly configured.');
      return;
    }

    $url = $this->wso2Auth->getAuthorizationUrl();
    $this->output()->writeln('Authorization URL:');
    $this->output()->writeln($url);
  }

  /**
   * Lists users authenticated with WSO2.
   *
   * @command wso2:list-users
   * @aliases wso2-users
   * @usage drush wso2:list-users
   *   Lists users authenticated with WSO2.
   */
  public function listUsers() {
    // Get the database connection.
    $database = \Drupal::database();

    // Query the authmap table for WSO2 users.
    $query = $database->select('authmap', 'a');
    $query->fields('a', ['uid', 'authname']);
    $query->condition('a.provider', 'wso2_auth');
    $query->join('users_field_data', 'u', 'a.uid = u.uid');
    $query->fields('u', ['name', 'mail', 'status', 'created']);
    $query->orderBy('u.created', 'DESC');
    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $this->logger()->notice('No users have authenticated with WSO2.');
      return;
    }

    // Prepare the table rows.
    $rows = [];
    foreach ($results as $result) {
      $rows[] = [
        $result->uid,
        $result->name,
        $result->mail,
        $result->authname,
        $result->status ? 'Active' : 'Blocked',
        date('Y-m-d H:i:s', $result->created),
      ];
    }

    // Display the table.
    $this->output()->writeln('WSO2 authenticated users:');
    $this->io()->table(
      ['UID', 'Name', 'Email', 'WSO2 ID', 'Status', 'Created'],
      $rows
    );
  }

  /**
   * Checks if a user is authenticated with WSO2.
   *
   * @param string $name
   *   The username.
   *
   * @command wso2:check-user
   * @aliases wso2-check
   * @usage drush wso2:check-user USERNAME
   *   Checks if a user is authenticated with WSO2.
   */
  public function checkUser($name) {
    // Get the user by name.
    $user = user_load_by_name($name);
    if (!$user) {
      $this->logger()->error('User not found: @name', ['@name' => $name]);
      return;
    }

    // Check if the user is authenticated with WSO2.
    $database = \Drupal::database();
    $query = $database->select('authmap', 'a');
    $query->fields('a', ['authname']);
    $query->condition('a.provider', 'wso2_auth');
    $query->condition('a.uid', $user->id());
    $authname = $query->execute()->fetchField();

    if (!$authname) {
      $this->logger()->notice('User @name is not authenticated with WSO2.', ['@name' => $name]);
      return;
    }

    $this->logger()->success('User @name is authenticated with WSO2.', ['@name' => $name]);
    $this->output()->writeln('WSO2 ID: ' . $authname);
  }

  /**
   * Clears the WSO2 session data.
   *
   * @command wso2:clear-session
   * @aliases wso2-clear
   * @usage drush wso2:clear-session
   *   Clears the WSO2 session data.
   */
  public function clearSession() {
    // Clear the WSO2 session state.
    \Drupal::state()->delete('wso2_auth.last_check');

    // Log the action.
    $this->logger()->success('WSO2 session data cleared.');
  }

}
