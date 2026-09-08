<?php

declare(strict_types=1);

namespace Drupal\qdrant_sync\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\qdrant_sync\EntityDataBuilderManager;
use Drupal\qdrant_sync\QdrantSyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the QdrantSyncSettingsForm class.
 */
class QdrantSyncSettingsForm extends ConfigFormBase {

  const array MODEL_EMBEDDING_VECTOR_SIZES = [
    'text-embedding-3-small' => 1536,
    'text-embedding-3-large' => 3072,
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Qdrant sync service.
   *
   * @var \Drupal\qdrant_sync\QdrantSyncService
   */
  protected QdrantSyncService $qdrantSyncService;

  /**
   * The entity JSON builder manager.
   *
   * @var \Drupal\qdrant_sync\EntityDataBuilderManager
   */
  protected EntityDataBuilderManager $builderManager;

  /**
   * Constructs a QdrantSyncSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\qdrant_sync\QdrantSyncService $qdrant_sync_service
   *   The Qdrant sync service.
   * @param \Drupal\qdrant_sync\EntityDataBuilderManager $builder_manager
   *   The entity JSON builder manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    EntityTypeManagerInterface $entity_type_manager,
    QdrantSyncService $qdrant_sync_service,
    EntityDataBuilderManager $builder_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->entityTypeManager = $entity_type_manager;
    $this->qdrantSyncService = $qdrant_sync_service;
    $this->builderManager = $builder_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('qdrant_sync.qdrant_sync_service'),
      $container->get('plugin.manager.entity_data_builder'),
    );
  }

  /**
   * {@inheritDoc}
   *
   * @phpstan-return array<int, string>
   */
  protected function getEditableConfigNames(): array {
    return [
      'qdrant_sync.settings',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId(): string {
    return 'qdrant_sync_settings';
  }

  /**
   * {@inheritDoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('qdrant_sync.settings');

    $form['openai'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Open AI'),
    ];

    $form['openai']['organization_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID'),
      '#default_value' => $config->get('organization_id') ?? '',
      '#required' => TRUE,
    ];

    $form['openai']['api_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('OpenAI API key'),
      '#default_value' => $config->get('api_key') ?? '',
      '#required' => TRUE,
    ];

    $form['openai']['embedding_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding model'),
      '#description' => $this->t('An embedding model converts input text or data into numerical vectors, capturing semantic meaning to enable tasks like similarity comparison, clustering, and search.'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large'),
      ],
      '#default_value' => $config->get('embedding_model') ?? '',
      '#required' => TRUE,
    ];

    $form['qdrant'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Qdrant'),
    ];

    $form['qdrant']['qdrant_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Qdrant host'),
      '#default_value' => $config->get('qdrant_host') ?? '',
      '#required' => TRUE,
    ];

    $form['qdrant']['qdrant_port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Qdrant port'),
      '#default_value' => $config->get('qdrant_port') ?? '',
      '#required' => TRUE,
    ];

    $form['qdrant']['qdrant_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Qdrant API key'),
      '#default_value' => $config->get('qdrant_api_key') ?? '',
      '#required' => TRUE,
    ];

    $form['qdrant']['qdrant_collection_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Qdrant collection name'),
      '#default_value' => $config->get('qdrant_collection_name') ?? '',
      '#required' => TRUE,
    ];

    $vector_count = 0;
    if ($collection_name = $config->get('qdrant_collection_name')) {
      $vector_count = $this->qdrantSyncService->getCollectionVectorCount(
        $config->get('qdrant_host'),
        (int) $config->get('qdrant_port'),
        $config->get('qdrant_api_key'),
        $collection_name
      );
    }

    $form['qdrant']['collection_info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Current number of points in collection: @count', [
        '@count' => $vector_count,
      ]),
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    $form['content_sync'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Content synchronization'),
      '#tree' => TRUE,
    ];

    $enabled_builders = $config->get('content_sync.enabled_builders') ?? [];

    $count = 0;

    foreach ($this->builderManager->getDefinitions() as $plugin_id => $definition) {
      $form['content_sync']['enabled_builders'][$plugin_id] = [
        '#type' => 'checkbox',
        '#title' => $definition['label'] . ' (' . $definition['entity_type'] . ':' . $definition['entity_bundle'] . ')',
        '#default_value' => !empty($enabled_builders[$plugin_id]),
      ];

      $count += $this->getEntityCount($definition['entity_type'], $definition['entity_bundle']);
    }

    $form['content_sync']['total_entities'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Total number of entities to sync to Qdrant database: @count', [
        '@count' => $count,
      ]),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Gets the count of entities for a specific entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   *
   * @return int
   *   The number of entities of the specified type and bundle.
   */
  protected function getEntityCount(string $entity_type_id, string $bundle): int {
    try {
      $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery();
      $query->condition('type', $bundle);
      $query->condition('status', TRUE);
      $query->accessCheck(FALSE);
      return $query->count()->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * {@inheritDoc}
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $embedding_model = $form_state->getValue('embedding_model');
    $qdrant_host = $form_state->getValue('qdrant_host');
    $qdrant_port = (int) $form_state->getValue('qdrant_port');
    $qdrant_api_key = $form_state->getValue('qdrant_api_key');
    $qdrant_collection_name = $form_state->getValue('qdrant_collection_name');

    if ($this->qdrantSyncService->createCollectionIfNotExists(
      static::MODEL_EMBEDDING_VECTOR_SIZES[$embedding_model],
      $qdrant_host,
      $qdrant_port,
      $qdrant_api_key,
      $qdrant_collection_name,
    ) === FALSE) {
      $form_state->setErrorByName('', $this->t('Something went wrong while connecting to the Qdrant database instance.'));
    }
  }

  /**
   * {@inheritDoc}
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('qdrant_sync.settings');
    $form_state->cleanValues();
    $values = $form_state->getValues();
    $config->setData($values);
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
