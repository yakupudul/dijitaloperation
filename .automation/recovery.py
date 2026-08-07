"""DOP Autopilot self-healing: classify failures, retries, recover payloads."""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

# Failure classes (canonical).
TRANSIENT_INFRA = "TRANSIENT_INFRA"
DEPENDENCY_OR_ENV = "DEPENDENCY_OR_ENV"
IMPLEMENTATION_FAILURE = "IMPLEMENTATION_FAILURE"
REVIEW_FAILURE = "REVIEW_FAILURE"
SECURITY_BLOCKER = "SECURITY_BLOCKER"
HARD_BLOCKER = "HARD_BLOCKER"

FAILURE_CLASSES = (
    TRANSIENT_INFRA,
    DEPENDENCY_OR_ENV,
    IMPLEMENTATION_FAILURE,
    REVIEW_FAILURE,
    SECURITY_BLOCKER,
    HARD_BLOCKER,
)

# Run summary outcomes shown in Actions.
COMPLETED_AND_CONTINUING = "COMPLETED_AND_CONTINUING"
RECOVERING = "RECOVERING"
HARD_BLOCKED = "HARD_BLOCKED"
ROADMAP_COMPLETE = "ROADMAP_COMPLETE"

RUN_SUMMARY_STATUSES = (
    COMPLETED_AND_CONTINUING,
    RECOVERING,
    HARD_BLOCKED,
    ROADMAP_COMPLETE,
)

MAX_INFRA_RETRY = 3
MAX_IMPLEMENTATION_FIX = 3
MAX_REVIEWER_RETRY = 3
MAX_ARCHITECT_RETRY = 3

RECOVER_EVENT_TYPE = "dop-recover-task"
NEXT_EVENT_TYPE = "dop-next-task"

_TRANSIENT_PATTERNS = (
    re.compile(r"timed?\s*out", re.I),
    re.compile(r"timeout", re.I),
    re.compile(r"temporar(?:y|ily)", re.I),
    re.compile(r"connection reset", re.I),
    re.compile(r"connection refused", re.I),
    re.compile(r"network (?:is )?unreachable", re.I),
    re.compile(r"TLS handshake", re.I),
    re.compile(r"ECONNRESET|ECONNREFUSED|ETIMEDOUT|ENETUNREACH", re.I),
    re.compile(r"502 Bad Gateway|503 Service|504 Gateway", re.I),
    re.compile(r"rate limit", re.I),
    re.compile(r"server error", re.I),
    re.compile(r"could not resolve host", re.I),
    re.compile(r"Failed to download|curl: \(28\)|curl: \(7\)", re.I),
    re.compile(r"packagist\.org|registry\.npmjs|pypi\.org", re.I),
)

_DEPENDENCY_PATTERNS = (
    re.compile(r"APP_KEY", re.I),
    re.compile(r"No application encryption key", re.I),
    re.compile(r"MissingAppKeyException", re.I),
    re.compile(r"\.env(?:\.example)?", re.I),
    re.compile(r"bootstrap_test_env", re.I),
    re.compile(r"composer (?:install|update) failed", re.I),
    re.compile(r"Your requirements could not be resolved", re.I),
    re.compile(r"failed to open stream: No such file or directory", re.I),
    re.compile(r"class ['\"]?Illuminate\\", re.I),
    re.compile(r"Please provide a valid cache path", re.I),
    re.compile(r"storage[/\\]framework", re.I),
    re.compile(r"vendor[/\\]autoload\.php", re.I),
)

_SECURITY_PATTERNS = (
    re.compile(r"secret-like paths", re.I),
    re.compile(r"Credential-like patterns", re.I),
    re.compile(r"SECURITY_BLOCKER", re.I),
    re.compile(r"scan_diff_for_credential", re.I),
)

_REVIEW_HARD_PATTERNS = (
    re.compile(r"invalid.?api.?key|incorrect.?api.?key", re.I),
    re.compile(r"authentication|unauthorized|401\b", re.I),
    re.compile(r"billing|insufficient.?quota|payment.?required|402\b", re.I),
    re.compile(r"OPENAI_API_KEY missing", re.I),
    re.compile(r"permission.?denied", re.I),
)

_IMPLEMENTATION_PATTERNS = (
    re.compile(r"FAILED|FAILURES!", re.I),
    re.compile(r"PHPUnit", re.I),
    re.compile(r"pint --test", re.I),
    re.compile(r"Quality gates", re.I),
    re.compile(r"Empty implementation diff", re.I),
    re.compile(r"Architect JSON failed validation", re.I),
    re.compile(r"unsafe or invalid product_spec_path", re.I),
    re.compile(r"Tests:\\|There were \d+ failures", re.I),
)


def classify_failure(
    *,
    step_name: str = "",
    error_text: str = "",
    exit_code: int | None = None,
) -> str:
    """Map a failed step + log text to a failure class (fail-closed to HARD_BLOCKER)."""
    text = f"{step_name}\n{error_text or ''}"
    step = (step_name or "").lower()

    if any(p.search(text) for p in _SECURITY_PATTERNS):
        return SECURITY_BLOCKER

    if "review" in step or "reviewer" in step:
        if any(p.search(text) for p in _REVIEW_HARD_PATTERNS):
            return HARD_BLOCKER
        if any(p.search(text) for p in _TRANSIENT_PATTERNS):
            return TRANSIENT_INFRA
        if "invalid json" in text.lower() or "json failed" in text.lower() or "verdict missing" in text.lower():
            return REVIEW_FAILURE
        if "OPENAI_API_KEY" in text or any(p.search(text) for p in _REVIEW_HARD_PATTERNS):
            return HARD_BLOCKER
        return REVIEW_FAILURE

    if any(p.search(text) for p in _REVIEW_HARD_PATTERNS) and (
        "openai" in text.lower() or "architect" in step or "review" in step
    ):
        return HARD_BLOCKER

    if any(p.search(text) for p in _TRANSIENT_PATTERNS):
        return TRANSIENT_INFRA

    if any(p.search(text) for p in _DEPENDENCY_PATTERNS) or "composer" in step or "bootstrap" in step:
        return DEPENDENCY_OR_ENV

    if any(p.search(text) for p in _IMPLEMENTATION_PATTERNS) or "quality" in step or "implement" in step:
        return IMPLEMENTATION_FAILURE

    if "architect" in step:
        return IMPLEMENTATION_FAILURE

    if exit_code not in (None, 0):
        return IMPLEMENTATION_FAILURE

    return HARD_BLOCKER


def retry_budget_for(failure_class: str) -> int:
    if failure_class == TRANSIENT_INFRA:
        return MAX_INFRA_RETRY
    if failure_class == DEPENDENCY_OR_ENV:
        return MAX_INFRA_RETRY
    if failure_class == IMPLEMENTATION_FAILURE:
        return MAX_IMPLEMENTATION_FIX
    if failure_class == REVIEW_FAILURE:
        return MAX_REVIEWER_RETRY
    return 0


def normalize_retry_counts(raw: Any) -> dict[str, int]:
    counts = {
        "infra": 0,
        "implementation": 0,
        "reviewer": 0,
        "architect": 0,
        "dependency": 0,
    }
    if not isinstance(raw, dict):
        return counts
    for key in counts:
        try:
            counts[key] = max(0, int(raw.get(key, 0)))
        except (TypeError, ValueError):
            counts[key] = 0
    return counts


def retry_counter_key(failure_class: str) -> str | None:
    return {
        TRANSIENT_INFRA: "infra",
        DEPENDENCY_OR_ENV: "dependency",
        IMPLEMENTATION_FAILURE: "implementation",
        REVIEW_FAILURE: "reviewer",
    }.get(failure_class)


def can_retry(failure_class: str, retry_counts: dict[str, int] | None = None) -> bool:
    if failure_class in {SECURITY_BLOCKER, HARD_BLOCKER}:
        return False
    counts = normalize_retry_counts(retry_counts)
    key = retry_counter_key(failure_class)
    if key is None:
        return False
    budget = retry_budget_for(failure_class)
    # dependency shares infra budget conceptually but tracks separately up to MAX_INFRA_RETRY
    if failure_class == DEPENDENCY_OR_ENV:
        return counts["dependency"] < MAX_INFRA_RETRY
    return counts[key] < budget


def increment_retry_count(
    failure_class: str, retry_counts: dict[str, int] | None = None
) -> dict[str, int]:
    counts = normalize_retry_counts(retry_counts)
    key = retry_counter_key(failure_class)
    if key:
        counts[key] = counts.get(key, 0) + 1
    return counts


def build_recovery_payload(
    *,
    task_id: str = "",
    branch: str = "",
    failure_class: str,
    retry_counts: dict[str, int] | None = None,
    failed_run_id: str = "",
    stage: str = "",
    pr_number: str = "",
    error_excerpt: str = "",
) -> dict[str, Any]:
    counts = normalize_retry_counts(retry_counts)
    return {
        "event_type": RECOVER_EVENT_TYPE,
        "original_task_id": (task_id or "").strip(),
        "original_branch": (branch or "").strip(),
        "failure_class": failure_class,
        "retry_counts": counts,
        "failed_run_id": str(failed_run_id or ""),
        "stage": (stage or "").strip(),
        "pr_number": str(pr_number or "").strip(),
        "error_excerpt": (error_excerpt or "")[:2000],
    }


def parse_recovery_client_payload(raw: Any) -> dict[str, Any]:
    """Normalize GitHub repository_dispatch client_payload for recover."""
    if isinstance(raw, str):
        try:
            raw = json.loads(raw)
        except json.JSONDecodeError:
            raw = {}
    if not isinstance(raw, dict):
        raw = {}
    failure_class = str(raw.get("failure_class") or HARD_BLOCKER)
    if failure_class not in FAILURE_CLASSES:
        failure_class = HARD_BLOCKER
    retry_raw = raw.get("retry_counts") or raw.get("retry_count")
    if isinstance(retry_raw, str):
        try:
            retry_raw = json.loads(retry_raw)
        except json.JSONDecodeError:
            retry_raw = {}
    return {
        "original_task_id": str(raw.get("original_task_id") or raw.get("task_id") or "").strip(),
        "original_branch": str(raw.get("original_branch") or raw.get("branch") or "").strip(),
        "failure_class": failure_class,
        "retry_counts": normalize_retry_counts(retry_raw),
        "failed_run_id": str(raw.get("failed_run_id") or "").strip(),
        "stage": str(raw.get("stage") or "").strip(),
        "pr_number": str(raw.get("pr_number") or "").strip(),
        "error_excerpt": str(raw.get("error_excerpt") or ""),
    }


def should_ignore_stale_recovery(
    *,
    task_id: str,
    branch: str,
    merged_task_ids: set[str] | None = None,
    merged_branches: set[str] | None = None,
    open_pr_branches: set[str] | None = None,
) -> bool:
    """True when the product task already merged — ignore stale recover events."""
    tid = (task_id or "").strip()
    br = (branch or "").strip()
    merged_task_ids = merged_task_ids or set()
    merged_branches = merged_branches or set()
    open_pr_branches = open_pr_branches or set()

    if tid and tid in merged_task_ids:
        return True
    if br and br in merged_branches:
        return True
    # No identity at all → not stale; recover may re-run Architect for early failures.
    if not tid and not br:
        return False
    # Branch still open as PR → not stale.
    if br and br in open_pr_branches:
        return False
    # Known branch with no open PR and not in merged set → treat as unknown; do not ignore
    # (may need resume). Caller can still decide.
    return False


def recovery_should_run_architect(payload: dict[str, Any]) -> bool:
    """Recover must not pick a *new* Architect task when an original task/branch exists."""
    task_id = str(payload.get("original_task_id") or "").strip()
    branch = str(payload.get("original_branch") or "").strip()
    stage = str(payload.get("stage") or "").strip().lower()
    if stage in {"architect", "architect_validation"}:
        return True
    if task_id or branch:
        return False
    return True


def would_create_infinite_recovery_loop(
    *,
    failure_class: str,
    retry_counts: dict[str, int] | None,
    previous_failed_run_ids: list[str] | None = None,
    failed_run_id: str = "",
) -> bool:
    """Guard: refuse recover dispatch when budgets exhausted or same run re-queued blindly.

    ``retry_counts`` should be the *current* counts before scheduling another recover.
    """
    if failure_class in {SECURITY_BLOCKER, HARD_BLOCKER}:
        return True
    if not can_retry(failure_class, retry_counts):
        return True
    prev = previous_failed_run_ids or []
    if failed_run_id and prev.count(failed_run_id) >= 2:
        return True
    counts = normalize_retry_counts(retry_counts)
    total = sum(counts.values())
    hard_cap = MAX_INFRA_RETRY + MAX_IMPLEMENTATION_FIX + MAX_REVIEWER_RETRY + MAX_ARCHITECT_RETRY
    return total >= hard_cap


def write_run_summary_status(path: Path, status: str, detail: str = "") -> None:
    if status not in RUN_SUMMARY_STATUSES:
        status = HARD_BLOCKED
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(status + ("\n" + detail if detail else "") + "\n", encoding="utf-8")


def format_run_summary_markdown(status: str, *, detail: str = "") -> str:
    if status not in RUN_SUMMARY_STATUSES:
        status = HARD_BLOCKED
    lines = [
        "## DOP Autopilot chain status",
        f"- **status:** `{status}`",
    ]
    if detail:
        lines.append(f"- **detail:** {detail}")
    return "\n".join(lines) + "\n"


def repair_invalid_product_spec_paths(
    paths: list[str],
    *,
    repo_root: Path,
    is_safe_path,
) -> list[str]:
    """Best-effort repair of common Architect path mistakes (prefix / catalog typos)."""
    repaired: list[str] = []
    for raw in paths:
        path = (raw or "").replace("\\", "/").strip()
        candidates = [path]
        if path.startswith("docs/website/"):
            candidates.append("docs/product/website/" + path[len("docs/website/") :])
        if path.endswith("_CATALOG.md"):
            candidates.append(path.replace("_CATALOG.md", ".md"))
            if path.startswith("docs/website/"):
                candidates.append(
                    "docs/product/website/" + path[len("docs/website/") :].replace("_CATALOG.md", ".md")
                )
        if "/DIAGNOSIS_CATALOG.md" in path:
            candidates.append("docs/product/website/DIAGNOSIS.md")

        chosen = None
        for cand in candidates:
            if not is_safe_path(cand):
                continue
            if (repo_root / cand).is_file():
                chosen = cand
                break
        repaired.append(chosen or path)
    # de-dupe preserve order
    out: list[str] = []
    seen: set[str] = set()
    for item in repaired:
        if item in seen:
            continue
        seen.add(item)
        out.append(item)
    return out
