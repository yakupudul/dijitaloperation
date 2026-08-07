#!/usr/bin/env bash
# Create a product Autopilot branch from a fresh origin/main tip, then apply
# the working-tree product changes. Rejects .github/ / .automation/ leaks.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

BRANCH="${1:?branch name required}"
TASK_FILE="${2:-.automation/runtime/task.json}"
RUNTIME_DIR=".automation/runtime"
PATCH_FILE="$RUNTIME_DIR/product.patch"
BASE_SHA_FILE="$RUNTIME_DIR/base_sha.txt"

mkdir -p "$RUNTIME_DIR"

git fetch origin main
ORIGIN_MAIN="$(git rev-parse origin/main)"
echo "$ORIGIN_MAIN" > "$BASE_SHA_FILE"

# Capture current product work (tracked + untracked), excluding runtime noise.
git add -A
git reset -q "$RUNTIME_DIR" 2>/dev/null || true
git reset -q .env 2>/dev/null || true
git reset -q .phpunit.cache 2>/dev/null || true
git diff --cached --binary > "$PATCH_FILE"
git reset -q

if [[ ! -s "$PATCH_FILE" ]]; then
  echo "prepare_product_branch: empty product patch" >&2
  exit 1
fi

# Detach from any stale local main; branch exclusively from origin/main.
git checkout --detach "$ORIGIN_MAIN"
git checkout -B "$BRANCH"
git reset --hard "$ORIGIN_MAIN"
git clean -fd -e .automation/runtime -e .env

if ! git apply --index --whitespace=nowarn "$PATCH_FILE"; then
  echo "prepare_product_branch: failed to apply product patch onto origin/main" >&2
  exit 1
fi

python - "$TASK_FILE" <<'PY'
import json
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, ".automation")
from common import (
    assert_product_branch_diff_safe,
    find_secret_like_paths,
    scan_diff_for_credential_leaks,
)

task_file = Path(sys.argv[1])
task = json.loads(task_file.read_text(encoding="utf-8")) if task_file.is_file() else {}

out = subprocess.check_output(["git", "diff", "--cached", "--name-only"], text=True)
paths = [line.strip() for line in out.splitlines() if line.strip()]
if not paths:
    raise SystemExit("prepare_product_branch: no staged product files after apply")

secrets = find_secret_like_paths(paths)
if secrets:
    raise SystemExit("Secret-like paths: " + ", ".join(secrets))

errors = assert_product_branch_diff_safe(paths, task=task)
if errors:
    raise SystemExit("; ".join(errors))

diff = subprocess.check_output(["git", "diff", "--cached"], text=True)
leaks = scan_diff_for_credential_leaks(diff)
if leaks:
    raise SystemExit("Credential-like patterns in product patch")

base = subprocess.check_output(["git", "rev-parse", "origin/main"], text=True).strip()
print(f"prepare_product_branch: staged {len(paths)} files on {base}")
for path in paths:
    print(f"  {path}")
PY

echo "prepare_product_branch: ready on $BRANCH (base $ORIGIN_MAIN)"
