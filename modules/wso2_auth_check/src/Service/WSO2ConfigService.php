<?php

namespace Drupal\wso2_auth_check\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\wso2_auth\Helper\WSO2EnvironmentHelper;

/**
 * Service per la gestione della configurazione WSO2.
 */
class WSO2ConfigService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The environment helper.
   *
   * @var \Drupal\wso2_auth\Helper\WSO2EnvironmentHelper
   */
  protected $environmentHelper;

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   La factory per le configurazioni.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Il gestore dei moduli.
   * @param \Drupal\wso2_auth\Helper\WSO2EnvironmentHelper $environment_helper
   *   The environment helper.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, WSO2EnvironmentHelper $environment_helper = NULL) {
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->environmentHelper = $environment_helper ?: new WSO2EnvironmentHelper($config_factory);
  }

  /**
   * Verifica se il controllo automatico è abilitato.
   *
   * @return bool
   *   TRUE se il controllo automatico è abilitato.
   */
  public function isEnabled() {
    // Verifica se il modulo wso2_auth_check è abilitato.
    $check_config = $this->configFactory->get('wso2_auth_check.settings');
    return (bool) $check_config->get('enabled');
  }

  /**
   * Verifica se almeno un modulo di configurazione WSO2 è attivo.
   *
   * @return bool
   *   TRUE se almeno un modulo è attivo.
   */
  public function hasActiveAuthModule() {
    $wso2silfi_enabled = $this->moduleHandler->moduleExists('wso2silfi') &&
      $this->configFactory->get('wso2silfi.settings')->get('general.wso2silfi_enabled');

    $wso2_auth_enabled = $this->moduleHandler->moduleExists('wso2_auth') &&
      $this->configFactory->get('wso2_auth.settings')->get('enabled');

    return $wso2_auth_enabled || $wso2silfi_enabled;
  }

  /**
   * Verifica se la modalità debug è abilitata.
   *
   * @return bool
   *   TRUE se la modalità debug è abilitata.
   */
  public function isDebugEnabled() {
    return (bool) $this->configFactory->get('wso2_auth_check.settings')->get('debug');
  }

  /**
   * Logga un messaggio di debug se la modalità debug è abilitata.
   *
   * @param string $message
   *   Il messaggio da loggare.
   * @param array $context
   *   Il contesto del messaggio.
   */
  public function debug($message, array $context = []) {
    if ($this->isDebugEnabled()) {
      \Drupal::logger('wso2_auth_check')->debug($message, ['@context' => $context]);
    }
  }

  /**
   * Ottiene la configurazione combinata da wso2silfi o wso2_auth.
   *
   * @return array
   *   Array con le configurazioni.
   */
  public function getConfig() {
    $config = [
      'idpUrl' => '',
      'clientId' => '',
      'redirectUri' => \Drupal::request()->getSchemeAndHttpHost() . '/wso2-auth-callback',
      'loginPath' => '',  // Sarà impostato in base al modulo attivo
      'checkInterval' => $this->configFactory->get('wso2_auth_check.settings')->get('check_interval') ?? 3,
      'debug' => $this->isDebugEnabled(),
      'checkSessionMethod' => $this->configFactory->get('wso2_auth_check.settings')->get('check_session_method') ?? 'iframe',
    ];

    // Usa wso2_auth se disponibile.
    if ($this->moduleHandler->moduleExists('wso2_auth')) {
      $auth_config = $this->configFactory->get('wso2_auth.settings');
      // Ottieni URL e endpoint del server di autenticazione
      $auth_server_url = $this->environmentHelper->getAuthServerUrl();
      $auth_endpoint = $this->environmentHelper->getAuthEndpoint();
      $full_auth_url = $auth_server_url . $auth_endpoint;
      $check_session_url = $this->environmentHelper->getCheckSessionUrl();

      if ($auth_config->get('enabled')) {
        $config['idpUrl'] = $full_auth_url;
        $config['checkSessionUrl'] = $check_session_url; // Aggiungi l'URL per il controllo sessione
        $config['redirectUri'] = \Drupal::request()->getSchemeAndHttpHost() . '/wso2-auth-callback';
        $config['clientId'] = $auth_config->get('citizen.client_id');
        $config['loginPath'] = '/wso2-auth/authorize/citizen';
        return $config;
      }
    }

    // Verifica e usa wso2silfi se disponibile e wso2_auth non è abilitato.
    if ($this->moduleHandler->moduleExists('wso2silfi')) {
      $silfi_config = $this->configFactory->get('wso2silfi.settings');
      if ($silfi_config->get('general.wso2silfi_enabled')) {
        $server_url = 'https://id.055055.it:9443';
        if ($silfi_config->get('general.stage')) {
          $server_url = 'https://id-staging.055055.it:9443';
        }

        $authorize = '/oauth2/authorize';
        $config['idpUrl'] = rtrim($server_url, '/') . $authorize;
        $config['checkSessionUrl'] = rtrim($server_url, '/') . '/oidc/checksession'; // Aggiungi URL checksession
        $config['redirectUri'] = \Drupal::request()->getSchemeAndHttpHost() . '/oauth2/authorized';
        $config['clientId'] = $silfi_config->get('citizen.client_id');
        $config['loginPath'] = '/wso2silfi/connect/cittadino';
        return $config;
      }
    }

    // Fallback path se nessun modulo è configurato
    $config['loginPath'] = '/user/login';

    return $config;
  }

}
