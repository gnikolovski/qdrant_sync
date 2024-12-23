<?php

declare(strict_types=1);

namespace Drupal\qdrant_sync\Plugin\EntityDataBuilder;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for entity Data builder plugins.
 */
interface EntityDataBuilderInterface {

  /**
   * Build data representation of the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to build data for.
   *
   * @return array
   *   Structured array.
   */
  public function build(ContentEntityInterface $entity): array;

}
