<?php

declare(strict_types=1);

namespace Drupal\qdrant_sync\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The Block attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class EntityDataBuilder extends Plugin {

  /**
   * Constructs a Block attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable name of the plugin.
   * @param string $entity_type
   *   The entity type this plugin supports.
   * @param string $entity_bundle
   *   The entity bundle this plugin supports.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $entity_type,
    public readonly string $entity_bundle,
  ) {}

}
