# Reusable theme (Drupal 11)

`project_base` is a standalone theme generated from core Starterkit (`base theme: false`). It is a full copy owned by the project: edit its templates, CSS and hooks directly. Core does not update it.

## Use in a new project

1. Copy `project_base/` to `web/themes/custom/`.
2. Rename: folder, `*.info.yml`, `*.libraries.yml`, `src/Hook/ProjectBaseHooks.php` (class name), and all `project_base` / `Project Base` occurrences (library names, `libraries-extend`).
3. Enable: `drush theme:install my_theme && drush config:set system.theme default my_theme -y && drush cr`

## Working notes

- Twig files in `templates/` override core; run `drush cr` after adding one.
- Plain CSS, no build step. Component CSS in `css/components/` is attached through `libraries-extend` in `*.info.yml`.
- Hooks live in `src/Hook/` (OOP hooks).
- `generator:` in `*.info.yml` records the core version it was generated from.

## Moving to a new major (e.g. Drupal 12)

No regeneration needed. Update `core_version_requirement` (e.g. `^11 || ^12`), run `drupal-check`/`upgrade_status`, fix deprecations. To pick up markup changes from newer core, generate a fresh Starterkit into a temp folder and diff it against this theme.

## Regenerating from Starterkit

The core script expects `vendor/` next to `web/`; from the project root:

```bash
ln -s ../vendor web/vendor
php web/core/scripts/dr generate-theme my_theme --path=themes/custom
rm web/vendor
```
