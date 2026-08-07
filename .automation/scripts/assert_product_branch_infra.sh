#!/usr/bin/env bash
# Fail if product branch three-dot diff vs origin/main touches infra paths.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

TASK_FILE="${1:-.automation/runtime/task.json}"

git fetch origin main

python - "$TASK_FILE" <<'PY'
import json
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, ".automation")
from common import assert_product_branch_diff_safe

task_file = Path(sys.argv[1])
task = json.loads(task_file.read_text(encoding="utf-8")) if task_file.is_file() else {}

out = subprocess.check_output(
    ["git", "diff", "--name-only", "origin/main...HEAD"],
    text=True,
)
paths = [line.strip() for line in out.splitlines() if line.strip()]
errors = assert_product_branch_diff_safe(paths, task=task)
if errors:
    print("Product branch infra gate failed:", file=sys.stderr)
    for path in paths:
        print(f"  {path}", file=sys.stderr)
    raise SystemExit("; ".join(errors))
print(f"product_branch_infra_gate: ok ({len(paths)} files vs origin/main)")
PY
