<?php

declare(strict_types=1);

namespace Drupal\qdrant_sync;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\qdrant_sync\Attribute\EntityDataBuilder;

/**
 * Manages entity Data builder plugins.
 */
class EntityDataBuilderManager extends DefaultPluginManager {

  /**
   * Constructs a EntityDataBuilderManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/EntityDataBuilder',
      $namespaces,
      $module_handler,
      'Drupal\qdrant_sync\Plugin\EntityDataBuilder\EntityDataBuilderInterface',
      EntityDataBuilder::class
    );
    $this->alterInfo('entity_data_builder_info');
    $this->setCacheBackend($cache_backend, 'entity_data_builder_plugins');
  }

  /**
   * Gets the appropriate data builder for an entity.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $entity_bundle
   *   The entity bundle.
   *
   * @return object|null
   *   The builder plugin, or null if none found.
   */
  public function getDataBuilderForEntity(string $entity_type, string $entity_bundle): ?object {
    foreach ($this->getDefinitions() as $plugin_id => $definition) {
      if ($definition['entity_type'] === $entity_type && $definition['entity_bundle'] === $entity_bundle) {
        return $this->createInstance($plugin_id);
      }
    }

    return NULL;
  }

}
