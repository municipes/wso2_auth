<?php

namespace Drupal\wso2_auth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\wso2_auth\WSO2AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'CitizenBlock' block.
 *
 * @Block(
 *  id = "wso2_citizen_block",
 *  admin_label = @Translation("WSO2 Blocco login Cittadino (wso2_auth)"),
 * )
 */
class CitizenBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService
   */
  protected $wso2Auth;

  /**
   * Constructs a new CitizenBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\wso2_auth\WSO2AuthService $wso2_auth
   *   The WSO2 authentication service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, WSO2AuthService $wso2_auth) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->wso2Auth = $wso2_auth;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('wso2_auth.authentication')
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

    if (!\Drupal::currentUser()->isAnonymous()) {
      return [];
    }

    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = \Drupal::service('request_stack')->getCurrentRequest();

    return [
      '#theme' => 'wso2_auth_block',
      '#title' => 'WSO2 login Cittadino',
      '#profile' => 'citizen',
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
