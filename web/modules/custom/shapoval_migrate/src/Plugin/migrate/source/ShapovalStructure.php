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

  /**
   * {@inheritdoc}
   */
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

      // The old menus (menu.php getSimpleMenu) show root pages only: a flagged
      // page with a parent (e.g. "Встречи 2015") is not a menu item there.
      case 'menu_main':
        $query->where('(s.Attr & 16) <> 0');
        $query->condition('s.ParentID', 0);
        break;

      case 'menu_footer':
        $query->where('(s.Attr & 32) <> 0');
        $query->condition('s.ParentID', 0);
        break;
    }
    $query->orderBy('s.Level')->orderBy('s.ID');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'ID' => 'Structure ID',
      'ParentID' => 'Parent ID',
      'cpu' => 'URL slug',
      'Head' => 'Title',
      'Text' => 'Body',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['ID' => ['type' => 'integer', 'alias' => 's']];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $attr = (int) $row->getSourceProperty('Attr');
    $row->setSourceProperty('alias', '/rus/' . $row->getSourceProperty('cpu'));
    $sdate = (int) $row->getSourceProperty('Sdate');
    $row->setSourceProperty('created_ts', $sdate > 0 ? $sdate : BodyProcessor::FALLBACK_DATE);
    $row->setSourceProperty('promo', $attr & 4 ? 1 : 0);
    // One image field: the icon first, the picture of the announce if there is no icon.
    $row->setSourceProperty('image_url', trim($row->getSourceProperty('Icon')) !== '' ? trim($row->getSourceProperty('Icon')) : trim((string) $row->getSourceProperty('anonceicon')));
    $ext = trim($row->getSourceProperty('extlink'));
    $row->setSourceProperty('ext_uri', $ext !== '' ? BodyProcessor::linkUri($ext) : NULL);
    $row->setSourceProperty('link_uri', $ext !== '' ? BodyProcessor::linkUri($ext) : 'internal:/rus/' . $row->getSourceProperty('cpu'));
    $row->setSourceProperty('link_options', $attr & 64 ? ['attributes' => ['target' => '_blank']] : []);
    $parent = (int) $row->getSourceProperty('ParentID');
    $row->setSourceProperty('menu_parent_id', $parent ?: NULL);
    return parent::prepareRow($row);
  }

}
