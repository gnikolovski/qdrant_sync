<?php

declare(strict_types=1);

namespace Drupal\qdrant_sync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Qdrant\Models\Filter\Condition\MatchString;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\PointStruct;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\Request\CreateCollection;
use Qdrant\Models\Request\VectorParams;
use Qdrant\Models\VectorStruct;
use Qdrant\Qdrant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Defines the QdrantSyncService class.
 */
final class QdrantSyncService {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.factory')]
    protected LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'state')]
    protected StateInterface $state,
    #[Autowire(service: 'config.factory')]
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'plugin.manager.entity_data_builder')]
    protected EntityDataBuilderManager $entityDataManager,
    #[Autowire(service: 'qdrant_sync.qdrant_client_factory')]
    protected QdrantClientFactory $qdrantClientFactory,
    #[Autowire(service: 'qdrant_sync.openai_client_factory')]
    protected OpenAiClientFactory $openAiClientFactory,
  ) {}

  /**
   * Gets the Qdrant client.
   *
   * @param string|null $qdrant_host
   *   The Qdrant host.
   * @param int|null $qdrant_port
   *   The Qdrant port.
   * @param string|null $qdrant_api_key
   *   The Qdrant API key.
   *
   * @return \Qdrant\Qdrant
   *   The Qdrant client.
   */
  protected function client(
    ?string $qdrant_host = NULL,
    ?int $qdrant_port = NULL,
    ?string $qdrant_api_key = NULL,
  ): Qdrant {
    $config = $this->configFactory->get('qdrant_sync.settings');

    if ($qdrant_host === NULL) {
      $qdrant_host = (string) $config->get('qdrant_host');
    }

    if ($qdrant_port === NULL) {
      $qdrant_port = (int) $config->get('qdrant_port');
    }

    if ($qdrant_api_key === NULL) {
      $qdrant_api_key = (string) $config->get('qdrant_api_key');
    }

    return $this->qdrantClientFactory->create($qdrant_host, $qdrant_port, $qdrant_api_key);
  }

  /***
   * Creates a new Qdrant collection if needed.
   */
  public function createCollectionIfNotExists(
    int $vector_size,
    string $qdrant_host,
    int $qdrant_port,
    string $qdrant_api_key,
    string $qdrant_collection_name,
  ): bool {
    $client = $this->client($qdrant_host, $qdrant_port, $qdrant_api_key);
    $response = $client->collections($qdrant_collection_name)->exists();

    if ($response['result']['exists'] === FALSE) {
      $collection = new CreateCollection();
      $collection->addVector(new VectorParams($vector_size, VectorParams::DISTANCE_COSINE), 'content');
      $response = $client->collections($qdrant_collection_name)->create($collection);
      return $response['result'];
    }

    return TRUE;
  }

  /**
   * Syncs data to vector database.
   */
  public function upsertData(QdrantSyncQueueItem $queue_item): void {
    $data_builder = $this->entityDataManager->getDataBuilderForEntity(
      $queue_item->getEntityType(),
      $queue_item->getEntityBundle(),
    );

    $entity = $this->entityTypeManager
      ->getStorage($queue_item->getEntityType())
      ->load($queue_item->getEntityId());

    if ($data_builder && $entity instanceof ContentEntityInterface) {
      $data = $data_builder->build($entity);
      $this->doSyncData($queue_item, $data);
    }
  }

  /**
   * Deletes data from vector database.
   */
  public function deleteData(QdrantSyncQueueItem $queue_item): void {
    $config = $this->configFactory->get('qdrant_sync.settings');
    $qdrant_collection_name = $config->get('qdrant_collection_name');
    $client = $this->client();

    $filter = new Filter();
    $filter->addMust(new MatchString('entity_uuid', $queue_item->getEntityUuid()));

    $client->collections($qdrant_collection_name)->points()->deleteByFilter($filter);
  }

  /**
   * Syncs data to vector database.
   *
   * @phpstan-param array<string, mixed> $data
   */
  protected function doSyncData(QdrantSyncQueueItem $queue_item, array $data): void {
    $config = $this->configFactory->get('qdrant_sync.settings');
    $qdrant_collection_name = $config->get('qdrant_collection_name');

    $embedding = $this->getEmbeddingForData($data);
    if (empty($embedding)) {
      $this->loggerFactory->get('qdrant_sync')->warning('Embeddings not created for entity with UUID: @entity_uuid', [
        '@entity_uuid' => $queue_item->getEntityUuid(),
      ]);
      return;
    }

    $client = $this->client();
    $points = new PointsStruct();
    $points->addPoint(
      new PointStruct(
        $queue_item->getEntityUuid(),
        new VectorStruct($embedding, 'content'),
        [
          'entity_type' => $queue_item->getEntityType(),
          'entity_bundle' => $queue_item->getEntityBundle(),
          'entity_id' => $queue_item->getEntityId(),
          'entity_uuid' => $queue_item->getEntityUuid(),
          'text' => json_encode($data),
        ]
      )
    );
    $client->collections($qdrant_collection_name)->points()->upsert($points);
  }

  /**
   * Gets the embedding for provided data.
   *
   * @param array<string, mixed> $data
   *   The data.
   *
   * @return array<int, float>
   *   The embedding for provided data.
   */
  protected function getEmbeddingForData(array $data): array {
    $config = $this->configFactory->get('qdrant_sync.settings');
    $api_key = (string) $config->get('api_key');
    $organization_id = $config->get('organization_id');
    $embedding_model = $config->get('embedding_model');

    $openai_client = $this->openAiClientFactory->create($api_key, $organization_id ?: NULL);
    $response = $openai_client->embeddings()->create([
      'model' => $embedding_model,
      'input' => json_encode($data),
    ]);

    return array_values($response->embeddings[0]->embedding);
  }

  /**
   * Gets the number of vectors in a collection.
   *
   * @param string $host
   *   The Qdrant host.
   * @param int $port
   *   The Qdrant port.
   * @param string $api_key
   *   The Qdrant API key.
   * @param string $collection_name
   *   The collection name.
   *
   * @return int
   *   The number of vectors in the collection.
   */
  public function getCollectionVectorCount(
    string $host,
    int $port,
    string $api_key,
    string $collection_name,
  ): int {
    $client = $this->client($host, $port, $api_key);

    try {
      /** @var \Qdrant\Response $collection_info */
      $collection_info = $client->collections($collection_name)->info();
      return $collection_info->__toArray()['result']['points_count'] ?? 0;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

}
