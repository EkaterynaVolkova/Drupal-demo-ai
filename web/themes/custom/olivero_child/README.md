# Olivero Child

Generic Drupal 11 sub-theme of core's Olivero. Zero dependencies, works in any Drupal 11 project.

## Use in a new project

1. Copy this folder to `web/themes/custom/<your_theme>`.
2. Rename: folder, `olivero_child.*` files, the `olivero_child` library/function prefixes and the `name` in the `.info.yml` (search & replace `olivero_child` / `Olivero Child`).
3. `drush theme:install <your_theme> && drush config:set system.theme default <your_theme> -y && drush cr`.

## Structure

- `*.info.yml` – metadata, base theme, library extension.
- `*.libraries.yml` – global CSS/JS attached through `libraries-extend`.
- `*.theme` – preprocess hooks and theme settings form.
- `css/`, `js/`, `images/` – assets. `templates/` – Twig overrides (copy from `core/themes/olivero/templates`).

## Note

Olivero's markup and CSS may change between minor Drupal releases, so re-check overrides after core updates. If you need a fully decoupled theme, generate one with core Starterkit: `php web/core/scripts/drupal generate-theme my_theme`.
