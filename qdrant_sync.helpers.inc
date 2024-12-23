<?php

/**
 * @file
 * Provides helper functions for the Qdrant sync module.
 */

declare(strict_types=1);

use Drupal\Core\Entity\EntityInterface;
use Drupal\qdrant_sync\QdrantSyncQueueItem;

/**
 * Checks if an entity should be synced based on configuration.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity to check.
 *
 * @return bool
 *   TRUE if the entity should be synced, FALSE otherwise.
 */
function qdrant_sync_should_sync_entity(EntityInterface $entity): bool {
  $config = \Drupal::config('qdrant_sync.settings');
  $enabled_builders = $config->get('content_sync.enabled_builders') ?? [];

  $entity_type = $entity->getEntityTypeId();
  $entity_bundle = $entity->bundle();

  // Check if any builder is enabled for this entity type + bundle combination.
  foreach ($enabled_builders as $plugin_id => $enabled) {
    $definition = \Drupal::service('plugin.manager.entity_data_builder')
      ->getDefinition($plugin_id, FALSE);

    if (
      $definition &&
      $enabled &&
      $definition['entity_type'] === $entity_type &&
      $definition['entity_bundle'] === $entity_bundle) {
      return TRUE;
    }
  }

  return FALSE;
}

/**
 * Adds an entity to the specified queue.
 *
 * Creates a queue item for syncing entity data with Qdrant, but only if an
 * identical item isn't already queued. This prevents duplicate processing
 * of the same entity.
 *
 * @param string $queue_name
 *   The name of the queue to use.
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity to queue for processing.
 */
function qdrant_sync_add_to_queue(string $queue_name, EntityInterface $entity): void {
  /** @var \Drupal\Core\Queue\QueueFactoryInterface $queue_factory */
  $queue_factory = \Drupal::service('queue');
  $queue = $queue_factory->get($queue_name);
  $item = QdrantSyncQueueItem::fromEntity($entity);
  $queue->createItem($item);
}
