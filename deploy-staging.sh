#!/usr/bin/env bash
# Repeatable staging deploy entrypoint. Delegates to deploy/staging/deploy.sh.
# Safe to run from the application root on the staging host.
# Does not overwrite .env, does not regenerate APP_KEY, never migrate:fresh.
# Does not install/remove TLS certificates or rewrite Nginx. Canonical URL lives in .env.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

if [[ ! -x "$ROOT/deploy/staging/deploy.sh" && ! -f "$ROOT/deploy/staging/deploy.sh" ]]; then
  echo "deploy-staging.sh: missing deploy/staging/deploy.sh" >&2
  exit 1
fi

if [[ "${MOXDOP_STAGING_PULL:-0}" == "1" ]]; then
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "deploy-staging.sh: git pull (MOXDOP_STAGING_PULL=1)"
    git pull --ff-only
  else
    echo "deploy-staging.sh: not a git work tree — skip pull" >&2
  fi
fi

exec bash "$ROOT/deploy/staging/deploy.sh"
