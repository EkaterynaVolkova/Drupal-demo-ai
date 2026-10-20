<?php

namespace Drupal\shapoval_migrate\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Generic source: all columns of a table, optionally filtered.
 *
 * Configuration: table, id (column), where (array of column => value).
 */
#[MigrateSource('shapoval_table')]
class ShapovalTable extends SqlBase {

  public function query() {
    $query = $this->select($this->configuration['table'], 't')->fields('t');
    foreach ($this->configuration['where'] ?? [] as $column => $value) {
      $query->condition($column, $value);
    }
    return $query;
  }

  public function fields() {
    $fields = [];
    foreach ($this->getDatabase()->query('DESCRIBE ' . $this->configuration['table'])->fetchAll() as $c) {
      $fields[$c->Field] = $c->Field;
    }
    return $fields;
  }

  public function getIds() {
    return [$this->configuration['id'] => ['type' => 'integer', 'alias' => 't']];
  }

}
