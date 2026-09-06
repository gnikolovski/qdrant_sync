<?php

declare(strict_types=1);

namespace Drupal\Tests\qdrant_sync\Unit;

use Drupal\qdrant_sync\EntityDataBuilderManager;
use Drupal\qdrant_sync\Plugin\EntityDataBuilder\EntityDataBuilderInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the lookup of a data builder plugin by entity type and bundle.
 */
#[CoversClass(EntityDataBuilderManager::class)]
#[Group('qdrant_sync')]
final class EntityDataBuilderManagerTest extends UnitTestCase {

  private const array DEFINITIONS = [
    'article_builder' => ['id' => 'article_builder', 'entity_type' => 'node', 'entity_bundle' => 'article'],
    'tags_builder' => ['id' => 'tags_builder', 'entity_type' => 'taxonomy_term', 'entity_bundle' => 'tags'],
  ];

  /**
   * Builds the manager with canned plugin definitions.
   */
  private function createManager(): EntityDataBuilderManager&MockObject {
    $manager = $this->getMockBuilder(EntityDataBuilderManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getDefinitions', 'createInstance'])
      ->getMock();
    $manager->method('getDefinitions')->willReturn(self::DEFINITIONS);
    return $manager;
  }

  /**
   * The builder whose type and bundle both match is instantiated.
   */
  public function testReturnsTheBuilderMatchingTypeAndBundle(): void {
    $manager = $this->createManager();
    $builder = $this->createMock(EntityDataBuilderInterface::class);
    $manager->expects($this->once())->method('createInstance')->with('tags_builder')->willReturn($builder);

    $this->assertSame($builder, $manager->getDataBuilderForEntity('taxonomy_term', 'tags'));
  }

  /**
   * A bundle without a builder yields nothing, even for a known type.
   */
  public function testReturnsNullForBundleWithoutBuilder(): void {
    $manager = $this->createManager();
    $manager->expects($this->never())->method('createInstance');

    $this->assertNull($manager->getDataBuilderForEntity('node', 'page'));
  }

  /**
   * A bundle name is not enough: the entity type has to match as well.
   */
  public function testReturnsNullWhenOnlyTheBundleNameMatches(): void {
    $manager = $this->createManager();
    $manager->expects($this->never())->method('createInstance');

    $this->assertNull($manager->getDataBuilderForEntity('media', 'article'));
  }

}
