<?php

namespace Drupal\wso2_auth_check\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure WSO2 Auth Check settings.
 */
class WSO2AuthCheckSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wso2_auth_check_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['wso2_auth_check.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('wso2_auth_check.settings');

    // Verifica disponibilità dei moduli di configurazione
    $wso2silfi_enabled = \Drupal::moduleHandler()->moduleExists('wso2silfi') &&
      \Drupal::config('wso2silfi.settings')->get('general.wso2silfi_enabled');

    $wso2_auth_enabled = \Drupal::moduleHandler()->moduleExists('wso2_auth') &&
      \Drupal::config('wso2_auth.settings')->get('enabled');

    // Mostra avviso se nessun modulo è attivo
    if (!$wso2silfi_enabled && !$wso2_auth_enabled) {
      $this->messenger()->addWarning($this->t('Nessun modulo di autenticazione WSO2 è attivo. Attiva wso2silfi o wso2_auth nelle impostazioni del sito.'));
    }
    else {
      // Mostra quale modulo verrà utilizzato
      if ($wso2_auth_enabled) {
        $this->messenger()->addStatus($this->t('Configurazione da wso2_auth - nuovo modulo (priorità più alta)'));
      }
      else {
        $this->messenger()->addStatus($this->t('Configurazione da wso2silfi'));
      }
    }

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Abilita controllo automatico autenticazione'),
      '#description' => $this->t('Se abilitato, verifica automaticamente se un utente anonimo è già autenticato presso l\'IdP.'),
      '#default_value' => $config->get('enabled') ?? FALSE,
    ];

    // Disabilita l'opzione se nessun modulo è attivo
    if (!$wso2silfi_enabled && !$wso2_auth_enabled) {
      $form['enabled']['#disabled'] = TRUE;
      $form['enabled']['#description'] .= ' ' . $this->t('Questa opzione è disabilitata finché un modulo di autenticazione WSO2 non sarà attivo.');
    }

    $form['check_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Intervallo tra i controlli (minuti)'),
      '#description' => $this->t('Tempo minimo in minuti tra due controlli consecutivi per utenti non autenticati.'),
      '#default_value' => $config->get('check_interval') ?? 0.5,
      '#min' => 0,
      '#max' => 60,
      '#step' => 0.1,
      '#required' => TRUE,
    ];

    $form['check_session_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Metodo di controllo sessione'),
      '#options' => [
        'iframe' => $this->t('Iframe tradizionale (prompt=none)'),
        'checksession' => $this->t('OIDC Session Management (checksession)'),
      ],
      '#default_value' => $config->get('check_session_method') ?? 'checksession',
      '#description' => $this->t('Scegli il metodo per verificare lo stato di autenticazione con WSO2. Il metodo checksession è raccomandato per i browser moderni.'),
    ];

    $form['check_session_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL Check Session OIDC'),
      '#description' => $this->t('URL per il controllo sessione OIDC (richiesto per il metodo checksession). Es: https://example.com/oauth2/checksession'),
      '#default_value' => $config->get('check_session_url') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="check_session_method"]' => ['value' => 'checksession'],
        ],
        'required' => [
          ':input[name="check_session_method"]' => ['value' => 'checksession'],
        ],
      ],
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Abilita modalità debug'),
      '#description' => $this->t('Se abilitato, verranno mostrati messaggi di debug dettagliati nella console del browser e nei log di Drupal.'),
      '#default_value' => $config->get('debug') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('wso2_auth_check.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('check_interval', $form_state->getValue('check_interval'))
      ->set('check_session_method', $form_state->getValue('check_session_method'))
      ->set('check_session_url', $form_state->getValue('check_session_url'))
      ->set('debug', $form_state->getValue('debug'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
