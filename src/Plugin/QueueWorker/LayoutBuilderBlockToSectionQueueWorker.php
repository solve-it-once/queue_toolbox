<?php

namespace Drupal\queue_toolbox\Plugin\QueueWorker;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process a queue based on entity IDs set up via drush.
 *
 * @QueueWorker(
 *   id = "queue_toolbox_layout_builder_block_to_section",
 *   title = @Translation("Process entity from queue"),
 *   cron = {"time" = 10}
 * )
 */
class LayoutBuilderBlockToSectionQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The drupal entity type manager interface.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Cached field map of layout section fields.
   *
   * @var array
   */
  protected array $layoutSectionFieldMap;

  /**
   * The logger channel factory.
   * 
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The uuid service.
   * 
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entityFieldManager, EntityTypeManagerInterface $entityTypeManager, LoggerChannelFactoryInterface $logger_factory, UuidInterface $uuidService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityFieldManager = $entityFieldManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger_factory->get('queue_toolbox');
    $this->uuidService = $uuidService;

    // Cache the layout section fields for memory management.
    $this->layoutSectionFieldMap = $this->entityFieldManager->getFieldMapByFieldType('layout_section');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('uuid')
    );
  }

  /**
   * Set the new revision to same state and log the update.
   * 
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to act on.
   * @param string $message
   *   A revision log message if those are present.
   * @param int $time
   *   The changed time for the revision.
   */
  public function prepareRevision(FieldableEntityInterface &$entity, $message, $time) {
    $entity->set('changed', $time);

    // If using a moderation workflow, set new revision to match latest.
    if ($entity->hasField('moderation_state')) {
      $state = $entity->get('moderation_state')->value;
      $entity->set('moderation_state', $state);
    }

    // Add a log message if available/required.
    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionLogMessage($message);
      $entity->setRevisionUserId(1);
    }
  }

  /**
   * Convert a hero block to a hero section.
   * 
   * @param array $component
   *   The block array to convert.
   * 
   * @return \Drupal\layout_builder\Section
   *   A hero section already converted from array.
   */
  protected function swapHeroBlock($component) {
    // Define section skeleton to be filled in.
    $section = [
      'layout_id' => 'new_recipe_section_id_here',
      'layout_settings' => [
        'label' => 'Hero',
        'custom_id' => '',
        'custom_classes' => '',
        'custom_class_choose' => NULL,
        'custom_styles' => NULL,
        'custom_data_attributes' => NULL,
      ],
      'components' => [],
      'third_party_settings' => [],
    ];

    // Store weight so we can conditionally increment it.
    $weight = 0;

    // Place first block. Each block is in same region for this example.
    if (!empty($component['configuration']['intro']['value'])) {
      // By reusing this, we can address the last block if needed.
      $uuid = $this->uuidService->generate();
      // A block plugin. For block_content use BlockContent::create() first.
      // Then block_content can be placed in inline_block derivatives.
      $section['components'][$uuid] = [
        'uuid' => $uuid,
        'region' => 'content',
        'configuration' => [
          'id' => 'text_block_plugin_id_here',
          'label' => 'Intro',
          'label_display' => FALSE,
          'provider' => 'approximate_module_machine_name_here',
          'text' => $component['configuration']['intro'],
          'context_mapping' => [],
        ],
        'weight' => $weight,
        'additional' => [],
      ];
      $weight++;
    }

    // Place second block.
    if (!empty($component['configuration']['body']['value'])) {
      $uuid = $this->uuidService->generate();
      $section['components'][$uuid] = [
        'uuid' => $uuid,
        'region' => 'content',
        'configuration' => [
          'id' => 'text_block_plugin_id_here',
          'label' => 'Body',
          'label_display' => FALSE,
          'provider' => 'approximate_module_machine_name_here',
          'text' => $component['configuration']['body'],
          'context_mapping' => [],
        ],
        'weight' => $weight,
        'additional' => [],
      ];
    }

    return Section::fromArray($section);
  }

  /**
   * Prepend a particular key in an array.
   *
   * @param array $array
   *   The array to modify.
   * @param string $key
   *   The key to insert before.
   * @param array $new
   *   The new array to insert.
   *
   * @return array
   *   The modified array.
   */
  public static function arrayInsertBefore(array $array, $key, array $new): array {
    $keys = array_keys($array);
    $pos = (int) array_search($key, $keys);

    if ($key === 0) {
      array_unshift($array, $new[0]);
      return $array;
    }
    else {
      return array_merge(array_slice($array, 0, $pos), $new, array_slice($array, $pos));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Timestamp to pass to prepareRevision().
    $time = time();

    /** @var \Drupal\Core\Entity\ContentEntityStorageBase $storage */
    $storage = $this->entityTypeManager->getStorage($data['entity_type']);

    // First load the entity.
    $entity = $storage->load($data['entity_id']);
    if (!empty($entity)) {
      if ($entity instanceof RevisionableInterface && !$entity->isLatestRevision()) {
        $vid = $storage->getLatestRevisionId($entity->id());
      }

      // Prepare and update current revision of entity.
      $this->prepareRevision($entity, 'Convert hero block to hero section in place.', $time);
      $this->updateEntity($entity, $data);

      if (!empty($vid)) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
        $latest = $storage->loadRevision($vid);
        // Do the same to latest, but a millisecond later.
        $this->prepareRevision($latest, 'Convert hero block to hero section in place.', $time + 1);
        $this->updateEntity($latest, $data);
      }
    }
  }

  /**
   * Perform the entity operations here.
   *
   * @param FieldableEntityInterface $entity
   *   An entity to update, usually a node.
   * @param array $data
   *   Data from the queue item.
   */
  public function updateEntity(FieldableEntityInterface $entity, array $data) {
    $save = FALSE;

    if (!empty($this->layoutSectionFieldMap[$data['entity_type']])) {
      foreach ($this->layoutSectionFieldMap[$data['entity_type']] as $field_name => $field_map) {
        // Prevent otherwise-harmless error log.
        if ($entity->hasField($field_name)) {
          $layout = $entity->get($field_name)->getValue();
          foreach ($layout as $i => $definition) {
            // Sections are lots of protected properties, so convert to array.
            $section = $definition['section']->toArray();

            // Find hero blocks and perform ops only when found.
            foreach ($section['components'] as $uuid => $component) {
              switch ($component['configuration']['id']) {
                case 'hero_block_plugin_id_here':
                  $save = TRUE;

                  // Create and prepend the replacement.
                  $new_section = $this->swapHeroBlock($component);
                  $layout = static::arrayInsertBefore($layout, $i, [
                    [
                      'section' => $new_section,
                    ],
                  ]);

                  // Assumes there is only one hero block per section.
                  if (count($section['components']) === 1) {
                    // Remove old section containing only the hero block.
                    unset($layout[$i + 1]);
                  }
                  else {
                    // Remove the block.
                    unset($section['components'][$uuid]);

                    // Convert new section back to object in place.
                    $layout[$i + 1]['section'] = Section::fromArray($section);
                  }

                  break;
              }
            }
          }

          // Set the layout back into the field.
          $entity->get(OverridesSectionStorage::FIELD_NAME)->setValue($layout);
        }
      }
    }

    // Only save if there is something to update.
    if ($save) {
      try {
        $entity->save();
      }
      catch (EntityStorageException $e) {
        $this->logger->error($e->getMessage());
      }
    }
  }

}
