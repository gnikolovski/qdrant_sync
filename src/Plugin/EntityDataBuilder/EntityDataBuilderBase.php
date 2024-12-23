<?php

declare(strict_types=1);

namespace Drupal\qdrant_sync\Plugin\EntityDataBuilder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for entity Data builder plugins.
 */
abstract class EntityDataBuilderBase extends PluginBase implements EntityDataBuilderInterface {

  /**
   * {@inheritdoc}
   */
  public function build(ContentEntityInterface $entity): array {
    return [];
  }

}
