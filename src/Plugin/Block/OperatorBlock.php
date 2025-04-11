<?php

namespace Drupal\wso2_auth\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wso2_auth\WSO2AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'OperatorBlock' block.
 *
 * @Block(
 *  id = "wso2_operator_block",
 *  admin_label = @Translation("WSO2 Blocco login Operatore (wso2_auth)"),
 * )
 */
#[Block(
  id: "wso2_operator_block",
  admin_label: new TranslatableMarkup("WSO2 Blocco login Operatore (wso2_auth)")
)]
class OperatorBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;
  
  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new OperatorBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    WSO2AuthService $wso2_auth,
    RequestStack $request_stack,
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->wso2Auth = $wso2_auth;
    $this->requestStack = $request_stack;
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('wso2_auth.authentication'),
      $container->get('request_stack'),
      $container->get('config.factory'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Usa il servizio iniettato per verificare se WSO2 è configurato
    if (!$this->wso2Auth->isConfigured()) {
      return [];
    }

    // Per l'operatore dovresti controllare anche se l'autenticazione operatore è abilitata
    $config = $this->configFactory->get('wso2_auth.settings');
    if (!$config->get('operator.enabled')) {
      return [];
    }

    if (!$this->currentUser->isAnonymous()) {
      return [];
    }

    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = $this->requestStack->getCurrentRequest();

    return [
      '#theme' => 'wso2_auth_block',
      '#title' => 'WSO2 login Operatore',
      '#profile' => 'operator',
      '#requestUri' => \rawurlencode($request->getRequestUri()),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }
}
