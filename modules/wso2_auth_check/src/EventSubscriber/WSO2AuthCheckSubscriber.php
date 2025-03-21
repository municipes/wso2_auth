<?php

namespace Drupal\wso2_auth_check\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * WSO2 Auth Check event subscriber.
 */
class WSO2AuthCheckSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   L'utente corrente.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   La factory per le configurazioni.
   */
  public function __construct(AccountInterface $current_user, ConfigFactoryInterface $config_factory) {
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
  }

  /**
   * Processa l'evento request.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   L'evento della richiesta.
   */
  public function onRequest(RequestEvent $event) {
    // Esegui solo sulle richieste master e per utenti anonimi.
    if (!$event->isMainRequest() || !$this->currentUser->isAnonymous()) {
      return;
    }

    // Verifica se il modulo è abilitato.
    $config = $this->configFactory->get('wso2_auth_check.settings');
    if (!$config->get('enable_auto_login')) {
      return;
    }

    // Il resto della logica può essere esteso qui se necessario.
    // Ad esempio, potremmo impostare un cookie o una variabile di sessione
    // per tenere traccia dei tentativi di autenticazione.
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['onRequest'];
    return $events;
  }

}
