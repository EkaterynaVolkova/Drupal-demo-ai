---
name: check
description: Run read-only quality checks on the changed files (phpcs, php -l, drush cr, bootstrap and config status) and report problems. Use before committing, before a PR, or before saying the work is done.
allowed-tools: Bash(git status *) Bash(git diff *) Bash(vendor/bin/phpcs *) Bash(php -l *) Bash(vendor/bin/drush cr) Bash(vendor/bin/drush status *) Bash(vendor/bin/drush config:status *)
---
Current state:
!`git status --short`

Run the checks and only report, do not fix anything:
1. List the changed files: uncommitted ones from the state above plus `git diff --name-only origin/main...HEAD`.
2. For changed PHP files (.php, .module, .theme, .inc, .install): run `php -l <file>` and `vendor/bin/phpcs --standard=Drupal,DrupalPractice <files>`.
3. Run `vendor/bin/drush cr`, then `vendor/bin/drush status` and confirm the bootstrap is successful.
4. Run `vendor/bin/drush config:status` and list any config differences.
5. Finish with a short table: check, result (pass or fail), details. If everything passes, say so in one line.
