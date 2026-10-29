---
name: commit
description: Commit the current changes as atomic Conventional Commits following the project rules
disable-model-invocation: true
allowed-tools: Bash(git status *) Bash(git diff *) Bash(git add *) Bash(git commit *) Bash(git log *) Bash(vendor/bin/phpcs *)
---
Current state:
!`git status --short`
!`git diff --stat`

Make the commits:
1. Run vendor/bin/phpcs --standard=Drupal,DrupalPractice on changed PHP files; if there are errors, stop and report them
2. Split the changes into logical groups (theme, config, module code, docs). One group, one commit.
2. Stage files by name or directory, never `git add -A`. Never stage settings*.php, files/, DB dumps, screenshots or secrets. If unsure about a file, leave it unstaged and tell me.
3. Message format: `<type>(<scope>): <description>`, imperative mood, types feat|fix|refactor|docs|style|test|chore. The body explains why and what.
4. No Co-authored-by trailers and no AI attribution lines.
5. Do not push until I ask. At the end show `git log --oneline` for the new commits.
