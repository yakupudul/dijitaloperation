#!/usr/bin/env python3
"""Generate docs/PROJECT_STATUS.md and Actions summary (deterministic, no OpenAI)."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

AUTOMATION_DIR = Path(__file__).resolve().parents[1]
ROOT = AUTOMATION_DIR.parent
if str(AUTOMATION_DIR) not in sys.path:
    sys.path.insert(0, str(AUTOMATION_DIR))

from common import recent_commit_summary  # noqa: E402
from project_status import (  # noqa: E402
    COMPLETED_AND_CONTINUING,
    HARD_BLOCKED,
    RECOVERING,
    ROADMAP_COMPLETE,
    RUNNING,
    build_snapshot,
    close_stale_prs_best_effort,
    collect_recent_and_stale_via_gh,
    commit_project_status_to_main,
    render_actions_summary,
    write_project_status_file,
)
from recovery import write_run_summary_status  # noqa: E402


def _load_json(path: Path) -> dict:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def _read(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8").strip() if path.is_file() else ""
    except OSError:
        return ""


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Update DOP PROJECT_STATUS.md")
    parser.add_argument(
        "--overall",
        default="",
        help="Override overall status: RUNNING|RECOVERING|HARD_BLOCKED|ROADMAP_COMPLETE",
    )
    parser.add_argument(
        "--run-outcome",
        default=os.environ.get("DOP_RUN_OUTCOME", COMPLETED_AND_CONTINUING),
        help="Run outcome / chain status",
    )
    parser.add_argument("--commit-main", action="store_true", help="Commit+push status file on main")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--close-stale", action="store_true", help="Best-effort close superseded PRs")
    parser.add_argument("--skip-gh", action="store_true", help="Do not call gh (tests/offline)")
    args = parser.parse_args(argv)

    runtime = AUTOMATION_DIR / "runtime"
    task = _load_json(runtime / "task.json")
    branch = _read(runtime / "branch.txt") or str(task.get("branch_name") or "")
    pr_number = _read(runtime / "pr_number.txt")
    verdict = ""
    review = _load_json(runtime / "review.json")
    if review:
        verdict = str(review.get("verdict") or "")
    evidence = _load_json(runtime / "review_evidence.json")
    if not verdict and evidence:
        verdict = str(evidence.get("verdict") or "")

    retry = _load_json(runtime / "retry_counts.json")
    recover = _load_json(runtime / "recover_payload.json")
    retry_state = ""
    if recover:
        retry_state = (
            f"recover/{recover.get('failure_class') or 'unknown'} "
            f"retries={json.dumps(recover.get('retry_counts') or retry or {})}"
        )
    elif retry:
        retry_state = f"retries={json.dumps(retry)}"

    blockers: list[dict[str, str]] = []
    if args.run_outcome == HARD_BLOCKED or args.overall == HARD_BLOCKED:
        blockers.append(
            {
                "issue_link": "see GitHub Issues with <!-- DOP_HARD_BLOCKER -->",
                "classification": "HARD_BLOCKED",
                "reason": _read(runtime / "last_failure.txt")[:500]
                or str(task.get("reason") or "hard blocker"),
            }
        )

    recent, stale, merged_ids = ([], [], set())
    if not args.skip_gh:
        recent, stale, merged_ids = collect_recent_and_stale_via_gh(ROOT)
    commits = recent_commit_summary(ROOT)

    run_id = os.environ.get("GITHUB_RUN_ID", "")
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    run_url = f"https://github.com/{repo}/actions/runs/{run_id}" if repo and run_id else ""

    # Architect ROADMAP_COMPLETE
    if str(task.get("status") or "") == "ROADMAP_COMPLETE":
        args.run_outcome = ROADMAP_COMPLETE
        args.overall = ROADMAP_COMPLETE

    snapshot = build_snapshot(
        ROOT,
        overall_status=args.overall or None,
        run_outcome=args.run_outcome,
        merged_task_ids=merged_ids,
        commit_summary=commits,
        recently_completed=recent,
        current_task=task if task.get("status") == "TASK_READY" else {},
        current_branch=branch,
        current_pr=f"#{pr_number}" if pr_number else "",
        reviewer_verdict=verdict,
        retry_recovery_state=retry_state or args.run_outcome,
        automation_run_id=run_id,
        automation_run_url=run_url,
        blockers=blockers,
        stale_prs=stale,
        repo=repo,
    )

    path = write_project_status_file(ROOT, snapshot)
    print(f"Wrote {path}", flush=True)
    print(f"overall={snapshot.overall_status} completed={snapshot.completed_count()}/23", flush=True)

    summary = render_actions_summary(snapshot)
    gh_summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if gh_summary:
        with open(gh_summary, "a", encoding="utf-8") as fh:
            fh.write(summary)
            fh.write("\n")
    write_run_summary_status(
        runtime / "chain_status.txt",
        snapshot.run_outcome
        if snapshot.run_outcome in {COMPLETED_AND_CONTINUING, RECOVERING, HARD_BLOCKED, ROADMAP_COMPLETE}
        else COMPLETED_AND_CONTINUING,
        f"overall={snapshot.overall_status} stages={snapshot.completed_count()}/23",
    )
    (runtime / "project_status_summary.md").write_text(summary, encoding="utf-8")

    if args.close_stale and stale and not args.skip_gh:
        closed = close_stale_prs_best_effort(ROOT, stale)
        if closed:
            print("Closed stale PRs:", ", ".join(closed), flush=True)

    if args.commit_main:
        markdown = path.read_text(encoding="utf-8")
        ok = commit_project_status_to_main(ROOT, content=markdown, dry_run=args.dry_run)
        print("status_commit=", ok, flush=True)
        # CRITICAL: never dispatch dop-next-task from this script.

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
