<?php

namespace Drupal\queue_toolbox\Drush\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\COre\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drush\Attributes\Argument;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A drush command file to populate queueing samples.
 *
 * @package Drupal\queue_toolbox\Drush\Commands
 */
class QueueToolboxCommands extends DrushCommands implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The queue service for enqueueing big processes.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, QueueFactory $queueFactory) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->queueFactory = $queueFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('queue'),
    );
  }

  /**
   * Queue up each row from a CSV to be processed in a queue worker.
   */
  #[Command(name: 'queue-toolbox:create-from-csv', aliases: ['qtcfc'])]
  #[Usage(name: 'drush queue-toolbox:create-from-csv', description: 'Queues up the rows')]
  public function createFromCsv() {
    $total = 0;
    $queue = $this->queueFactory->get('queue_toolbox_create_from_csv');

    $rows = array_map('str_getcsv', file(realpath(dirname(__FILE__)) . '/../../../csv/products-1000.csv'));
    $header = array_shift($rows);
    foreach ($rows as $row) {
      $this_row = array_combine($header, $row);
      $queue->createItem($this_row);
      $total++;
    }

    return $total . ' rows are queued for processing. Run cron or '
      . 'drush queue:list to proceed.';
  }

  /**
   * Queue up each row from a CSV to be processed in a queue worker.
   */
  #[Command(name: 'queue-toolbox:update-from-csv', aliases: ['qtufc'])]
  #[Usage(name: 'drush queue-toolbox:update-from-csv', description: 'Queues up the rows')]
  public function updateFromCsv() {
    $total = 0;
    $queue = $this->queueFactory->get('queue_toolbox_update_from_csv');

    $rows = array_map('str_getcsv', file(realpath(dirname(__FILE__)) . '/../../../csv/products-1000.csv'));
    $header = array_shift($rows);
    foreach ($rows as $row) {
      $this_row = array_combine($header, $row);
      $queue->createItem($this_row);
      $total++;
    }

    return $total . ' rows are queued for processing. Run cron or '
      . 'drush queue:list to proceed.';
  }

  /**
   * Queue up all entities, optionally by bundle, for processing.
   */
  #[Command(name: 'queue-toolbox:move-fields', aliases: ['qtmf'])]
  #[Argument(name: 'entity_type', description: 'Entity type to enqueue')]
  #[Option(name: 'bundle', description: 'A specific bundle of the entity type to enqueue.')]
  #[Usage(name: 'drush queue-toolbox:move-fields', description: 'Queues up the rows')]
  public function moveFields() {
    $total = 0;
    $queue = $this->queueFactory->get('queue_toolbox_move_fields');

    // Get the entity bundle key for the query.
    $definition = $this->entityTypeManager->getDefinition($entity_type);
    $bundle_key = $definition->getKey('bundle');

    // Do an entity query with optional bundle.
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $query = $storage->getQuery()->accessCheck(FALSE);

    if (!empty($options['bundle']) && mb_strlen($options['bundle'])) {
      $query->condition($bundle_key, $options['bundle']);
    }

    $result = $query->execute();

    // Enqueue each entity ID with metadata needed to load it.
    foreach ($result as $entity_id) {
      $queue->createItem([
        'entity_id' => $entity_id,
        'entity_type' => $entity_type,
      ]);
      $total++;
    }

    return $total . ' rows are queued for processing. Run cron or '
      . 'drush queue:list to proceed.';
  }

  /**
   * Queue up all entities, optionally by bundle, for processing.
   */
  #[Command(name: 'queue-toolbox:layout-builder-block-to-section', aliases: ['qtlbbts'])]
  #[Argument(name: 'entity_type', description: 'Entity type to enqueue')]
  #[Option(name: 'bundle', description: 'A specific bundle of the entity type to enqueue.')]
  #[Usage(name: 'drush queue-toolbox:layout-builder-block-to-section', description: 'Queues up the rows')]
  public function layoutBuilderBlockToSection() {
    $total = 0;
    $queue = $this->queueFactory->get('queue_toolbox_layout_builder_block_to_section');

    // Get the entity bundle key for the query.
    $definition = $this->entityTypeManager->getDefinition($entity_type);
    $bundle_key = $definition->getKey('bundle');

    // Do an entity query with optional bundle.
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $query = $storage->getQuery()->accessCheck(FALSE);

    if (!empty($options['bundle']) && mb_strlen($options['bundle'])) {
      $query->condition($bundle_key, $options['bundle']);
    }

    $result = $query->execute();

    // Enqueue each entity ID with metadata needed to load it.
    foreach ($result as $entity_id) {
      $queue->createItem([
        'entity_id' => $entity_id,
        'entity_type' => $entity_type,
      ]);
      $total++;
    }

    return $total . ' rows are queued for processing. Run cron or '
      . 'drush queue:list to proceed.';
  }

}
