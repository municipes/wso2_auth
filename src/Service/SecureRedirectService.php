<?php

namespace Drupal\wso2_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;

/**
 * Service for handling secure redirects with domain whitelist.
 */
class SecureRedirectService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelInterface $logger) {
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Get the list of whitelisted domains.
   *
   * @return array
   *   Array of whitelisted domains.
   */
  public function getWhitelistedDomains(): array {
    $config = $this->configFactory->get('wso2_auth.settings');
    $whitelist_text = $config->get('external_domains_whitelist') ?? '';

    if (empty($whitelist_text)) {
      return [];
    }

    // Processa la lista: rimuovi spazi, righe vuote e normalizza
    $domains = array_filter(
      array_map('trim', explode("\n", $whitelist_text)),
      function($domain) {
        return !empty($domain) && $this->isValidDomain($domain);
      }
    );

    return array_values($domains);
  }

  /**
   * Validate if a domain string is valid.
   *
   * @param string $domain
   *   The domain to validate.
   *
   * @return bool
   *   TRUE if valid domain.
   */
  protected function isValidDomain(string $domain): bool {
    // Rimuovi protocollo se presente
    $domain = preg_replace('/^https?:\/\//', '', $domain);

    // Rimuovi path se presente
    $domain = parse_url('http://' . $domain, PHP_URL_HOST);

    // Valida il formato del dominio
    return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== FALSE;
  }

  /**
   * Check if a URL is safe for redirect.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is safe for redirect.
   */
  public function isUrlSafeForRedirect(string $url): bool {
    // Se è un percorso relativo, è sempre sicuro
    if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
      return TRUE;
    }

    // Se non è un URL valido, non è sicuro
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return FALSE;
    }

    $parsed_url = parse_url($url);

    // Se non ha host, potrebbe essere relativo - sicuro
    if (!isset($parsed_url['host'])) {
      return TRUE;
    }

    $host = $parsed_url['host'];

    // Controlla se il dominio è nella whitelist
    $whitelisted_domains = $this->getWhitelistedDomains();

    foreach ($whitelisted_domains as $allowed_domain) {
      // Normalizza il dominio consentito
      $normalized_allowed = preg_replace('/^https?:\/\//', '', $allowed_domain);
      $normalized_allowed = parse_url('http://' . $normalized_allowed, PHP_URL_HOST);

      // Confronto esatto o sottodominio
      if ($host === $normalized_allowed ||
          str_ends_with($host, '.' . $normalized_allowed)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Generate a safe redirect URL.
   *
   * @param string $destination
   *   The desired destination.
   * @param string $fallback_route
   *   The fallback route if destination is not safe.
   *
   * @return string
   *   A safe URL for redirect.
   */
  public function getSafeRedirectUrl(string $destination, string $fallback_route = '<front>'): string {
    $config = $this->configFactory->get('wso2_auth.settings');
    $debug = $config->get('debug');

    if (empty($destination)) {
      return Url::fromRoute($fallback_route)->setAbsolute()->toString();
    }

    // Se è un URL sicuro, elaboralo
    if ($this->isUrlSafeForRedirect($destination)) {

      // Se è un percorso interno, usa Drupal URL
      if (strpos($destination, '/') === 0 && strpos($destination, '//') !== 0) {
        try {
          return Url::fromUserInput($destination)->setAbsolute()->toString();
        } catch (\Exception $e) {
          if ($debug) {
            $this->logger->warning('WSO2 Auth SecureRedirect: Error processing internal path @dest: @error', [
              '@dest' => $destination,
              '@error' => $e->getMessage(),
            ]);
          }
          return Url::fromRoute($fallback_route)->setAbsolute()->toString();
        }
      }

      // Se è un URL esterno whitelistato, usalo direttamente
      if ($debug) {
        $this->logger->debug('WSO2 Auth SecureRedirect: External URL @dest is whitelisted', [
          '@dest' => $destination,
        ]);
      }

      return $destination;
    }

    // URL non sicuro, usa fallback
    if ($debug) {
      $this->logger->warning('WSO2 Auth SecureRedirect: URL @dest not in whitelist, using fallback', [
        '@dest' => $destination,
      ]);
    }

    return Url::fromRoute($fallback_route)->setAbsolute()->toString();
  }

}
