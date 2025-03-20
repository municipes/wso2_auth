<?php

namespace Drupal\Tests\wso2_auth_check\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wso2_auth\WSO2AuthService;
use Drupal\wso2_auth_check\WSO2SessionCheckHelper;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @coversDefaultClass \Drupal\wso2_auth_check\WSO2SessionCheckHelper
 * @group wso2_auth_check
 */
class WSO2SessionCheckHelperTest extends UnitTestCase {

  /**
   * The session check helper.
   *
   * @var \Drupal\wso2_auth_check\WSO2SessionCheckHelper
   */
  protected $helper;

  /**
   * The mocked WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $wso2Auth;

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked session.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $session;

  /**
   * The mocked configuration object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The mocked WSO2 configuration object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $wso2Config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up mocks.
    $this->wso2Auth = $this->createMock(WSO2AuthService::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->session = $this->createMock(SessionInterface::class);

    // Set up configuration.
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->wso2Config = $this->createMock(ImmutableConfig::class);

    // Configure the config factory to return our configs.
    $this->configFactory->method('get')
      ->willReturnMap([
        ['wso2_auth_check.settings', $this->config],
        ['wso2_auth.settings', $this->wso2Config],
      ]);

    // Create the helper.
    $this->helper = new WSO2SessionCheckHelper(
      $this->wso2Auth,
      $this->currentUser,
      $this->configFactory,
      $this->logger
    );
  }

  /**
   * @covers ::isEnabled
   */
  public function testIsEnabled() {
    // Test when everything is enabled.
    $this->config->method('get')
      ->with('enabled')
      ->willReturn(TRUE);
    $this->wso2Config->method('get')
      ->with('enabled')
      ->willReturn(TRUE);
    $this->wso2Auth->method('isConfigured')
      ->willReturn(TRUE);

    $this->assertTrue($this->helper->isEnabled());

    // Reset mocks.
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->wso2Config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->willReturnMap([
        ['wso2_auth_check.settings', $this->config],
        ['wso2_auth.settings', $this->wso2Config],
      ]);

    // Test when module is disabled.
    $this->config->method('get')
      ->with('enabled')
      ->willReturn(FALSE);
    $this->wso2Config->method('get')
      ->with('enabled')
      ->willReturn(TRUE);
    $this->wso2Auth->method('isConfigured')
      ->willReturn(TRUE);

    $this->helper = new WSO2SessionCheckHelper(
      $this->wso2Auth,
      $this->currentUser,
      $this->configFactory,
      $this->logger
    );

    $this->assertFalse($this->helper->isEnabled());
  }

  /**
   * @covers ::isPathExcluded
   */
  public function testIsPathExcluded() {
    // Define test cases.
    $testCases = [
      // Path, user excluded paths, expected result.
      ['/wso2-auth/authorize', '', TRUE],
      ['/wso2-auth/callback', '', TRUE],
      ['/user/login', '', TRUE],
      ['/admin/config', '', TRUE],
      ['/node/1', '', FALSE],
      ['/node/1', "/node/1\n/node/2", TRUE],
      ['/node/12', "/node/1\n/node/2", FALSE],
      ['/custom/path', "/custom", TRUE],
    ];

    foreach ($testCases as $testCase) {
      list($path, $userExcludedPaths, $expected) = $testCase;

      // Configure the mock.
      $this->config->method('get')
        ->with('excluded_paths')
        ->willReturn($userExcludedPaths);

      // Test the method.
      $this->assertEquals($expected, $this->helper->isPathExcluded($path));
    }
  }

  /**
   * @covers ::shouldCheckSession
   */
  public function testShouldCheckSession() {
    // Define test cases.
    $testCases = [
      // checked, redirect_in_progress, check_every_page, last_check, check_interval, expected.
      [FALSE, FALSE, FALSE, 0, 300, TRUE],
      [TRUE, FALSE, FALSE, 0, 300, FALSE],
      [TRUE, FALSE, TRUE, time() - 400, 300, TRUE],
      [TRUE, FALSE, TRUE, time() - 200, 300, FALSE],
      [FALSE, TRUE, FALSE, 0, 300, FALSE],
    ];

    foreach ($testCases as $testCase) {
      list($checked, $redirectInProgress, $checkEveryPage, $lastCheck, $checkInterval, $expected) = $testCase;

      // Reset mocks.
      $this->config = $this->createMock(ImmutableConfig::class);
      $this->session = $this->createMock(SessionInterface::class);

      // Configure the mocks.
      $sessionGetMap = [
        ['wso2_auth_check.checked', $checked],
        ['wso2_auth_check.redirect_in_progress', $redirectInProgress],
        ['wso2_auth_check.last_check', $lastCheck],
      ];
      $this->session->method('get')
        ->willReturnMap($sessionGetMap);

      $configGetMap = [
        ['check_every_page', $checkEveryPage],
        ['check_interval', $checkInterval],
      ];
      $this->config->method('get')
        ->willReturnMap($configGetMap);

      $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
      $this->configFactory->method('get')
        ->willReturnMap([
          ['wso2_auth_check.settings', $this->config],
        ]);

      // Create a new helper with our mocks.
      $helper = new WSO2SessionCheckHelper(
        $this->wso2Auth,
        $this->currentUser,
        $this->configFactory,
        $this->logger
      );

      // Test the method.
      $this->assertEquals($expected, $helper->shouldCheckSession($this->session));
    }
  }

  /**
   * @covers ::markSessionChecked
   */
  public function testMarkSessionChecked() {
    // Configure the mock.
    $this->session->expects($this->exactly(2))
      ->method('set')
      ->withConsecutive(
        ['wso2_auth_check.checked', TRUE],
        ['wso2_auth_check.last_check', $this->anything()]
      );

    // Test the method.
    $this->helper->markSessionChecked($this->session);
  }

  /**
   * @covers ::markRedirectInProgress
   */
  public function testMarkRedirectInProgress() {
    // Test setting to true.
    $this->session->expects($this->once())
      ->method('set')
      ->with('wso2_auth_check.redirect_in_progress', TRUE);

    $this->helper->markRedirectInProgress($this->session);

    // Reset mock.
    $this->session = $this->createMock(SessionInterface::class);

    // Test setting to false.
    $this->session->expects($this->once())
      ->method('remove')
      ->with('wso2_auth_check.redirect_in_progress');

    $this->helper->markRedirectInProgress($this->session, FALSE);
  }

  /**
   * @covers ::resetSessionFlags
   */
  public function testResetSessionFlags() {
    // Configure the mock.
    $this->session->expects($this->exactly(3))
      ->method('remove')
      ->withConsecutive(
        ['wso2_auth_check.checked'],
        ['wso2_auth_check.last_check'],
        ['wso2_auth_check.redirect_in_progress']
      );

    // Test the method.
    $this->helper->resetSessionFlags($this->session);
  }

}
