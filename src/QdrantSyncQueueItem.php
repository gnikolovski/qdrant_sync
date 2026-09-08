<?php

declare(strict_types=1);

namespace Drupal\qdrant_sync;

use Drupal\Core\Entity\EntityInterface;

/**
 * Data Transfer Object for queue items.
 */
final readonly class QdrantSyncQueueItem {

  /**
   * Constructs a new QdrantSyncQueueItem instance.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $entityBundle
   *   The entity bundle.
   * @param int|string $entityId
   *   The entity ID.
   * @param string $entityUuid
   *   The entity UUID.
   */
  public function __construct(
    private string $entityType,
    private string $entityBundle,
    private int|string $entityId,
    private string $entityUuid,
  ) {}

  /**
   * Creates a DTO from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to create the DTO from.
   *
   * @return self
   *   A new DTO instance.
   */
  public static function fromEntity(EntityInterface $entity): self {
    $id = $entity->id();
    $uuid = $entity->uuid();
    if ($id === NULL || $uuid === NULL) {
      throw new \InvalidArgumentException('Only saved entities can be queued for Qdrant sync.');
    }

    return new self(
      $entity->getEntityTypeId(),
      $entity->bundle(),
      $id,
      $uuid,
    );
  }

  /**
   * Gets the entity type.
   *
   * @return string
   *   The entity type ID.
   */
  public function getEntityType(): string {
    return $this->entityType;
  }

  /**
   * Gets the entity bundle.
   *
   * @return string
   *   The entity bundle.
   */
  public function getEntityBundle(): string {
    return $this->entityBundle;
  }

  /**
   * Gets the entity ID.
   *
   * @return int|string
   *   The entity ID.
   */
  public function getEntityId(): int|string {
    return $this->entityId;
  }

  /**
   * Gets the entity UUID.
   *
   * @return string
   *   The entity UUID.
   */
  public function getEntityUuid(): string {
    return $this->entityUuid;
  }

  /**
   * Converts the DTO to an array.
   *
   * @return array<string, int|string>
   *   The DTO data as an array.
   */
  public function toArray(): array {
    return [
      'entity_type' => $this->entityType,
      'entity_bundle' => $this->entityBundle,
      'entity_id' => $this->entityId,
      'entity_uuid' => $this->entityUuid,
    ];
  }

}
