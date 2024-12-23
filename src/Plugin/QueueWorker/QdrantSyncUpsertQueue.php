<?php

declare(strict_types=1);

namespace Drupal\qdrant_sync\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\qdrant_sync\QdrantSyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines 'qdrant_sync_upsert' queue worker.
 */
#[QueueWorker(
  id: 'qdrant_sync_upsert',
  title: new TranslatableMarkup('Qdrant sync upsert'),
  cron: ['time' => 60]
)]
final class QdrantSyncUpsertQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a new QdrantSyncUpsertQueue instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly QdrantSyncService $qdrantSyncService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('qdrant_sync.qdrant_sync_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->qdrantSyncService->upsertData($data);
  }

}
