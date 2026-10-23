<?php

namespace Drupal\shapoval_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Database;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\shapoval_migrate\BodyProcessor;

/**
 * Unique existing uploads/ files referenced by texts, icons and settings.
 */
#[MigrateSource('shapoval_media_files')]
class ShapovalMediaFiles extends SourcePluginBase {

  const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'shapoval_media_files';
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return ['path' => 'Path relative to uploads/', 'name' => 'File name', 'bundle' => 'Media bundle'];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['path' => ['type' => 'string']];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $db = Database::getConnection('default', 'migrate');
    $root = \Drupal::root();
    $paths = [];
    $add = function (string $html) use (&$paths) {
      foreach (BodyProcessor::collectUploadPaths($html) as $p) {
        $paths[$p] = $p;
      }
    };
    $result = $db->query('SELECT Text, Descr FROM {sections} WHERE LangID = 1');
    foreach ($result as $r) {
      $add($r->Text);
      $add($r->Descr);
    }
    foreach ($db->query("SELECT Icon, anonceicon, top_icon FROM {sections} WHERE LangID = 1") as $r) {
      foreach ([$r->Icon, $r->anonceicon, $r->top_icon] as $url) {
        if ($url && ($p = BodyProcessor::uploadsPath($url))) {
          $paths[$p] = $p;
        }
      }
    }
    foreach ($db->query('SELECT icon FROM {banners}') as $r) {
      if ($r->icon && ($p = BodyProcessor::uploadsPath($r->icon))) {
        $paths[$p] = $p;
      }
    }
    foreach ($db->query('SELECT default_image FROM {settings} WHERE LangID = 1') as $r) {
      if ($r->default_image && ($p = BodyProcessor::uploadsPath($r->default_image))) {
        $paths[$p] = $p;
      }
    }
    ksort($paths);
    $rows = [];
    foreach ($paths as $p) {
      if (!is_file($root . '/sites/default/files/uploads/' . $p) || strlen('public://uploads/' . $p) > 250) {
        continue;
      }
      $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
      $rows[] = [
        'path' => $p,
        'name' => basename($p),
        'bundle' => in_array($ext, self::IMAGE_EXT) ? 'image' : 'document',
      ];
    }
    return new \ArrayIterator($rows);
  }

}
