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
 * - <font> becomes a styled span; from inline styles only the visual
 *   properties of the old design survive (colour, background, weight, size,
 *   alignment, and width/height of cells and images);
 * - layout tables keep their structure and attributes (border, cell spacing,
 *   widths, valign, align, bgcolor);
 * - empty paragraphs are removed.
 */
class BodyProcessor {

  const URI_BASE = '/sites/default/files/uploads/';

  const FALLBACK_DATE = 1215089599;

  /**
   * Legacy mode: old articles keep their layout (the format is Full HTML).
   *
   * Pictures stay plain <img> with their width/height/align/style (not
   * <drupal-media>, which would replace the size and position with the theme's),
   * layout tables and empty spacer paragraphs stay as they are. The Media
   * entities of the same files are still created by the media_files migration.
   */
  const KEEP_LEGACY_LAYOUT = TRUE;

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

  /**
   * {@inheritdoc}
   */
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

  /**
   * {@inheritdoc}
   */
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

    // Forms (old subscription widgets) cannot work here; neither can counters.
    foreach (iterator_to_array($xpath->query('//form | //input | //select | //textarea | //button | //noscript | //div[starts-with(@id, "subscr-block")]')) as $el) {
      $el->parentNode?->removeChild($el);
    }

    $this->removeSubscription($dom, $xpath);

    // Video.
    foreach (iterator_to_array($xpath->query('//object | //embed[not(ancestor::object)] | //iframe[contains(@src, "youtube")]')) as $node) {
      $id = self::youtubeId($dom->saveHTML($node));
      if ($id && ($uuid = $this->remoteVideoUuid($id))) {
        $node->parentNode->replaceChild($this->mediaElement($dom, $uuid), $node);
      }
    }

    // Images.
    foreach (iterator_to_array($xpath->query('//img[@src]')) as $img) {
      if (self::KEEP_LEGACY_LAYOUT) {
        $img->setAttribute('loading', 'lazy');
        continue;
      }
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
        // The rendered <span class="media"> inherits this style: old pages
        // size their pictures with width/height attributes.
        if (($w = $this->numericSize($img, 'width')) !== NULL) {
          $el->setAttribute('style', 'width:' . $w . 'px');
        }
        $img->parentNode->replaceChild($el, $img);
      }
      elseif ($align) {
        $img->setAttribute('data-align', $align);
      }
    }

    // <font color size> to <span style>.
    foreach (iterator_to_array($xpath->query('//font')) as $font) {
      $css = [];
      if (preg_match('~^#?([0-9a-f]{3}|[0-9a-f]{6})$~i', trim($font->getAttribute('color')), $m)) {
        $css[] = 'color:#' . $m[1];
      }
      $sizes = [1 => 'x-small', 2 => 'small', 3 => 'medium', 4 => 'large', 5 => 'x-large', 6 => 'xx-large', 7 => 'xxx-large'];
      $size = (int) $font->getAttribute('size');
      if (isset($sizes[$size]) && $size !== 3) {
        $css[] = 'font-size:' . $sizes[$size];
      }
      $span = $dom->createElement('span');
      if ($css) {
        $span->setAttribute('style', implode(';', $css));
      }
      while ($font->firstChild) {
        $span->appendChild($font->firstChild);
      }
      $font->parentNode->replaceChild($span, $font);
    }

    // Bgcolor / width attributes of table parts to the style that survives.
    foreach ($xpath->query('//td | //th | //tr | //table') as $el) {
      $css = [];
      if (preg_match('~^#?([0-9a-f]{3}|[0-9a-f]{6})$~i', trim($el->getAttribute('bgcolor')), $m)) {
        $css[] = 'background-color:#' . $m[1];
      }
      // CKEditor 5 drops table[align] on save; the style survives, so convert it.
      if ($el->nodeName === 'table') {
        $css[] = match (strtolower($el->getAttribute('align'))) {
          'center' => 'margin-left:auto;margin-right:auto',
          'left' => 'float:left',
          'right' => 'float:right',
          default => NULL,
        };
        $css = array_filter($css);
      }
      if ($css) {
        $style = $el->getAttribute('style');
        $el->setAttribute('style', ($style !== '' ? rtrim($style, ';') . ';' : '') . implode(';', $css));
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

    // Scripting URLs (javascript:, data:) never survive.
    foreach (iterator_to_array($xpath->query('//*[@href or @src]')) as $el) {
      foreach (['href', 'src'] as $name) {
        if ($el->hasAttribute($name) && preg_match('~^\s*(javascript|data|vbscript):~i', $el->getAttribute($name))) {
          $el->removeAttribute($name);
        }
      }
    }

    // Attribute whitelist.
    $allowed = [
      'a' => ['href', 'name', 'target', 'title', 'rel'],
      'img' => ['src', 'alt', 'title', 'width', 'height', 'align', 'border', 'hspace', 'vspace', 'loading', 'data-align'],
      'table' => ['border', 'cellspacing', 'cellpadding', 'width', 'height'],
      'tr' => ['valign', 'align'],
      'td' => ['colspan', 'rowspan', 'valign', 'width', 'height', 'align', 'class', 'nowrap'],
      'th' => ['colspan', 'rowspan', 'valign', 'width', 'height', 'align', 'class', 'nowrap'],
      'hr' => ['width', 'size', 'align', 'noshade'],
      'ul' => ['type'],
      'iframe' => ['src', 'width', 'height', 'allowfullscreen'],
      'object' => ['data', 'type', 'width', 'height'],
      'param' => ['name', 'value'],
      'embed' => ['src', 'type', 'width', 'height', 'allowfullscreen'],
      'drupal-media' => ['data-entity-type', 'data-entity-uuid', 'data-align', 'alt', 'style'],
      'ol' => ['start', 'type'],
      'li' => ['value'],
    ];
    foreach (iterator_to_array($xpath->query('//*')) as $el) {
      $keep = $allowed[$el->nodeName] ?? [];
      $style = $this->filterStyle($el->getAttribute('style'), $el->nodeName, $highlight);
      if ($highlight) {
        $el->setAttribute('class', trim($el->getAttribute('class') . ' ' . $highlight));
      }
      foreach (iterator_to_array($el->attributes) as $attr) {
        $ok = in_array($attr->name, $keep, TRUE);
        if ($attr->name === 'class') {
          $classes = array_filter(explode(' ', $attr->value), fn($c) => preg_match('~^(text-align-(center|right|justify)|hl-(red|blue))$~', $c));
          $ok = (bool) $classes;
          if ($ok) {
            $attr->value = implode(' ', $classes);
          }
        }
        if ($attr->name === 'style') {
          $ok = $el->nodeName === 'drupal-media' ? $ok : FALSE;
        }
        if (!$ok) {
          $el->removeAttribute($attr->name);
        }
      }
      if ($style !== '' && $el->nodeName !== 'drupal-media') {
        $el->setAttribute('style', $style);
      }
    }

    // Unwrap presentational tags.
    foreach (iterator_to_array($xpath->query('//span[not(@style) and not(@class)] | //*[starts-with(name(), "o:")]')) as $el) {
      $this->unwrap($el);
    }

    // Empty paragraphs/blocks.
    foreach (array_reverse(iterator_to_array($xpath->query('//p | //div | //strong | //em | //b | //i | //u | //a[not(@href)][not(@name)]'))) as $el) {
      // Paragraphs with only &nbsp; are the old spacers: keep them.
      $text = trim($el->textContent, " \t\n\r\0\x0B");
      if ($text === '' && !$xpath->query('.//img | .//drupal-media | .//object | .//iframe | .//embed | .//table | .//a[@name]', $el)->length) {
        $el->parentNode->removeChild($el);
      }
    }

    return trim(Html::serialize($dom));
  }

  /**
   * Keeps only the visual style properties of the old design.
   *
   * Width and height survive for cells, tables and images only.
   */
  protected function filterStyle(string $style, string $tag, ?string &$highlight = NULL): string {
    $highlight = NULL;
    $allowed = [
      'color', 'background-color', 'font-weight', 'font-size', 'font-style', 'font-family', 'text-decoration',
      'text-align', 'text-indent', 'line-height', 'vertical-align', 'float', 'clear',
      'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
      'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
      'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-width', 'border-style', 'border-collapse',
      'list-style-type', 'display',
    ];
    if (in_array($tag, ['table', 'td', 'th', 'img', 'div', 'p', 'iframe'], TRUE)) {
      $allowed = array_merge($allowed, ['width', 'height']);
    }
    $keep = [];
    foreach (explode(';', $style) as $decl) {
      if (!str_contains($decl, ':')) {
        continue;
      }
      [$prop, $value] = array_map('trim', explode(':', $decl, 2));
      $prop = strtolower($prop);
      $value = preg_replace('~\s*!important$~i', '', $value);
      if (!in_array($prop, $allowed, TRUE) || $value === '' || preg_match('~[<>{}\\\\]|expression|url\\(~i', $value)) {
        continue;
      }
      if ($prop === 'display' && !in_array(strtolower($value), ['block', 'inline', 'inline-block', 'table', 'table-cell'], TRUE)) {
        continue;
      }
      // Meaningless defaults produced by editors.
      if (($prop === 'color' && preg_match('~^(#000(000)?|black|windowtext|inherit)$~i', $value))
        || ($prop === 'background-color' && preg_match('~^(transparent|inherit|#fff(fff)?|white)$~i', $value) && $tag !== 'span')
        || ($prop === 'font-weight' && in_array(strtolower($value), ['normal', '400', 'inherit'], TRUE))) {
        continue;
      }
      if ($prop === 'color' && ($class = self::highlightClass($value))) {
        $highlight = $class;
        continue;
      }
      $keep[$prop] = $prop . ':' . $value;
    }
    return implode(';', $keep);
  }

  /**
   * The semantic accent class for the red and blue text colours of the old
   * site (#ff0000, #cc0000, red, #0000ff, #3366ff, blue...); other colours
   * stay inline. The shades are defined in the theme (--hl-red, --hl-blue).
   */
  public static function highlightClass(string $value): ?string {
    $value = strtolower(trim($value));
    $names = ['red' => '#ff0000', 'blue' => '#0000ff'];
    $value = $names[$value] ?? $value;
    if (!preg_match('~^#([0-9a-f]{3}|[0-9a-f]{6})$~', $value, $m)) {
      return NULL;
    }
    $hex = strlen($m[1]) === 3 ? preg_replace('~(.)~', '$1$1', $m[1]) : $m[1];
    [$r, $g, $b] = array_map(fn($c) => hexdec($c) / 255, str_split($hex, 2));
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    if ($max < 0.5 || $max === 0.0 || ($max - $min) / $max < 0.8) {
      return NULL;
    }
    $d = $max - $min;
    $hue = match (TRUE) {
      $max === $r => fmod(($g - $b) / $d, 6) * 60,
      $max === $g => (($b - $r) / $d + 2) * 60,
      default => (($r - $g) / $d + 4) * 60,
    };
    $hue = fmod($hue + 360, 360);
    if ($hue <= 15 || $hue >= 345) {
      return 'hl-red';
    }
    return $hue >= 215 && $hue <= 255 ? 'hl-blue' : NULL;
  }

  /**
   * Numeric width/height of an <img> from its attribute or style.
   */
  protected function numericSize(\DOMElement $img, string $dim): ?int {
    if (preg_match('~^\s*(\d+)\s*(?:px)?\s*$~', $img->getAttribute($dim), $m)) {
      return (int) $m[1];
    }
    if (preg_match('~(?:^|;)\s*' . $dim . '\s*:\s*(\d+)px~i', $img->getAttribute('style'), $m)) {
      return (int) $m[1];
    }
    return NULL;
  }

  /**
   * Removes the mailing-list promotions ("Подпишитесь на нашу рассылку и
   * получите подарок", "Рассылка+ подарок!"): the subscription forms cannot
   * work on the new site. Whole boxes, paragraphs or spacer pairs go; the
   * neighbouring layout stays intact.
   */
  protected function removeSubscription(\DOMDocument $dom, \DOMXPath $xpath): void {
    $text = fn(\DOMNode $n) => trim(preg_replace('~\s+~u', ' ', str_replace("\xc2\xa0", ' ', $n->textContent)));
    // Boxes (tables) with the offer; only the outermost one.
    $tables = [];
    foreach ($xpath->query('//table') as $t) {
      if (str_contains($text($t), 'Подпишитесь на нашу рассылку')) {
        $tables[] = $t;
      }
    }
    foreach ($tables as $t) {
      $outer = FALSE;
      for ($a = $t->parentNode; $a; $a = $a->parentNode) {
        if ($a->nodeName === 'table' && in_array($a, $tables, TRUE)) {
          $outer = TRUE;
        }
      }
      if (!$outer && $t->parentNode) {
        $t->parentNode->removeChild($t);
      }
    }
    // Paragraphs left behind (monthly material pages).
    foreach (iterator_to_array($xpath->query('//p')) as $p) {
      $t = $text($p);
      if (preg_match('~^(Подпишитесь на рассылку!?|Хотите каждый месяц получать свежий материал.*|Рассылка\+)$~u', $t) && $p->parentNode) {
        // "Рассылка+" is followed by "подарок!".
        if ($t === 'Рассылка+' && ($n = $p->nextSibling)) {
          while ($n && $n->nodeType !== XML_ELEMENT_NODE) {
            $n = $n->nextSibling;
          }
          if ($n && $n->nodeName === 'p' && preg_match('~^подарок!?$~ui', $text($n))) {
            $n->parentNode->removeChild($n);
          }
        }
        $p->parentNode->removeChild($p);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function unwrap(\DOMNode $el): void {
    while ($el->firstChild) {
      $el->parentNode->insertBefore($el->firstChild, $el);
    }
    $el->parentNode->removeChild($el);
  }

  /**
   * {@inheritdoc}
   */
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

  /**
   * {@inheritdoc}
   */
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

  /**
   * {@inheritdoc}
   */
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
