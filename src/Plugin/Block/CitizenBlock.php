<?php

namespace Drupal\wso2_auth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\wso2_auth\WSO2AuthService;

/**
 * Provides a 'CitizenBlock' block.
 *
 * @Block(
 *  id = "wso2_citizen_block",
 *  admin_label = @Translation("WSO2 Blocco login Cittadino (wso2_auth)"),
 * )
 */
class CitizenBlock extends BlockBase {

  /**
   * The WSO2 authentication service.
   *
   * @var \Drupal\wso2_auth\WSO2AuthService
   */
  protected $wso2Auth;

  /**
   * {@inheritdoc}
   */
  public function build() {
    if (!\Drupal::config('wso2_auth.settings')->get('enabled')) {
      return;
    }

    if (!\Drupal::currentUser()->isAnonymous()) {
      return;
    }

    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = \Drupal::service('request_stack')->getCurrentRequest();
    // $fullUrl = $request->getSchemeAndHttpHost() . $request->getRequestUri();

    return [
      '#theme' => 'wso2_auth_block',
      '#title' => 'WSO2 login Cittadino',
      '#profile' => 'cittadino',
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
