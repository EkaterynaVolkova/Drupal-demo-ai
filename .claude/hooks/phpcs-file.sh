#!/usr/bin/env bash
# PostToolUse hook: run Drupal coding standards on the PHP file Claude just edited.
# Exit 2 returns phpcs output to Claude so it fixes the file; skips if phpcs is not installed.
file=$(jq -r '.tool_input.file_path // ""')
case "$file" in
  *.php|*.module|*.theme|*.inc|*.install) ;;
  *) exit 0 ;;
esac
phpcs="${CLAUDE_PROJECT_DIR:-.}/vendor/bin/phpcs"
[ -x "$phpcs" ] || exit 0
out=$("$phpcs" --standard=Drupal,DrupalPractice "$file" 2>&1) || { echo "$out" >&2; exit 2; }
exit 0
