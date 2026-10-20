<?php

namespace Drupal\shapoval_migrate\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;
use Drupal\shapoval_migrate\BodyProcessor;

/**
 * Pages of the old site (structure + Russian row of sections).
 *
 * Configuration "kind": folders, pages, news, menu_main or menu_footer.
 */
#[MigrateSource('shapoval_structure')]
class ShapovalStructure extends SqlBase {

  public function query() {
    $kind = $this->configuration['kind'];
    $query = $this->select('structure', 's');
    $query->join('sections', 'x', 'x.StructID = s.ID AND x.LangID = 1');
    $query->leftJoin('structure', 'p', 'p.ID = s.ParentID');
    $query->fields('s', ['ID', 'ParentID', 'Level', 'Attr', 'SortOrder', 'FolderDoc', 'cpu']);
    $query->fields('x', ['Head', 'Text', 'Descr', 'Sdate', 'Icon', 'anonceicon', 'top_icon', 'extlink']);
    $query->addExpression('p.Attr', 'parent_attr');
    switch ($kind) {
      case 'folders':
        $query->condition('s.FolderDoc', 0);
        break;

      case 'pages':
        $query->condition('s.FolderDoc', 1);
        $query->where('(p.ID IS NULL OR (p.Attr & 2) = 0)');
        break;

      case 'news':
        $query->condition('s.FolderDoc', 1);
        $query->where('(p.Attr & 2) <> 0');
        break;

      case 'menu_main':
        $query->where('(s.Attr & 16) <> 0');
        break;

      case 'menu_footer':
        $query->where('(s.Attr & 32) <> 0');
        break;
    }
    $query->orderBy('s.Level')->orderBy('s.ID');
    return $query;
  }

  public function fields() {
    return [
      'ID' => 'Structure ID',
      'ParentID' => 'Parent ID',
      'cpu' => 'URL slug',
      'Head' => 'Title',
      'Text' => 'Body',
    ];
  }

  public function getIds() {
    return ['ID' => ['type' => 'integer', 'alias' => 's']];
  }

  public function prepareRow(Row $row) {
    $attr = (int) $row->getSourceProperty('Attr');
    $row->setSourceProperty('alias', '/rus/' . $row->getSourceProperty('cpu'));
    $sdate = (int) $row->getSourceProperty('Sdate');
    $row->setSourceProperty('created_ts', $sdate > 0 ? $sdate : BodyProcessor::FALLBACK_DATE);
    $row->setSourceProperty('promo', $attr & 4 ? 1 : 0);
    $pos = NULL;
    if ($attr & 512) {
      $pos = 'right';
    }
    elseif ($attr & 1024) {
      $pos = 'left';
    }
    elseif ($attr & 2048) {
      $pos = 'center';
    }
    $row->setSourceProperty('promo_position', $pos);
    $ext = trim($row->getSourceProperty('extlink'));
    $row->setSourceProperty('ext_uri', $ext !== '' ? BodyProcessor::linkUri($ext) : NULL);
    $row->setSourceProperty('link_uri', $ext !== '' ? BodyProcessor::linkUri($ext) : 'internal:/rus/' . $row->getSourceProperty('cpu'));
    $row->setSourceProperty('link_options', $attr & 64 ? ['attributes' => ['target' => '_blank']] : []);
    $parent = (int) $row->getSourceProperty('ParentID');
    $row->setSourceProperty('menu_parent_id', $parent ?: NULL);
    return parent::prepareRow($row);
  }

}
