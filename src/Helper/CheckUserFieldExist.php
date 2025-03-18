<?php

namespace Drupal\wso2_auth\Helper;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Helper class to check if user fields exist.
 */
class CheckUserFieldExist {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected static $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected static $entityTypeManager;

  /**
   * Sets the entity field manager.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public static function setEntityFieldManager(EntityFieldManagerInterface $entityFieldManager) {
    static::$entityFieldManager = $entityFieldManager;
  }

  /**
   * Sets the entity type manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public static function setEntityTypeManager(EntityTypeManagerInterface $entityTypeManager) {
    static::$entityTypeManager = $entityTypeManager;
  }

  /**
   * Check if a user field exists.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field exists, FALSE otherwise.
   */
  public static function exist($field_name) {
    if (!static::$entityFieldManager) {
      static::$entityFieldManager = \Drupal::service('entity_field.manager');
    }

    $fields = static::$entityFieldManager->getFieldDefinitions('user', 'user');
    return isset($fields[$field_name]);
  }

  /**
   * Check if a field exists in any entity type and bundle.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field exists, FALSE otherwise.
   */
  public static function existInAnyEntity($field_name) {
    if (!static::$entityTypeManager) {
      static::$entityTypeManager = \Drupal::service('entity_type.manager');
    }

    if (!static::$entityFieldManager) {
      static::$entityFieldManager = \Drupal::service('entity_field.manager');
    }

    try {
      $field_storage = static::$entityTypeManager->getStorage('field_storage_config')->load('user.' . $field_name);
      return (bool) $field_storage;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

}
