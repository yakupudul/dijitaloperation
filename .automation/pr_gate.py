"""DOP Autopilot v2 PR gate helpers (deterministic; no task planning/recovery)."""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

from common import (
    AUTOMATION_PR_MARKER,
    has_automation_pr_marker,
    parse_json_object,
    validate_architect_task,
)

# Active Autopilot v2 must never emit these event types.
FORBIDDEN_DISPATCH_EVENT_TYPES = frozenset({"dop-next-task", "dop-recover-task"})

# Substrings that must not appear in the active PR gate workflow.
FORBIDDEN_ORCHESTRATION_MARKERS = (
    "dop-next-task",
    "dop-recover-task",
    "repository_dispatch",
    "Run Architect",
    "recover_supervisor",
    "quality_gates_with_fix",
    "architect.py",
)


def extract_task_json_from_pr_body(body: str) -> dict[str, Any] | None:
    """Parse Architect/task JSON embedded in a DOP automation PR body."""
    if not body:
        return None
    for match in re.finditer(r"```json\s*(\{.*?\})\s*```", body, flags=re.DOTALL | re.IGNORECASE):
        raw = match.group(1)
        if '"task_id"' not in raw:
            continue
        try:
            data = parse_json_object(raw)
        except (ValueError, json.JSONDecodeError):
            continue
        if isinstance(data, dict) and data.get("task_id"):
            if "status" not in data:
                data = {**data, "status": "TASK_READY"}
            return data
    # Markdown fallback: build a minimal TASK_READY object from fields.
    task_id_m = re.search(r"\*\*task_id:\*\*\s*`([^`]+)`", body, flags=re.IGNORECASE)
    if not task_id_m:
        return None
    title_m = re.search(r"\*\*title:\*\*\s*(.+)", body, flags=re.IGNORECASE)
    branch_m = re.search(r"\*\*branch:\*\*\s*`([^`]+)`", body, flags=re.IGNORECASE)
    specs = re.findall(r"`(docs/product/[^`]+\.md)`", body)
    return {
        "status": "TASK_READY",
        "task_id": task_id_m.group(1).strip(),
        "title": (title_m.group(1).strip() if title_m else task_id_m.group(1).strip()),
        "branch_name": branch_m.group(1).strip() if branch_m else "dop/unknown",
        "objective": "See PR body",
        "instructions": "See PR body",
        "acceptance_criteria": ["See PR body acceptance criteria"],
        "files_or_areas": [],
        "must_not_do": [],
        "tests_required": [],
        "product_spec_paths": specs,
        "reason": "Parsed from DOP automation PR body",
    }


def is_automation_product_pr(body: str | None) -> bool:
    return has_automation_pr_marker(body)


def reviewer_verdict_blocks_merge(verdict: str) -> bool:
    return str(verdict or "").strip().upper() != "APPROVED"


def format_fix_required_ci_failure(review: dict[str, Any]) -> str:
    """Human-readable FIX_REQUIRED output for Cursor Automations / failed checks."""
    lines = [
        "DOP PR Gate: Reviewer FIX_REQUIRED",
        "",
        f"summary: {review.get('summary') or ''}",
        f"model: {review.get('model') or ''}",
        f"reviewed_head_sha: {review.get('reviewed_head_sha') or ''}",
        "",
        "blocking_issues:",
    ]
    issues = review.get("issues") or review.get("blocking_issues") or []
    if not issues:
        lines.append("- (no structured issues listed; see reviewer summary)")
    else:
        for issue in issues:
            if isinstance(issue, dict):
                lines.append(
                    "- [{sev}] {file}: {problem} → {fix}".format(
                        sev=issue.get("severity", "?"),
                        file=issue.get("file", ""),
                        problem=issue.get("problem", ""),
                        fix=issue.get("required_fix", ""),
                    )
                )
            else:
                lines.append(f"- {issue}")
    lines.append("")
    lines.append("Cursor Automation should read this failed check, fix the PR, and push.")
    return "\n".join(lines) + "\n"


def pr_gate_may_merge(
    *,
    tests_passed: bool,
    pint_passed: bool,
    secrets_passed: bool,
    infra_passed: bool,
    reviewer_verdict: str,
    review_evidence: dict[str, Any] | None,
    current_head_sha: str,
    task: dict[str, Any],
    pr_body: str,
    branch_name: str,
    mergeable: bool,
    repo_root: Path | None = None,
) -> list[str]:
    """Return merge-blocking errors. Empty list => squash merge allowed."""
    from common import final_merge_gate_errors

    if not tests_passed:
        return ["PHPUnit / quality gates failed"]
    if not pint_passed:
        return ["Pint failed"]
    if not secrets_passed:
        return ["secret/security gates failed"]
    if not infra_passed:
        return ["product branch infra path protection failed"]
    if reviewer_verdict_blocks_merge(reviewer_verdict):
        return [f"reviewer verdict is not APPROVED ({reviewer_verdict!r})"]

    return final_merge_gate_errors(
        branch_name=branch_name,
        pr_body=pr_body,
        task=task,
        secret_paths=[],
        suspicious_diff=False,
        tests_passed=True,
        mergeable=mergeable,
        repo_root=repo_root,
        review_evidence=review_evidence,
        current_head_sha=current_head_sha,
        require_review_approval=True,
    )


def active_workflow_forbids_legacy_dispatch(workflow_text: str) -> list[str]:
    """Validate an active PR-gate workflow does not reintroduce legacy orchestration."""
    errors: list[str] = []
    if re.search(r"(?m)^\s*repository_dispatch\s*:", workflow_text):
        errors.append("repository_dispatch trigger must not be active")
    if re.search(r"event_type=['\"]dop-(next|recover)-task['\"]", workflow_text):
        errors.append("legacy dop-*-task dispatch must not be present")
    # Planning / recovery orchestration must not live in the PR gate.
    for needle in (
        "Run Architect",
        "recover_supervisor",
        "quality_gates_with_fix",
        ".automation/architect.py",
        ".automation/legacy/architect.py",
    ):
        if needle in workflow_text:
            errors.append(f"forbidden orchestration marker present: {needle}")
    return errors


def legacy_stub_is_manual_only(workflow_text: str) -> bool:
    """True when legacy stub has workflow_dispatch only (no repository_dispatch trigger)."""
    if re.search(r"(?m)^\s*repository_dispatch\s*:", workflow_text):
        return False
    return bool(re.search(r"(?m)^\s*workflow_dispatch\s*:", workflow_text))


def status_only_commit_must_not_dispatch(commit_message: str, changed_files: list[str]) -> bool:
    """v2: status-only commits never create product continuation (no dispatch exists)."""
    from common import should_skip_next_task_for_merged_pr
    from project_status import is_status_only_commit_message, should_ignore_status_commit_for_product_progress

    if is_status_only_commit_message(commit_message):
        return True
    if should_ignore_status_commit_for_product_progress(commit_message, changed_files):
        return True
    return should_skip_next_task_for_merged_pr("main", commit_message, changed_files)


def write_task_file_from_pr_body(body: str, output: Path) -> dict[str, Any]:
    task = extract_task_json_from_pr_body(body)
    if not task:
        raise ValueError("Could not extract task metadata from PR body")
    errors = validate_architect_task(task, require_product_specs=False)
    # Soft: product_spec_paths may be validated later with repo_root.
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(task, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return task


__all__ = [
    "AUTOMATION_PR_MARKER",
    "FORBIDDEN_DISPATCH_EVENT_TYPES",
    "active_workflow_forbids_legacy_dispatch",
    "extract_task_json_from_pr_body",
    "format_fix_required_ci_failure",
    "is_automation_product_pr",
    "legacy_stub_is_manual_only",
    "pr_gate_may_merge",
    "reviewer_verdict_blocks_merge",
    "status_only_commit_must_not_dispatch",
    "write_task_file_from_pr_body",
]
