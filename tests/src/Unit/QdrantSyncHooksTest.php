<?php

declare(strict_types=1);

namespace Drupal\Tests\qdrant_sync\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\qdrant_sync\EntityDataBuilderManager;
use Drupal\node\NodeInterface;
use Drupal\qdrant_sync\QdrantSyncQueueItem;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which entity events queue an upsert, a delete, or nothing.
 */
#[CoversFunction('qdrant_sync_entity_insert')]
#[CoversFunction('qdrant_sync_entity_update')]
#[CoversFunction('qdrant_sync_entity_delete')]
#[CoversFunction('qdrant_sync_should_sync_entity')]
#[CoversFunction('qdrant_sync_add_to_queue')]
#[Group('qdrant_sync')]
final class QdrantSyncHooksTest extends UnitTestCase {

  private const array DEFINITIONS = [
    'article_builder' => ['id' => 'article_builder', 'entity_type' => 'node', 'entity_bundle' => 'article'],
    'page_builder' => ['id' => 'page_builder', 'entity_type' => 'node', 'entity_bundle' => 'page'],
  ];

  /**
   * The items queued per queue name.
   *
   * @var array<string, \Drupal\qdrant_sync\QdrantSyncQueueItem[]>
   */
  private array $queued = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../qdrant_sync.module';

    // The article builder is switched on, the page builder exists but is off.
    $config_factory = $this->getConfigFactoryStub([
      'qdrant_sync.settings' => [
        'content_sync' => ['enabled_builders' => ['article_builder' => TRUE, 'page_builder' => FALSE]],
      ],
    ]);

    $manager = $this->getMockBuilder(EntityDataBuilderManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getDefinition'])
      ->getMock();
    $manager->method('getDefinition')->willReturnCallback(fn (string $plugin_id, bool $exception_on_invalid): array|false => self::DEFINITIONS[$plugin_id] ?? FALSE);

    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->method('get')->willReturnCallback(function (string $name): QueueInterface {
      $queue = $this->createMock(QueueInterface::class);
      $queue->method('createItem')->willReturnCallback(function (QdrantSyncQueueItem $item) use ($name): int {
        $this->queued[$name][] = $item;
        return count($this->queued[$name]);
      });
      return $queue;
    });

    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    $container->set('plugin.manager.entity_data_builder', $manager);
    $container->set('queue', $queue_factory);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a node of the given bundle and publishing state.
   *
   * @param string $bundle
   *   The node bundle.
   * @param bool $published
   *   Whether the node is published.
   * @param \Drupal\node\NodeInterface|null $original
   *   The node as it was before the update, for update hooks.
   */
  private function createNode(string $bundle, bool $published, ?NodeInterface $original = NULL): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('bundle')->willReturn($bundle);
    $node->method('id')->willReturn(5);
    $node->method('uuid')->willReturn('uuid-5');
    $node->method('isPublished')->willReturn($published);
    $node->method('getOriginal')->willReturn($original);
    return $node;
  }

  /**
   * Asserts exactly one item for node 5 sits in the given queue and no other.
   */
  private function assertOnlyQueued(string $queue_name): void {
    $this->assertSame([$queue_name], array_keys($this->queued));
    $this->assertCount(1, $this->queued[$queue_name]);
    $this->assertSame([
      'entity_type' => 'node',
      'entity_bundle' => 'article',
      'entity_id' => 5,
      'entity_uuid' => 'uuid-5',
    ], $this->queued[$queue_name][0]->toArray());
  }

  /**
   * A new published article is queued for upsert.
   */
  public function testInsertOfPublishedArticleQueuesAnUpsert(): void {
    qdrant_sync_entity_insert($this->createNode('article', TRUE));

    $this->assertOnlyQueued('qdrant_sync_upsert');
  }

  /**
   * A new draft is not synced.
   */
  public function testInsertOfUnpublishedArticleQueuesNothing(): void {
    qdrant_sync_entity_insert($this->createNode('article', FALSE));

    $this->assertSame([], $this->queued);
  }

  /**
   * A bundle whose builder is switched off is not synced.
   */
  public function testInsertOfBundleWithDisabledBuilderQueuesNothing(): void {
    qdrant_sync_entity_insert($this->createNode('page', TRUE));

    $this->assertSame([], $this->queued);
  }

  /**
   * Entities without a publishing state are ignored.
   */
  public function testInsertOfUnpublishableEntityQueuesNothing(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->expects($this->never())->method('getEntityTypeId');

    qdrant_sync_entity_insert($entity);

    $this->assertSame([], $this->queued);
  }

  /**
   * Saving a published article again refreshes it in Qdrant.
   */
  public function testUpdateOfPublishedArticleQueuesAnUpsert(): void {
    $node = $this->createNode('article', TRUE, $this->createNode('article', TRUE));

    qdrant_sync_entity_update($node);

    $this->assertOnlyQueued('qdrant_sync_upsert');
  }

  /**
   * Unpublishing removes the article from Qdrant.
   */
  public function testUnpublishingQueuesDelete(): void {
    $node = $this->createNode('article', FALSE, $this->createNode('article', TRUE));

    qdrant_sync_entity_update($node);

    $this->assertOnlyQueued('qdrant_sync_delete');
  }

  /**
   * Editing a draft that stays a draft touches nothing.
   */
  public function testUpdateOfDraftThatStaysDraftQueuesNothing(): void {
    $node = $this->createNode('article', FALSE, $this->createNode('article', FALSE));

    qdrant_sync_entity_update($node);

    $this->assertSame([], $this->queued);
  }

  /**
   * Publishing a draft for the first time queues an upsert.
   */
  public function testPublishingDraftQueuesUpsert(): void {
    $node = $this->createNode('article', TRUE, $this->createNode('article', FALSE));

    qdrant_sync_entity_update($node);

    $this->assertOnlyQueued('qdrant_sync_upsert');
  }

  /**
   * Deleting a published article removes it from Qdrant.
   */
  public function testDeleteOfPublishedArticleQueuesDelete(): void {
    qdrant_sync_entity_delete($this->createNode('article', TRUE));

    $this->assertOnlyQueued('qdrant_sync_delete');
  }

  /**
   * Deleting a draft has nothing to remove.
   */
  public function testDeleteOfUnpublishedArticleQueuesNothing(): void {
    qdrant_sync_entity_delete($this->createNode('article', FALSE));

    $this->assertSame([], $this->queued);
  }

}
