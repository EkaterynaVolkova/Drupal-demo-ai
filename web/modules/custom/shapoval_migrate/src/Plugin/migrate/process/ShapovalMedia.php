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
    $candidates = array_filter(array_map('trim', array_map('strval', (array) $value)));
    // 1. The first candidate (icon, then the announce picture) that is a
    // migrated upload with a file.
    foreach ($candidates as $url) {
      $path = BodyProcessor::uploadsPath($url);
      if ($path !== NULL && ($id = $this->processor->mediaId($path))) {
        return $id;
      }
    }
    // 2. Otherwise the first external hot-link that can still be downloaded.
    foreach ($candidates as $url) {
      if (BodyProcessor::uploadsPath($url) === NULL && preg_match('~^https?://~i', $url) && ($id = $this->externalMedia($url))) {
        return $id;
      }
    }
    return NULL;
  }

  /**
   * Downloads an external picture once and returns the ID of its Media.
   *
   * Files go to uploads/external/<host>/<hash>.<ext>; an existing file is not
   * downloaded again. Failures are collected in the state "shapoval_migrate.
   * external_failed" for the report.
   */
  protected function externalMedia(string $url): ?int {
    static $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $host = preg_replace('~[^a-z0-9.-]~', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    $hash = md5($url);
    $dir = 'public://uploads/external/' . $host;
    $fs = \Drupal::service('file_system');
    $uri = NULL;
    foreach ($types as $ext) {
      if (is_file($fs->realpath($dir) . '/' . $hash . '.' . $ext)) {
        $uri = $dir . '/' . $hash . '.' . $ext;
        break;
      }
    }
    if ($uri === NULL) {
      try {
        $response = \Drupal::httpClient()->get($url, [
          'timeout' => 8,
          'connect_timeout' => 4,
          'headers' => ['User-Agent' => 'Mozilla/5.0'],
        ]);
        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if (!isset($types[$type])) {
          throw new \RuntimeException('not an image: ' . $type);
        }
        $fs->prepareDirectory($dir, $fs::CREATE_DIRECTORY | $fs::MODIFY_PERMISSIONS);
        $uri = $dir . '/' . $hash . '.' . $types[$type];
        $fs->saveData((string) $response->getBody(), $uri, $fs::EXISTS_REPLACE);
      }
      catch (\Throwable $e) {
        $state = \Drupal::state();
        $failed = $state->get('shapoval_migrate.external_failed', []);
        $failed[$url] = $e->getMessage();
        $state->set('shapoval_migrate.external_failed', $failed);
        return NULL;
      }
    }
    $etm = \Drupal::entityTypeManager();
    $files = $etm->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = $files ? reset($files) : \Drupal\file\Entity\File::create(['uri' => $uri, 'uid' => 1, 'status' => 1]);
    if ($file->isNew()) {
      $file->save();
    }
    $found = $etm->getStorage('media')->loadByProperties(['bundle' => 'image', 'field_media_image' => $file->id()]);
    if ($found) {
      return (int) reset($found)->id();
    }
    $media = $etm->getStorage('media')->create(['bundle' => 'image', 'uid' => 1, 'status' => 1, 'name' => basename($uri), 'field_media_image' => ['target_id' => $file->id()]]);
    $media->save();
    return (int) $media->id();
  }

}
