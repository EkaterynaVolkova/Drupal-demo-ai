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
 * Cleans an HTML text of the old CMS.
 */
#[MigrateProcess('shapoval_body')]
class ShapovalBody extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected BodyProcessor $processor) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('shapoval_migrate.body_processor'));
  }

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    return $this->processor->process((string) $value);
  }

}
