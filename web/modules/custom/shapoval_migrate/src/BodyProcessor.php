<?php

namespace Drupal\shapoval_migrate;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Converts HTML bodies of the old CMS into clean Drupal HTML.
 *
 * - uploads/ links are rewritten to /sites/default/files/uploads/;
 * - <img> of existing uploads become <drupal-media>;
 * - YouTube <object>/<embed> become <drupal-media> of a Remote video;
 * - inline styles, <font>, empty paragraphs and layout tables are removed.
 */
class BodyProcessor {

  const URI_BASE = '/sites/default/files/uploads/';

  const FALLBACK_DATE = 1215089599;

  /**
   * Media UUIDs keyed by path relative to uploads/.
   */
  protected ?array $mediaUuids = NULL;

  /**
   * Remote video media UUIDs keyed by YouTube ID.
   */
  protected array $videoUuids = [];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected string $root,
  ) {}

  /**
   * Returns the path relative to uploads/ for a URL, or NULL if not an upload.
   */
  public static function uploadsPath(string $url): ?string {
    $url = html_entity_decode(trim($url));
    $url = preg_replace('~^https?://(?:www\.)?shapovalov\.md~i', '', $url);
    if (!preg_match('~^/?(?:sites/default/files/)?uploads/([^?#]+)~i', $url, $m)) {
      return NULL;
    }
    $path = rawurldecode($m[1]);
    return str_contains($path, '..') ? NULL : $path;
  }

  public function fileExists(string $path): bool {
    return is_file($this->root . '/sites/default/files/uploads/' . $path);
  }

  /**
   * Normalizes a link from the old site (extlink, menu) to a Drupal URI.
   */
  public static function linkUri(string $url): string {
    $url = trim($url);
    if (preg_match('~^https?://(?:www\.)?shapovalov\.md(/rus/[^?#\s]*)?/?$~i', $url, $m)) {
      return 'internal:' . ($m[1] ?? '/');
    }
    if (preg_match('~^[a-z]+://~i', $url)) {
      return $url;
    }
    return 'internal:/' . ltrim($url, '/');
  }

  /**
   * Collects all uploads/ paths referenced from src/href in an HTML string.
   */
  public static function collectUploadPaths(string $html): array {
    $paths = [];
    if (preg_match_all('~\b(?:src|href)\s*=\s*(["\'])(.*?)\1~is', $html, $m)) {
      foreach ($m[2] as $url) {
        if (($p = self::uploadsPath($url)) !== NULL && $p !== '') {
          $paths[$p] = $p;
        }
      }
    }
    return $paths;
  }

  public static function youtubeId(string $html): ?string {
    if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:v|embed)/|youtu\.be/|[?&]v=)([A-Za-z0-9_-]{11})~', $html, $m)) {
      return $m[1];
    }
    return NULL;
  }

  /**
   * Finds flash/object embeds that are not YouTube.
   */
  public static function nonYoutubeObjects(string $html): int {
    $count = 0;
    if (preg_match_all('~<object\b.*?</object>~is', $html, $m)) {
      foreach ($m[0] as $obj) {
        if (!self::youtubeId($obj)) {
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * Cleans one HTML fragment.
   */
  public function process(string $html): string {
    if (trim($html) === '') {
      return '';
    }
    $html = preg_replace(['~<!--.*?-->~s', '~<script\b.*?</script>~is', '~<style\b.*?</style>~is'], '', $html);
    $html = preg_replace_callback('~\b(src|href)\s*=\s*(["\'])(.*?)\2~is', function ($m) {
      $url = preg_replace('~^(?:https?://(?:www\.)?shapovalov\.md)?/?uploads/~i', self::URI_BASE, $m[3]);
      $url = preg_replace('~^https?://(?:www\.)?shapovalov\.md(/rus/)~i', '$1', $url);
      return $m[1] . '=' . $m[2] . $url . $m[2];
    }, $html);

    $dom = Html::load($html);
    $xpath = new \DOMXPath($dom);

    // Forms (old subscription widgets) cannot work here.
    foreach (iterator_to_array($xpath->query('//form | //input | //select | //textarea | //button')) as $el) {
      $el->parentNode?->removeChild($el);
    }

    // Video.
    foreach (iterator_to_array($xpath->query('//object | //embed[not(ancestor::object)] | //iframe[contains(@src, "youtube")]')) as $node) {
      $id = self::youtubeId($dom->saveHTML($node));
      if ($id && ($uuid = $this->remoteVideoUuid($id))) {
        $node->parentNode->replaceChild($this->mediaElement($dom, $uuid), $node);
      }
    }

    // Images.
    foreach (iterator_to_array($xpath->query('//img[@src]')) as $img) {
      $align = $this->alignment($img);
      $path = self::uploadsPath($img->getAttribute('src'));
      $uuid = $path !== NULL ? $this->mediaUuid($path) : NULL;
      if ($uuid) {
        $el = $this->mediaElement($dom, $uuid);
        if (trim($img->getAttribute('alt')) !== '') {
          $el->setAttribute('alt', trim($img->getAttribute('alt')));
        }
        if ($align) {
          $el->setAttribute('data-align', $align);
        }
        $img->parentNode->replaceChild($el, $img);
      }
      elseif ($align) {
        $img->setAttribute('data-align', $align);
      }
    }

    // Text alignment to classes before styles are dropped.
    foreach ($xpath->query('//*[@style or @align]') as $el) {
      if (in_array($el->nodeName, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'center'])) {
        $a = $el->getAttribute('align');
        if (preg_match('~text-align\s*:\s*(center|right|justify)~i', $el->getAttribute('style'), $m)) {
          $a = $m[1];
        }
        if (in_array(strtolower($a), ['center', 'right', 'justify'])) {
          $el->setAttribute('class', 'text-align-' . strtolower($a));
        }
      }
    }
    foreach (iterator_to_array($xpath->query('//center')) as $center) {
      $div = $dom->createElement('div');
      $div->setAttribute('class', 'text-align-center');
      while ($center->firstChild) {
        $div->appendChild($center->firstChild);
      }
      $center->parentNode->replaceChild($div, $center);
    }

    // Attribute whitelist.
    $allowed = [
      'a' => ['href', 'name', 'target', 'title', 'rel'],
      'img' => ['src', 'alt', 'title', 'width', 'height', 'data-align'],
      'td' => ['colspan', 'rowspan', 'class'],
      'th' => ['colspan', 'rowspan', 'class'],
      'iframe' => ['src', 'width', 'height', 'allowfullscreen'],
      'object' => ['data', 'type', 'width', 'height'],
      'param' => ['name', 'value'],
      'embed' => ['src', 'type', 'width', 'height', 'allowfullscreen'],
      'drupal-media' => ['data-entity-type', 'data-entity-uuid', 'data-align', 'alt'],
      'ol' => ['start', 'type'],
    ];
    foreach (iterator_to_array($xpath->query('//*')) as $el) {
      $keep = $allowed[$el->nodeName] ?? [];
      foreach (iterator_to_array($el->attributes) as $attr) {
        $ok = in_array($attr->name, $keep, TRUE);
        if ($attr->name === 'class') {
          $ok = (bool) preg_match('~^text-align-(center|right|justify)$~', $attr->value);
        }
        if (!$ok) {
          $el->removeAttribute($attr->name);
        }
      }
    }

    // Unwrap presentational tags.
    foreach (iterator_to_array($xpath->query('//font | //span | //*[starts-with(name(), "o:")]')) as $el) {
      $this->unwrap($el);
    }

    // Layout tables with a single cell.
    foreach (array_reverse(iterator_to_array($xpath->query('//table'))) as $table) {
      if ($xpath->query('.//tr/td | .//tr/th', $table)->length === 1 && $xpath->query('.//tr', $table)->length === 1) {
        $cell = $xpath->query('.//tr/td | .//tr/th', $table)->item(0);
        $frag = $dom->createDocumentFragment();
        while ($cell->firstChild) {
          $frag->appendChild($cell->firstChild);
        }
        $table->parentNode->replaceChild($frag, $table);
      }
    }

    // Empty paragraphs/blocks.
    foreach (array_reverse(iterator_to_array($xpath->query('//p | //div | //strong | //em | //b | //i | //u | //a[not(@href)][not(@name)]'))) as $el) {
      $text = trim(str_replace("\xc2\xa0", ' ', $el->textContent));
      if ($text === '' && !$xpath->query('.//img | .//drupal-media | .//object | .//iframe | .//embed | .//table | .//a[@name]', $el)->length) {
        $el->parentNode->removeChild($el);
      }
    }

    return trim(Html::serialize($dom));
  }

  protected function unwrap(\DOMNode $el): void {
    while ($el->firstChild) {
      $el->parentNode->insertBefore($el->firstChild, $el);
    }
    $el->parentNode->removeChild($el);
  }

  protected function alignment(\DOMElement $img): ?string {
    $style = $img->getAttribute('style');
    if (preg_match('~float\s*:\s*(left|right)~i', $style, $m)) {
      return strtolower($m[1]);
    }
    $a = strtolower($img->getAttribute('align'));
    if (in_array($a, ['left', 'right'])) {
      return $a;
    }
    return NULL;
  }

  protected function mediaElement(\DOMDocument $dom, string $uuid): \DOMElement {
    $el = $dom->createElement('drupal-media');
    $el->setAttribute('data-entity-type', 'media');
    $el->setAttribute('data-entity-uuid', $uuid);
    return $el;
  }

  /**
   * UUID of the migrated Media for an uploads path.
   */
  public function mediaUuid(string $path): ?string {
    if ($this->mediaUuids === NULL) {
      $this->mediaUuids = [];
      if ($this->database->schema()->tableExists('migrate_map_media_files')) {
        $q = $this->database->select('migrate_map_media_files', 'm');
        $q->join('media', 'e', 'e.mid = m.destid1');
        $q->fields('m', ['sourceid1']);
        $q->fields('e', ['uuid']);
        foreach ($q->execute() as $r) {
          $this->mediaUuids[$r->sourceid1] = $r->uuid;
        }
      }
    }
    return $this->mediaUuids[$path] ?? NULL;
  }

  public function mediaId(string $path): ?int {
    if (!($uuid = $this->mediaUuid($path))) {
      return NULL;
    }
    $ids = $this->entityTypeManager->getStorage('media')->getQuery()->accessCheck(FALSE)->condition('uuid', $uuid)->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * Gets or creates a Remote video media for a YouTube ID.
   */
  public function remoteVideoUuid(string $id): ?string {
    if (isset($this->videoUuids[$id])) {
      return $this->videoUuids[$id];
    }
    $storage = $this->entityTypeManager->getStorage('media');
    $url = 'https://www.youtube.com/watch?v=' . $id;
    $found = $storage->loadByProperties(['bundle' => 'remote_video', 'field_media_oembed_video' => $url]);
    try {
      $media = $found ? reset($found) : $storage->create(['bundle' => 'remote_video', 'uid' => 1, 'status' => 1, 'field_media_oembed_video' => $url]);
      if (!$found) {
        $media->save();
      }
      return $this->videoUuids[$id] = $media->uuid();
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

}
