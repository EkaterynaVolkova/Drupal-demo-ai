<?php

namespace Drupal\shapoval_migrate\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\shapoval_migrate\BodyProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Icon URL of the old site to the ID of the migrated Media (or NULL).
 */
#[MigrateProcess('shapoval_media')]
class ShapovalMedia extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected BodyProcessor $processor) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('shapoval_migrate.body_processor'));
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $path = BodyProcessor::uploadsPath((string) $value);
    return $path === NULL ? NULL : $this->processor->mediaId($path);
  }

}
