<?php

namespace Drupal\wso2_auth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\wso2_auth\WSO2AuthService;

/**
 * Provides a 'OperatorBlock' block.
 *
 * @Block(
 *  id = "wso2_operator_block",
 *  admin_label = @Translation("WSO2 Blocco login Operatore (wso2_auth)"),
 * )
 */
class OperatorBlock extends BlockBase {

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
      '#title' => 'WSO2 login Operatore',
      '#profile' => 'operatore',
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
