<?php

namespace Drupal\Tests\wso2_auth\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the functionality of the WSO2 Authentication module.
 *
 * @group wso2_auth
 */
class WSO2AuthTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'wso2_auth',
    'externalauth',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A test user with permission to administer WSO2 authentication.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create and log in an admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer wso2 authentication',
      'access administration pages',
    ]);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Tests the WSO2 authentication settings form.
   */
  public function testSettingsForm() {
    // Access the settings form.
    $this->drupalGet('admin/config/people/wso2-auth');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('WSO2 Authentication Settings');

    // Test form submission.
    $edit = [
      'enabled' => TRUE,
      'server_settings[auth_server_url]' => 'https://id.055055.it:9443/oauth2',
      'server_settings[ag_entity_id]' => 'FIRENZE',
      'server_settings[client_id]' => 'xxxxxxxxxxxxxxxxxxxxxx',
      'server_settings[client_secret]' => 'xxxxxxxxxxxxxxxxxxxxxx',
      'server_settings[redirect_uri]' => $this->buildUrl('wso2-auth/callback'),
      'server_settings[scope]' => 'openid',
      'user_settings[auto_register]' => TRUE,
      'field_mapping[mapping][user_id]' => 'sub',
      'field_mapping[mapping][username]' => 'email',
      'field_mapping[mapping][email]' => 'email',
      'field_mapping[mapping][first_name]' => 'given_name',
      'field_mapping[mapping][last_name]' => 'family_name',
      'advanced[auto_redirect]' => FALSE,
      'advanced[debug]' => TRUE,
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Verify the saved configuration.
    $config = $this->config('wso2_auth.settings');
    $this->assertEquals(TRUE, $config->get('enabled'));
    $this->assertEquals('https://id.055055.it:9443/oauth2', $config->get('auth_server_url'));
    $this->assertEquals('xxxxxxxxxxxxxxxxxxxxxx', $config->get('client_id'));
    $this->assertEquals('FIRENZE', $config->get('ag_entity_id'));
    $this->assertEquals(TRUE, $config->get('debug'));
  }

  /**
   * Tests the WSO2 authentication login button.
   */
  public function testLoginButton() {
    // Configure the module.
    $this->config('wso2_auth.settings')
      ->set('enabled', TRUE)
      ->set('auth_server_url', 'https://id.055055.it:9443/oauth2')
      ->set('client_id', 'xxxxxxxxxxxxxxxxxxxxxx')
      ->set('client_secret', 'xxxxxxxxxxxxxxxxxxxxxx')
      ->set('redirect_uri', $this->buildUrl('wso2-auth/callback'))
      ->save();

    // Log out and check the login page.
    $this->drupalLogout();
    $this->drupalGet('user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Log in with WSO2');
    $this->assertSession()->linkExists('Log in with WSO2');

    // Check that the link points to the authorize route.
    $authorize_url = $this->getAbsoluteUrl('wso2-auth/authorize');
    $this->assertSession()->linkByHrefExists($authorize_url);
  }

  /**
   * Tests the WSO2 authorization route.
   */
  public function testAuthorizeRoute() {
    // Configure the module.
    $this->config('wso2_auth.settings')
      ->set('enabled', TRUE)
      ->set('auth_server_url', 'https://id.055055.it:9443/oauth2')
      ->set('ag_entity_id', 'FIRENZE')
      ->set('client_id', 'xxxxxxxxxxxxxxxxxxxxxx')
      ->set('client_secret', 'xxxxxxxxxxxxxxxxxxxxxx')
      ->set('redirect_uri', $this->buildUrl('wso2-auth/callback'))
      ->save();

    // Access the authorize route.
    $this->drupalGet('wso2-auth/authorize');
    $this->assertSession()->statusCodeEquals(302);

    // Get the redirect header.
    $redirect_url = $this->getSession()->getResponseHeader('Location');

    // Since we can't actually test the external WSO2 server in a unit test,
    // we just check that the redirect URL contains the expected parameters.
    $this->assertStringContainsString('https://id.055055.it:9443/oauth2', $redirect_url);
    $this->assertStringContainsString('client_id=xxxxxxxxxxxxxxxxxxxxxx', $redirect_url);
    $this->assertStringContainsString('response_type=code', $redirect_url);
    $this->assertStringContainsString('scope=openid', $redirect_url);
    $this->assertStringContainsString('agEntityId=FIRENZE', $redirect_url);
    $this->assertStringContainsString('state=', $redirect_url);
  }
}
