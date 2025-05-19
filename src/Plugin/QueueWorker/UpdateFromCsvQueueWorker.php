<?php

namespace Drupal\queue_toolbox\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process a queue based on CSV values via drush.
 *
 * @QueueWorker(
 *   id = "queue_toolbox_update_from_csv",
 *   title = @Translation("Process from CSV rows"),
 *   cron = {"time" = 10}
 * )
 */
class UpdateFromCsvQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The path alias manager.
   * 
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

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
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManager $entityTypeManager, AliasManagerInterface $aliasManager, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
    $this->aliasManager = $aliasManager;
    $this->logger = $logger_factory->get('queue_toolbox');
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
      $container->get('path_alias.manager'),
      $container->get('logger.factory')
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
   * Helper to look up a node by path alias.
   * 
   * @param string $alias
   *   Root-relative (starting with slash) path alias to look up.
   * @param string $langcode
   *   Two-letter code for the language of the alias.
   * 
   * @return bool|\Drupal\node\NodeInterface
   *   The node if prsent, or FALSE otherwise.
   */
  public function getNodeByAlias(string $alias, string $langcode = 'en') {
    $node = FALSE;

    $path = $this->aliasManager->getPathByAlias($alias, $langcode);
    if (preg_match('/node\/(\d+)/', $path, $matches)) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->entityTypeManager->getStorage('node')->load($matches[1]);
    }
    else {
      $this->logger->warning('No node found for alias %alias', [
        '%alias' => $alias,
      ]);
    }

    return $node;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Timestamp to pass to prepareRevision().
    $time = time();

    // If the product has an index value, look up by alias pattern.
    if (!empty($data['Index'])) {
      $entity = $this->getNodeByAlias('/product/' . $data['Index']);

      if (!empty($entity)) {
        /** @var \Drupal\Core\Entity\ContentEntityStorageBase $storage */
        $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());

        if ($entity instanceof RevisionableInterface && !$entity->isLatestRevision()) {
          $vid = $storage->getLatestRevisionId($entity->id());
        }

        // Prepare and update current revision of entity.
        $this->prepareRevision($entity, 'Update product category based on CSV.', $time);
        $this->updateEntity($entity, $data);

        if (!empty($vid)) {
          /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
          $latest = $storage->loadRevision($vid);
          // Do the same to latest, but a millisecond later.
          $this->prepareRevision($latest, 'Update product category based on CSV.', $time + 1);
          $this->updateEntity($latest, $data);
        }
      }
    }
  }

  /**
   * Perform the entity operations from CSV here.
   *
   * @param FieldableEntityInterface $entity
   *   An entity to update, usually a node.
   * @param array $data
   *   Data from a CSV row.
   */
  public function updateEntity(FieldableEntityInterface $entity, array $data) {
    $save = FALSE;

    // If CSV row has description text.
    if (!empty($data['Description'])) {
      $entity->set('field_body', $data['Description']);
      $save = TRUE;
    }

    // Only save if there is something to update.
    if ($save) {
      $entity->save();
    }
  }

}
