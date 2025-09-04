<?php

namespace Drupal\wso2_simply\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form di configurazione per il modulo WSO2 Simply.
 */
class Wso2SimplyConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['wso2_simply.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wso2_simply_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('wso2_simply.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Abilita WSO2 Simply'),
      '#description' => $this->t('Abilita o disabilita il modulo WSO2 Simply.'),
      '#default_value' => $config->get('enabled'),
    ];

    $form['oauth_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Impostazioni OAuth2'),
      '#open' => TRUE,
    ];

    $form['oauth_settings']['oauth_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL OAuth2'),
      '#description' => $this->t('URL base per l\'autenticazione OAuth2 (es: https://id.055055.it:9443/oauth2/authorize)'),
      '#default_value' => $config->get('oauth_url'),
      '#required' => TRUE,
    ];

    $form['oauth_settings']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#description' => $this->t('Client ID per l\'applicazione OAuth2'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];

    $form['oauth_settings']['redirect_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Redirect URI'),
      '#description' => $this->t('URI di redirect per l\'applicazione OAuth2'),
      '#default_value' => $config->get('redirect_uri'),
      '#required' => TRUE,
    ];

    $form['oauth_settings']['scope'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Scope'),
      '#description' => $this->t('Scope per la richiesta OAuth2 (es: openid)'),
      '#default_value' => $config->get('scope') ?: 'openid',
      '#required' => TRUE,
    ];

    $form['redirect_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Impostazioni Redirect'),
      '#open' => TRUE,
    ];

    $form['redirect_settings']['base_redirect_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL Base Redirect'),
      '#description' => $this->t('URL base per i redirect dopo l\'autenticazione (es: https://www.comune.sesto-fiorentino.fi.it/wso2silfi/connect/cittadino)'),
      '#default_value' => $config->get('base_redirect_url'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('wso2_simply.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('oauth_url', $form_state->getValue('oauth_url'))
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('redirect_uri', $form_state->getValue('redirect_uri'))
      ->set('scope', $form_state->getValue('scope'))
      ->set('base_redirect_url', $form_state->getValue('base_redirect_url'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
