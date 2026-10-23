<?php

namespace Drupal\shapoval_migrate\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Node ID to its system path (/node/<nid>).
 */
#[MigrateProcess('shapoval_node_path')]
class ShapovalNodePath extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    return '/node/' . (is_array($value) ? reset($value) : $value);
  }

}
