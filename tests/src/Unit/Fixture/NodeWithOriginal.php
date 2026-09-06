<?php

declare(strict_types=1);

namespace Drupal\Tests\qdrant_sync\Unit\Fixture;

use Drupal\node\NodeInterface;

/**
 * A node double that declares the magic 'original' property.
 *
 * The hooks read $entity->original, which a real entity serves through
 * __get(). Declaring it here lets a mock carry a previous revision without
 * PHP 8.2's dynamic property deprecation.
 */
abstract class NodeWithOriginal implements NodeInterface {

  /**
   * The entity as it was before the update, if any.
   */
  public ?NodeInterface $original = NULL;

}
