<?php

namespace Drupal\queue_toolbox\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process a queue of entities added via drush.
 *
 * @QueueWorker(
 *   id = "queue_toolbox_move_fields",
 *   title = @Translation("Process entity from queue"),
 *   cron = {"time" = 10}
 * )
 */
class MoveFieldsQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The drupal entity query interface.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected EntityTypeManager $entityTypeManager;

  /**
   * The logger channel factory.
   * 
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The path matcher service.
   * 
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  protected $pathMatcher;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManager $entityTypeManager, LoggerChannelFactoryInterface $logger_factory, PathMatcherInterface $path_matcher) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger_factory->get('queue_toolbox');
    $this->pathMatcher = $path_matcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('path.matcher')
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
      $this->prepareRevision($entity, 'Move some fields around.', $time);
      $this->updateEntity($entity, $data);

      if (!empty($vid)) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
        $latest = $storage->loadRevision($vid);
        // Do the same to latest, but a millisecond later.
        $this->prepareRevision($latest, 'Move some fields around.', $time + 1);
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

    // Get the entity's URL alias to match it.
    $alias = mb_strtolower($entity->toUrl()->toString());

    // Only move body to new field if alias matches patterns.
    $pages = mb_strtolower("/products/*\r\n/product-category/*");
    if ($this->pathMatcher->matchPath($alias, $pages)) {
      $old_field = $entity->get('field_body')->getValue();
      $entity->set('field_body', [
        'format' => 'basic_html',
        'value' => '',
      ]);
      $entity->set('field_body_new', $old_field[0]);
      $save = TRUE;
    }

    // Only save if there is something to update.
    if ($save) {
      $entity->save();
    }
  }

}
