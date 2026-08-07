#!/usr/bin/env python3
"""Classify Autopilot failure and optionally dispatch dop-recover-task."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

AUTOMATION_DIR = Path(__file__).resolve().parents[2]  # .automation/
LEGACY_DIR = Path(__file__).resolve().parents[1]  # .automation/legacy/
ROOT = AUTOMATION_DIR.parent
if str(AUTOMATION_DIR) not in sys.path:
    sys.path.insert(0, str(AUTOMATION_DIR))
if str(LEGACY_DIR) not in sys.path:
    sys.path.insert(0, str(LEGACY_DIR))

from recovery import (  # noqa: E402
    HARD_BLOCKED,
    HARD_BLOCKER,
    RECOVER_EVENT_TYPE,
    RECOVERING,
    SECURITY_BLOCKER,
    build_recovery_payload,
    can_retry,
    classify_failure,
    format_run_summary_markdown,
    increment_retry_count,
    normalize_retry_counts,
    parse_recovery_client_payload,
    should_ignore_stale_recovery,
    would_create_infinite_recovery_loop,
    write_run_summary_status,
)


def _read_text(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8") if path.is_file() else ""
    except OSError:
        return ""


def _load_task() -> dict:
    path = AUTOMATION_DIR / "runtime" / "task.json"
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def _load_retry_counts() -> dict:
    path = AUTOMATION_DIR / "runtime" / "retry_counts.json"
    if path.is_file():
        try:
            return normalize_retry_counts(json.loads(path.read_text(encoding="utf-8")))
        except (OSError, json.JSONDecodeError):
            pass
    # Also accept recover payload snapshot
    payload_path = AUTOMATION_DIR / "runtime" / "recover_payload.json"
    if payload_path.is_file():
        try:
            raw = json.loads(payload_path.read_text(encoding="utf-8"))
            return normalize_retry_counts(parse_recovery_client_payload(raw).get("retry_counts"))
        except (OSError, json.JSONDecodeError):
            pass
    return normalize_retry_counts({})


def _gh_dispatch(repo: str, payload: dict) -> None:
    # GitHub client_payload values must be string/number/boolean — stringify nested JSON.
    client = {
        "original_task_id": str(payload.get("original_task_id", "")),
        "original_branch": str(payload.get("original_branch", "")),
        "failure_class": str(payload.get("failure_class", "")),
        "retry_counts": json.dumps(payload.get("retry_counts") or {}),
        "failed_run_id": str(payload.get("failed_run_id", "")),
        "stage": str(payload.get("stage", "")),
        "pr_number": str(payload.get("pr_number", "")),
        "error_excerpt": str(payload.get("error_excerpt", ""))[:1000],
    }
    body = {"event_type": RECOVER_EVENT_TYPE, "client_payload": client}
    subprocess.run(
        ["gh", "api", f"repos/{repo}/dispatches", "--method", "POST", "--input", "-"],
        input=json.dumps(body),
        text=True,
        check=True,
        cwd=ROOT,
    )


def _create_hard_blocker_issue(*, reason: str, task: dict, failure_class: str) -> None:
    from common import format_hard_blocker_issue_body

    body = format_hard_blocker_issue_body(
        reason=f"[{failure_class}] {reason}",
        task=task,
    )
    blocker = AUTOMATION_DIR / "runtime" / "blocker.md"
    blocker.write_text(body, encoding="utf-8")
    title = f"DOP hard blocker: {failure_class}"
    subprocess.run(
        ["gh", "issue", "create", "--title", title, "--body-file", str(blocker)],
        cwd=ROOT,
        check=False,
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="DOP Autopilot failure recovery supervisor")
    parser.add_argument("--step-name", default="")
    parser.add_argument("--error-file", default="")
    parser.add_argument("--error-text", default="")
    parser.add_argument("--exit-code", type=int, default=1)
    parser.add_argument("--stage", default="")
    parser.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", ""))
    parser.add_argument("--run-id", default=os.environ.get("GITHUB_RUN_ID", ""))
    parser.add_argument("--summary-file", default=str(AUTOMATION_DIR / "runtime" / "chain_status.txt"))
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)

    error_text = args.error_text or ""
    if args.error_file:
        error_text = error_text or _read_text(Path(args.error_file))
    # Prefer last failure log if present
    fail_log = AUTOMATION_DIR / "runtime" / "last_failure.txt"
    if not error_text and fail_log.is_file():
        error_text = _read_text(fail_log)

    task = _load_task()
    branch = ""
    branch_file = AUTOMATION_DIR / "runtime" / "branch.txt"
    if branch_file.is_file():
        branch = branch_file.read_text(encoding="utf-8").strip()
    branch = branch or str(task.get("branch_name") or "")
    task_id = str(task.get("task_id") or "")
    pr_number = _read_text(AUTOMATION_DIR / "runtime" / "pr_number.txt").strip()

    failure_class = classify_failure(
        step_name=args.step_name,
        error_text=error_text,
        exit_code=args.exit_code,
    )
    retry_counts = _load_retry_counts()

    summary_path = Path(args.summary_file)
    github_summary = os.environ.get("GITHUB_STEP_SUMMARY")

    # Stale: if task already merged, do nothing.
    merged_ids: set[str] = set()
    merged_branches: set[str] = set()
    try:
        out = subprocess.check_output(
            [
                "gh",
                "pr",
                "list",
                "--state",
                "merged",
                "--base",
                "main",
                "--limit",
                "50",
                "--json",
                "body,headRefName",
            ],
            cwd=ROOT,
            text=True,
        )
        rows = json.loads(out)
        from common import extract_task_ids_from_pr_bodies

        merged_ids = extract_task_ids_from_pr_bodies([str(r.get("body") or "") for r in rows])
        merged_branches = {str(r.get("headRefName") or "") for r in rows}
    except (OSError, subprocess.CalledProcessError, json.JSONDecodeError):
        pass

    if should_ignore_stale_recovery(
        task_id=task_id,
        branch=branch,
        merged_task_ids=merged_ids,
        merged_branches=merged_branches,
    ):
        detail = f"stale recovery ignored for task_id={task_id} branch={branch}"
        write_run_summary_status(summary_path, HARD_BLOCKED, detail)
        if github_summary:
            Path(github_summary).open("a", encoding="utf-8").write(
                format_run_summary_markdown(HARD_BLOCKED, detail=detail)
            )
        print(detail)
        return 0

    if failure_class in {SECURITY_BLOCKER, HARD_BLOCKER} or not can_retry(failure_class, retry_counts):
        detail = f"{failure_class}; retries exhausted or non-recoverable"
        write_run_summary_status(summary_path, HARD_BLOCKED, detail)
        if github_summary:
            Path(github_summary).open("a", encoding="utf-8").write(
                format_run_summary_markdown(HARD_BLOCKED, detail=detail)
            )
        if not args.dry_run:
            _create_hard_blocker_issue(
                reason=detail + "\n\n" + (error_text[:1500] or args.step_name),
                task=task,
                failure_class=failure_class,
            )
        print(detail)
        return 0

    if would_create_infinite_recovery_loop(
        failure_class=failure_class,
        retry_counts=retry_counts,
        failed_run_id=args.run_id,
    ):
        detail = "refusing recover dispatch to avoid infinite loop"
        write_run_summary_status(summary_path, HARD_BLOCKED, detail)
        if github_summary:
            Path(github_summary).open("a", encoding="utf-8").write(
                format_run_summary_markdown(HARD_BLOCKED, detail=detail)
            )
        if not args.dry_run:
            _create_hard_blocker_issue(
                reason=detail,
                task=task,
                failure_class=HARD_BLOCKER,
            )
        print(detail)
        return 0

    next_counts = increment_retry_count(failure_class, retry_counts)
    payload = build_recovery_payload(
        task_id=task_id,
        branch=branch,
        failure_class=failure_class,
        retry_counts=next_counts,
        failed_run_id=args.run_id,
        stage=args.stage or args.step_name,
        pr_number=pr_number,
        error_excerpt=error_text,
    )
    (AUTOMATION_DIR / "runtime").mkdir(parents=True, exist_ok=True)
    (AUTOMATION_DIR / "runtime" / "recover_dispatch.json").write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    detail = (
        f"dispatching {RECOVER_EVENT_TYPE} class={failure_class} "
        f"task={task_id or '-'} branch={branch or '-'} retries={next_counts}"
    )
    write_run_summary_status(summary_path, RECOVERING, detail)
    if github_summary:
        Path(github_summary).open("a", encoding="utf-8").write(
            format_run_summary_markdown(RECOVERING, detail=detail)
        )

    if args.dry_run:
        print(json.dumps(payload, indent=2))
        return 0

    repo = args.repo or os.environ.get("GITHUB_REPOSITORY", "")
    if not repo:
        print("GITHUB_REPOSITORY missing; cannot dispatch", file=sys.stderr)
        return 1
    _gh_dispatch(repo, payload)
    print(detail)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
