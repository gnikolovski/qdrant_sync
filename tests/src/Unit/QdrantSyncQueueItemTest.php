<?php

declare(strict_types=1);

namespace Drupal\Tests\qdrant_sync\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\qdrant_sync\QdrantSyncQueueItem;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the queue item that carries an entity reference through the queue.
 */
#[CoversClass(QdrantSyncQueueItem::class)]
#[Group('qdrant_sync')]
final class QdrantSyncQueueItemTest extends UnitTestCase {

  /**
   * An item built from an entity copies its type, bundle, id and UUID.
   */
  public function testFromEntityCopiesTheEntityIdentity(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('article');
    $entity->method('id')->willReturn('5');
    $entity->method('uuid')->willReturn('uuid-1');

    $item = QdrantSyncQueueItem::fromEntity($entity);

    $this->assertSame('node', $item->getEntityType());
    $this->assertSame('article', $item->getEntityBundle());
    $this->assertSame('5', $item->getEntityId());
    $this->assertSame('uuid-1', $item->getEntityUuid());
  }

  /**
   * The array form keeps the keys the queue workers and logs rely on.
   */
  public function testToArrayKeepsTheIdentityKeys(): void {
    $item = new QdrantSyncQueueItem('taxonomy_term', 'tags', 7, 'uuid-7');

    $this->assertSame([
      'entity_type' => 'taxonomy_term',
      'entity_bundle' => 'tags',
      'entity_id' => 7,
      'entity_uuid' => 'uuid-7',
    ], $item->toArray());
  }

}
