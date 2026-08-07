#!/usr/bin/env bash
# Run quality gates; on IMPLEMENTATION_FAILURE / DEPENDENCY_OR_ENV, invoke Cursor Fixer up to 3 times.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
MAX_FIX="${MAX_IMPLEMENTATION_FIX:-3}"
NOTES="${1:-.automation/runtime/test_notes.txt}"
mkdir -p .automation/runtime

attempt=0
while true; do
  attempt=$((attempt + 1))
  set +e
  bash .automation/scripts/quality_gates.sh 2>&1 | tee "$NOTES"
  status=${PIPESTATUS[0]}
  set -e
  if [[ "$status" -eq 0 ]]; then
    echo "quality_gates_ok attempt=$attempt" >> .automation/runtime/impl_fix_log.txt
    exit 0
  fi
  echo "quality_gates_failed attempt=$attempt exit=$status" | tee -a .automation/runtime/impl_fix_log.txt
  cp "$NOTES" ".automation/runtime/test_notes_fail_${attempt}.txt" || true
  if [[ "$attempt" -ge "$MAX_FIX" ]]; then
    echo "Max implementation fix attempts ($MAX_FIX) exhausted" >&2
    exit "$status"
  fi
  # Deterministic env recovery first
  bash .automation/scripts/bootstrap_test_env.sh || true
  if [[ ! -d vendor ]]; then
    composer install --no-interaction --prefer-dist || true
  fi
  # Cursor Fixer for implementation failures
  if [[ -z "${CURSOR_API_KEY:-}" ]]; then
    echo "CURSOR_API_KEY missing; cannot auto-fix implementation failure" >&2
    exit "$status"
  fi
  python - <<'PY'
import json
from pathlib import Path
fixer = Path('.automation/prompts/fixer.md').read_text()
task = Path('.automation/runtime/task.json').read_text() if Path('.automation/runtime/task.json').is_file() else '{}'
notes = Path('.automation/runtime/test_notes.txt').read_text() if Path('.automation/runtime/test_notes.txt').is_file() else ''
Path('.automation/runtime/impl_fixer_prompt.txt').write_text(f"""{fixer}

## Architect task
```json
{task}
```

## Failing quality gates / test output
```
{notes[-12000:]}
```

Fix the implementation so PHPUnit/Pint/quality gates pass.
Do NOT git push/PR. Do NOT touch secrets/.env contents beyond bootstrap.
Do NOT modify `.github/` or `.automation/` unless the Architect task explicitly requires it.
""")
PY
  if command -v agent >/dev/null 2>&1; then AGENT_BIN=agent
  elif command -v cursor-agent >/dev/null 2>&1; then AGENT_BIN=cursor-agent
  else
    echo "Cursor agent binary missing" >&2
    exit "$status"
  fi
  MODEL_ARGS=()
  if [[ -n "${CURSOR_AGENT_MODEL:-}" ]]; then MODEL_ARGS=(--model "$CURSOR_AGENT_MODEL"); fi
  "$AGENT_BIN" -p --force --output-format text "${MODEL_ARGS[@]}" "$(cat .automation/runtime/impl_fixer_prompt.txt)" || true
  if git status --porcelain | grep -q .; then
    git add -A
    git reset -q .automation/runtime 2>/dev/null || true
    git commit -m "fix: address automated quality gate failures" || true
  fi
done
