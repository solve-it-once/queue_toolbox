<?php

namespace Drupal\queue_toolbox\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process a queue based on CSV values via drush.
 *
 * @QueueWorker(
 *   id = "queue_toolbox_create_from_csv",
 *   title = @Translation("Process from CSV rows"),
 *   cron = {"time" = 10}
 * )
 */
class CreateFromCsvQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
   * Lookup of taxonomy terms by name.
   *
   * @param string $name
   *   Term name to look up.
   * @param string $vid
   *   The vocabulary machine name.
   *
   * @return bool|string
   *   The id if present, or FALSE otherwise.
   */
  public function getTaxonomyTermTidByName(string $name, string $vid) {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vid,
        'name' => $name,
      ]);
    $term = (count($terms)) ? array_shift($terms) : FALSE;
    return ($term) ? $term->id() : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Timestamp to pass to prepareRevision().
    $time = time();

    if (!empty($data['Category'])) {
      // Create new term (in vocab that must pre-exist) to hold the data.
      $term = Term::create([
        'vid' => 'product_category',
        'name' => $data['Name'],
      ]);

      // Handle the currency term reference.
      $currency_id = $this->getTaxonomyTermTidByName($data['Currency'], 'currency');
      if (empty($currency_id)) {
        $currency = Term::create([
          'vid' => 'currency',
          'name' => $data['Currency'],
        ]);
        $currency->save();
        $currency_id = $currency->id();
      }
      $term->set('field_currency_term', [
        'target_id' => $currency_id,
        'target_type' => 'taxonomy_term',
      ]);

      // Save the new term.
      $this->prepareRevision($term, 'Create new term from product CSV.');
      $term->save();
    }
  }

}
