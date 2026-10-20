<?php

namespace Drupal\shapoval_migrate\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Parent menu link plugin id ("menu_link_content:<uuid>") or empty string.
 *
 * Configuration "migration": the migration that created the parent links.
 */
#[MigrateProcess('shapoval_menu_parent')]
class ShapovalMenuParent extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected MigrateLookupInterface $lookup) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('migrate.lookup'));
  }

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!$value) {
      return '';
    }
    $ids = $this->lookup->lookup($this->configuration['migration'], [$value]);
    if (!$ids) {
      return '';
    }
    $link = \Drupal::entityTypeManager()->getStorage('menu_link_content')->load(reset($ids[0]));
    return $link ? 'menu_link_content:' . $link->uuid() : '';
  }

}
