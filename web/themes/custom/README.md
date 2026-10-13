# Reusable theme pair (Drupal 11)

- `project_base` – standalone base theme generated from core Starterkit (`base theme: false`). Holds generic markup/components; change rarely.
- `project_child` – child theme (`base theme: project_base`). All project-specific CSS, JS, Twig overrides and preprocess go here.

## Use in a new project

1. Copy both folders to `web/themes/custom/`.
2. Rename (folders, `*.info.yml`, `*.libraries.yml`, `*.theme`, function/library prefixes, `base theme:` in the child): replace `project_base` / `project_child` / `Project Base` / `Project Child`.
3. Enable and set default:
   `drush theme:install my_base my_child && drush config:set system.theme default my_child -y && drush cr`

## Regenerating the base from a newer core Starterkit

The core script expects `vendor/` next to `web/`; with the recommended layout run (from project root):

```bash
ln -s ../vendor web/vendor
php web/core/scripts/dr generate-theme my_base --path=themes/custom
rm web/vendor
```
