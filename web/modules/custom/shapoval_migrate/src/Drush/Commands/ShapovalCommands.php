<?php

namespace Drupal\shapoval_migrate\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\shapoval_migrate\BodyProcessor;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Report and verification commands for the shapovalov.md migration.
 */
class ShapovalCommands extends DrushCommands {

  const REPORT_FILE = 'public://shapoval-migration-report.txt';

  /**
   * Problems found in the source data (step 15 of the migration plan).
   */
  #[CLI\Command(name: 'shapoval:report', aliases: ['shrep'])]
  #[CLI\Usage(name: 'drush shapoval:report', description: 'Print and save the migration report.')]
  public function report(): void {
    $db = Database::getConnection('default', 'migrate');
    $root = \Drupal::root();
    $lines = [];
    $query = $db->query("SELECT s.ID, s.cpu, s.FolderDoc, s.ParentID, x.Head, x.Text, x.Descr, x.Sdate, x.Icon, x.anonceicon, x.top_icon FROM {structure} s JOIN {sections} x ON x.StructID = s.ID AND x.LangID = 1 ORDER BY s.ID");
    $missing = $flash = $empty = $nosection = $nodate = $future = $scripts = $forms = [];
    $total_docs = 0;
    foreach ($query as $r) {
      $paths = BodyProcessor::collectUploadPaths($r->Text . $r->Descr);
      foreach ([$r->Icon, $r->anonceicon, $r->top_icon] as $url) {
        if ($url && ($p = BodyProcessor::uploadsPath($url))) {
          $paths[$p] = $p;
        }
      }
      foreach ($paths as $p) {
        if (!is_file($root . '/sites/default/files/uploads/' . $p)) {
          $missing[$p][] = $r->cpu;
        }
      }
      if (preg_match('~<script\b~i', $r->Text)) {
        $scripts[] = $r->cpu;
      }
      if (preg_match('~<form\b~i', $r->Text)) {
        $forms[] = $r->cpu;
      }
      if ($n = BodyProcessor::nonYoutubeObjects($r->Text)) {
        $flash[] = "{$r->cpu} ($n)";
      }
      if ($r->FolderDoc == 1) {
        $total_docs++;
        if (trim(strip_tags($r->Text)) === '' && !preg_match('~<(img|object|iframe|embed)\b~i', $r->Text)) {
          $empty[] = $r->cpu . ' — ' . $r->Head;
        }
        if ($r->ParentID == 0) {
          $nosection[] = $r->cpu;
        }
        if ($r->Sdate == 0) {
          $nodate[] = $r->cpu;
        }
        elseif ($r->Sdate > time()) {
          $future[] = $r->cpu . ' ' . date('Y-m-d', $r->Sdate);
        }
      }
    }
    $nodes = (int) \Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute();
    $lines[] = 'Documents in source (FolderDoc=1): ' . $total_docs . '; nodes in Drupal: ' . $nodes;
    $lines[] = 'Media in Drupal: image ' . $this->count('media', 'image') . ', document ' . $this->count('media', 'document') . ', remote_video ' . $this->count('media', 'remote_video');
    $lines[] = '';
    $lines[] = 'Files referenced by texts/icons but missing in uploads/ (' . count($missing) . '); links stay as /sites/default/files/uploads/..., fix manually:';
    foreach ($missing as $p => $cpus) {
      $lines[] = "  $p  <- " . implode(', ', array_slice(array_unique($cpus), 0, 3)) . (count(array_unique($cpus)) > 3 ? ', ...' : '');
    }
    $lines[] = '';
    $lines[] = 'Non-YouTube <object>/Flash left in text as is (' . count($flash) . ' pages): ' . implode(', ', $flash);
    $lines[] = '';
    $lines[] = 'Documents with empty body (' . count($empty) . '):';
    foreach ($empty as $e) {
      $lines[] = "  $e";
    }
    $lines[] = '';
    $lines[] = 'Documents without section (ParentID=0, ' . count($nosection) . '): ' . implode(', ', $nosection);
    $lines[] = 'Documents with Sdate=0, created set to 2008-07-03 (' . count($nodate) . '): ' . implode(', ', $nodate);
    $lines[] = 'Documents dated in the future (' . count($future) . '): ' . implode(', ', $future);
    $lines[] = 'Texts where <script> was removed (' . count($scripts) . '): ' . implode(', ', $scripts);
    $lines[] = 'Texts where <form> was removed (' . count($forms) . '): ' . implode(', ', $forms);
    $text = implode("\n", $lines) . "\n";
    file_put_contents(\Drupal::service('file_system')->realpath(self::REPORT_FILE), $text);
    $this->output()->write($text);
    $this->logger()->success('Saved to ' . self::REPORT_FILE);
  }

  /**
   * {@inheritdoc}
   */
  protected function count(string $type, string $bundle): int {
    return (int) \Drupal::entityTypeManager()->getStorage($type)->getQuery()->accessCheck(FALSE)->condition('bundle', $bundle)->count()->execute();
  }

  /**
   * Checks that migrated and legacy URLs of the old site respond with 200.
   */
  #[CLI\Command(name: 'shapoval:verify-urls', aliases: ['shver'])]
  #[CLI\Argument(name: 'base', description: 'Base URL of the new site.')]
  #[CLI\Option(name: 'old-root', description: 'Directory of the old site with urllist.txt and sitemap.xml.')]
  public function verifyUrls(string $base = 'http://new_drupal.loc', array $options = ['old-root' => '/var/www/shapovalov.loc']): void {
    $base = rtrim($base, '/');
    $db = Database::getConnection('default', 'migrate');
    $checks = [];
    foreach ($db->query('SELECT cpu FROM {structure}') as $r) {
      $checks['cpu'][] = '/rus/' . $r->cpu;
    }
    $old = [];
    foreach (['urllist.txt'] as $f) {
      foreach (file($options['old-root'] . '/' . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $u) {
        $old[$u] = $u;
      }
    }
    if (is_file($options['old-root'] . '/sitemap.xml') && preg_match_all('~<loc>(.*?)</loc>~', file_get_contents($options['old-root'] . '/sitemap.xml'), $m)) {
      foreach ($m[1] as $u) {
        $old[html_entity_decode($u)] = html_entity_decode($u);
      }
    }
    foreach ($old as $u) {
      $parts = parse_url($u);
      $checks['old'][] = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    $checks['old'] = array_values(array_unique($checks['old']));
    $client = new Client(['http_errors' => FALSE, 'allow_redirects' => ['max' => 5, 'track_redirects' => TRUE], 'timeout' => 60]);
    $failed = [];
    foreach ($checks as $group => $paths) {
      $stats = ['200' => 0, 'redirected' => 0, 'fail' => 0];
      $requests = function () use ($paths, $base) {
        foreach ($paths as $i => $path) {
          yield $i => new Request('GET', $base . $path);
        }
      };
      $pool = new Pool($client, $requests(), [
        'concurrency' => 8,
        'fulfilled' => function (Response $response, $i) use ($paths, $group, &$stats, &$failed) {
          if ($response->getStatusCode() === 200) {
            $stats[$response->getHeader('X-Guzzle-Redirect-History') ? 'redirected' : '200']++;
          }
          else {
            $stats['fail']++;
            $failed[] = "[$group] {$paths[$i]} -> " . $response->getStatusCode();
          }
        },
        'rejected' => function ($reason, $i) use ($paths, $group, &$stats, &$failed) {
          $stats['fail']++;
          $failed[] = "[$group] {$paths[$i]} -> error";
        },
      ]);
      $pool->promise()->wait();
      $this->output()->writeln(sprintf('%s: %d URLs, direct 200: %d, 200 after redirect: %d, failed: %d', $group, count($paths), $stats['200'], $stats['redirected'], $stats['fail']));
    }
    // Classify failures of old URLs: old pagination (/<lang>/<cpu>/<N>, not
    // redirected by decision) and cpu values that are not in the dump at all.
    $known = [];
    foreach ($db->query('SELECT LOWER(cpu) AS cpu FROM {structure}') as $r) {
      $known[$r->cpu] = TRUE;
    }
    $classes = ['pagination' => [], 'stale_cpu' => [], 'other' => []];
    foreach ($failed as $f) {
      if (preg_match('~^\[old\] /(?:rus|rom|eng)/([^/?\s]+)(/\d+)?/?\s~', $f, $m)) {
        $key = isset($known[strtolower($m[1])]) ? (!empty($m[2]) ? 'pagination' : 'other') : 'stale_cpu';
      }
      else {
        $key = 'other';
      }
      $classes[$key][] = $f;
    }
    foreach ($classes as $key => $list) {
      $this->output()->writeln(sprintf('failed old URLs, %s: %d%s', $key, count($list), $key === 'stale_cpu' ? ' (' . count(array_unique(array_map(fn($f) => strtolower(preg_replace('~^\[old\] /\w+/([^/?\s]+).*~', '$1', $f)), $list))) . ' distinct cpu values)' : ''));
    }
    foreach ($classes['other'] as $f) {
      $this->output()->writeln($f);
    }
    file_put_contents(\Drupal::service('file_system')->realpath('public://shapoval-url-check.txt'), implode("\n", $failed) . "\n");
  }

  /**
   * Pre-generates image style derivatives of all migrated images.
   *
   * Derivatives are created on demand when a page is first viewed, so this is
   * optional: it only avoids a slow first view and makes the site independent
   * of the web server being able to write to files/styles on a request.
   */
  #[CLI\Command(name: 'shapoval:derivatives', aliases: ['shder'])]
  #[CLI\Option(name: 'styles', description: 'Comma-separated image style IDs.')]
  #[CLI\Usage(name: 'drush shapoval:derivatives', description: 'Create shapoval_content, shapoval_icon and shapoval_card derivatives.')]
  public function derivatives(array $options = ['styles' => 'shapoval_content,shapoval_icon,shapoval_card']): void {
    $styles = \Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple(array_filter(array_map('trim', explode(',', $options['styles']))));
    $ids = \Drupal::entityQuery('file')->accessCheck(FALSE)->condition('filemime', 'image/%', 'LIKE')->execute();
    $created = 0;
    foreach (\Drupal::entityTypeManager()->getStorage('file')->loadMultiple($ids) as $file) {
      foreach ($styles as $style) {
        $dest = $style->buildUri($file->getFileUri());
        if (!file_exists($dest) && is_file($file->getFileUri()) && $style->createDerivative($file->getFileUri(), $dest)) {
          $created++;
        }
      }
    }
    $this->output()->writeln(sprintf('Created %d derivatives for %d images.', $created, count($ids)));
  }

}
