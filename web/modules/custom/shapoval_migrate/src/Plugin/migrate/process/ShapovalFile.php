<?php

namespace Drupal\shapoval_migrate\Plugin\migrate\process;

use Drupal\file\Entity\File;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Gets or creates a managed file for a path inside uploads/.
 *
 * Configuration "only": bundle ("image" or "document") the value is for;
 * for other bundles NULL is returned.
 */
#[MigrateProcess('shapoval_file')]
class ShapovalFile extends ProcessPluginBase {

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (isset($this->configuration['only']) && $row->getSourceProperty('bundle') !== $this->configuration['only']) {
      return NULL;
    }
    $uri = 'public://uploads/' . $value;
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
    if ($files) {
      return reset($files)->id();
    }
    $file = File::create([
      'uri' => $uri,
      'filename' => basename($value),
      'uid' => 1,
      'status' => 1,
      'filesize' => (int) @filesize(\Drupal::root() . '/sites/default/files/uploads/' . $value),
    ]);
    $file->save();
    return $file->id();
  }

}
