<?php

declare(strict_types=1);

namespace Drupal\Tests\qdrant_sync\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\qdrant_sync\EntityDataBuilderManager;
use Drupal\qdrant_sync\OpenAiClientFactory;
use Drupal\qdrant_sync\Plugin\EntityDataBuilder\EntityDataBuilderInterface;
use Drupal\qdrant_sync\QdrantClientFactory;
use Drupal\qdrant_sync\QdrantSyncQueueItem;
use Drupal\qdrant_sync\QdrantSyncService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response as HttpResponse;
use OpenAI\Resources\Embeddings;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\RequestInterface;
use Qdrant\Http\Transport;
use Qdrant\Qdrant;

/**
 * Tests the sync service against a fake OpenAI client and Qdrant transport.
 */
#[CoversClass(QdrantSyncService::class)]
#[Group('qdrant_sync')]
final class QdrantSyncServiceTest extends UnitTestCase {

  private const array SETTINGS = [
    'qdrant_sync.settings' => [
      'qdrant_host' => 'qdrant.example.com',
      'qdrant_port' => 6333,
      'qdrant_api_key' => 'qdrant-key',
      'qdrant_collection_name' => 'site',
      'api_key' => 'openai-key',
      'organization_id' => 'org-1',
      'embedding_model' => 'text-embedding-3-small',
    ],
  ];

  /**
   * The requests the fake Qdrant transport received, in order.
   *
   * @var \Psr\Http\Message\RequestInterface[]
   */
  private array $qdrantRequests = [];

  /**
   * The arguments each Qdrant client was created with, in order.
   *
   * @var array<int, array{string, int, string}>
   */
  private array $qdrantClients = [];

  /**
   * The fake OpenAI client.
   */
  private ClientFake $openai;

  /**
   * The logger channel the service writes to.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * The entity data builder manager.
   */
  private EntityDataBuilderManager&MockObject $dataBuilderManager;

  /**
   * The entity storage for the 'node' entity type.
   */
  private EntityStorageInterface&MockObject $nodeStorage;

  /**
   * Encodes a JSON body, failing the test instead of returning false.
   *
   * @param array<mixed> $data
   *   The data to encode.
   */
  private static function json(array $data): string {
    return json_encode($data, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds the config factory, typed so the service constructor accepts it.
   *
   * @param array<string, array<string, mixed>> $configs
   *   The config objects keyed by name.
   */
  private function configFactory(array $configs): ConfigFactoryInterface {
    $config_factory = $this->getConfigFactoryStub($configs);
    assert($config_factory instanceof ConfigFactoryInterface);
    return $config_factory;
  }

  /**
   * Builds the service.
   *
   * @param array<int, mixed> $qdrant_results
   *   One entry per expected Qdrant request: the 'result' of the reply, or a
   *   \Throwable the transport should raise instead.
   */
  private function createService(array $qdrant_results = []): QdrantSyncService {
    $transport = $this->createMock(Transport::class);
    $transport->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$qdrant_results): HttpResponse {
      $this->qdrantRequests[] = $request;
      $result = array_shift($qdrant_results);
      if ($result instanceof \Throwable) {
        throw $result;
      }
      return new HttpResponse(200, ['content-type' => 'application/json'], self::json([
        'status' => 'ok',
        'result' => $result,
      ]));
    });

    $qdrant_factory = $this->createMock(QdrantClientFactory::class);
    $qdrant_factory->method('create')->willReturnCallback(function (string $host, int $port, string $api_key) use ($transport): Qdrant {
      $this->qdrantClients[] = [$host, $port, $api_key];
      return new Qdrant($transport);
    });

    $this->openai = new ClientFake();
    $openai_factory = $this->createMock(OpenAiClientFactory::class);
    $openai_factory->method('create')->with('openai-key', 'org-1')->willReturn($this->openai);

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('qdrant_sync')->willReturn($this->logger);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($this->nodeStorage);

    $this->dataBuilderManager = $this->createMock(EntityDataBuilderManager::class);

    return new QdrantSyncService(
      $entity_type_manager,
      $logger_factory,
      $this->createMock(StateInterface::class),
      $this->configFactory(self::SETTINGS),
      $this->dataBuilderManager,
      $qdrant_factory,
      $openai_factory,
    );
  }

  /**
   * Decodes the JSON body of a recorded Qdrant request.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  private function body(RequestInterface $request): array {
    return json_decode((string) $request->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * An existing collection is left alone.
   */
  public function testCreateCollectionIfNotExistsKeepsAnExistingCollection(): void {
    $service = $this->createService([['exists' => TRUE]]);

    $this->assertTrue($service->createCollectionIfNotExists(1536, 'other.example.com', 6334, 'other-key', 'site'));

    $this->assertSame([['other.example.com', 6334, 'other-key']], $this->qdrantClients);
    $this->assertCount(1, $this->qdrantRequests);
    $this->assertSame('GET', $this->qdrantRequests[0]->getMethod());
    $this->assertSame('/collections/site/exists', $this->qdrantRequests[0]->getUri()->getPath());
  }

  /**
   * A missing collection is created with a named cosine vector.
   */
  public function testCreateCollectionIfNotExistsCreatesTheMissingCollection(): void {
    $service = $this->createService([['exists' => FALSE], TRUE]);

    $this->assertTrue($service->createCollectionIfNotExists(1536, 'other.example.com', 6334, 'other-key', 'site'));

    $this->assertCount(2, $this->qdrantRequests);
    $create = $this->qdrantRequests[1];
    $this->assertSame('PUT', $create->getMethod());
    $this->assertSame('/collections/site', $create->getUri()->getPath());
    $this->assertSame([
      'vectors' => ['content' => ['size' => 1536, 'distance' => 'Cosine']],
    ], $this->body($create));
  }

  /**
   * Deleting filters the points by the entity UUID.
   */
  public function testDeleteDataRemovesThePointsOfTheEntity(): void {
    $service = $this->createService([['status' => 'acknowledged']]);

    $service->deleteData(new QdrantSyncQueueItem('node', 'article', 5, 'uuid-1'));

    $this->assertSame([['qdrant.example.com', 6333, 'qdrant-key']], $this->qdrantClients);
    $this->assertCount(1, $this->qdrantRequests);
    $delete = $this->qdrantRequests[0];
    $this->assertSame('POST', $delete->getMethod());
    $this->assertSame('/collections/site/points/delete', $delete->getUri()->getPath());
    $this->assertSame([
      'filter' => ['must' => [['key' => 'entity_uuid', 'match' => ['value' => 'uuid-1']]]],
    ], $this->body($delete));
  }

  /**
   * Upserting embeds the built data and stores it under the entity UUID.
   */
  public function testUpsertDataEmbedsTheBuiltDataAndUpsertsThePoint(): void {
    $service = $this->createService([['status' => 'acknowledged']]);
    $this->openai->addResponses([
      EmbeddingsResponse::fake(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]),
    ]);

    $entity = $this->createMock(ContentEntityInterface::class);
    $this->nodeStorage->method('load')->with(5)->willReturn($entity);
    $builder = $this->createMock(EntityDataBuilderInterface::class);
    $builder->method('build')->with($entity)->willReturn(['title' => 'Hello']);
    $this->dataBuilderManager->method('getDataBuilderForEntity')->with('node', 'article')->willReturn($builder);

    $service->upsertData(new QdrantSyncQueueItem('node', 'article', 5, 'uuid-1'));

    $this->openai->assertSent(Embeddings::class, function (string $method, array $parameters): bool {
      return $method === 'create'
        && $parameters['model'] === 'text-embedding-3-small'
        && $parameters['input'] === '{"title":"Hello"}';
    });

    $this->assertCount(1, $this->qdrantRequests);
    $upsert = $this->qdrantRequests[0];
    $this->assertSame('PUT', $upsert->getMethod());
    $this->assertSame('/collections/site/points', $upsert->getUri()->getPath());
    $this->assertSame([
      'points' => [
        [
          'id' => 'uuid-1',
          'vector' => ['content' => [0.1, 0.2, 0.3]],
          'payload' => [
            'entity_type' => 'node',
            'entity_bundle' => 'article',
            'entity_id' => 5,
            'entity_uuid' => 'uuid-1',
            'text' => '{"title":"Hello"}',
          ],
        ],
      ],
    ], $this->body($upsert));
  }

  /**
   * Entities without a data builder are ignored.
   */
  public function testUpsertDataSkipsEntitiesWithoutDataBuilder(): void {
    $service = $this->createService();
    $this->nodeStorage->method('load')->willReturn($this->createMock(ContentEntityInterface::class));
    $this->dataBuilderManager->method('getDataBuilderForEntity')->willReturn(NULL);

    $service->upsertData(new QdrantSyncQueueItem('node', 'article', 5, 'uuid-1'));

    $this->openai->assertNothingSent();
    $this->assertSame([], $this->qdrantRequests);
  }

  /**
   * An empty embedding is logged and nothing reaches Qdrant.
   */
  public function testUpsertDataLogsWarningWhenNoEmbeddingComesBack(): void {
    $service = $this->createService();
    $this->openai->addResponses([
      EmbeddingsResponse::fake([
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => []]],
      ], NULL, OverrideStrategy::Replace),
    ]);
    $this->nodeStorage->method('load')->willReturn($this->createMock(ContentEntityInterface::class));
    $builder = $this->createMock(EntityDataBuilderInterface::class);
    $builder->method('build')->willReturn(['title' => 'Hello']);
    $this->dataBuilderManager->method('getDataBuilderForEntity')->willReturn($builder);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('Embeddings not created'), ['@entity_uuid' => 'uuid-1']);

    $service->upsertData(new QdrantSyncQueueItem('node', 'article', 5, 'uuid-1'));

    $this->assertSame([], $this->qdrantRequests);
  }

  /**
   * The vector count comes from the collection info.
   */
  public function testGetCollectionVectorCountReadsThePointsCount(): void {
    $service = $this->createService([['status' => 'green', 'points_count' => 42]]);

    $this->assertSame(42, $service->getCollectionVectorCount('other.example.com', 6334, 'other-key', 'site'));

    $this->assertSame([['other.example.com', 6334, 'other-key']], $this->qdrantClients);
    $this->assertSame('GET', $this->qdrantRequests[0]->getMethod());
    $this->assertSame('/collections/site', $this->qdrantRequests[0]->getUri()->getPath());
  }

  /**
   * A failing Qdrant call counts as zero, but says so in the log.
   */
  public function testGetCollectionVectorCountIsZeroAndLoggedWhenQdrantFails(): void {
    $service = $this->createService([new \RuntimeException('Connection refused')]);
    $this->logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('point count'), ['@collection' => 'site', '@error' => 'Connection refused']);

    $this->assertSame(0, $service->getCollectionVectorCount('other.example.com', 6334, 'other-key', 'site'));
  }

}
