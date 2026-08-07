#!/usr/bin/env bash
# Shared quality gates for DOP automation (no secret echoing).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

python -m unittest discover -s .automation/tests -v

if [[ -f composer.json ]]; then
  composer validate --no-check-publish
  if [[ ! -d vendor ]]; then
    composer install --no-interaction --prefer-dist
  fi
  php artisan test
  if [[ -x vendor/bin/pint ]]; then
    vendor/bin/pint --test
  fi
fi

python - <<'PY'
import subprocess
import sys
from pathlib import Path
sys.path.insert(0, str(Path('.automation').resolve()))
from common import find_secret_like_paths
out = subprocess.check_output(['git', 'status', '--porcelain'], text=True)
paths = []
for line in out.splitlines():
    if not line.strip():
        continue
    path = line[3:].strip()
    if ' -> ' in path:
        path = path.split(' -> ', 1)[1]
    paths.append(path)
hits = find_secret_like_paths(paths)
if hits:
    raise SystemExit('Secret-like paths detected: ' + ', '.join(hits))
print('Quality gates passed')
PY
