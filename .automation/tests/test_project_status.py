"""Tests for DOP project status observability."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
import sys

AUTOMATION = Path(__file__).resolve().parents[1]
ROOT = AUTOMATION.parent
sys.path.insert(0, str(AUTOMATION))

from project_status import (  # noqa: E402
    HARD_BLOCKED,
    RECOVERING,
    ROADMAP_COMPLETE,
    RUNNING,
    TOTAL_STAGES,
    build_snapshot,
    is_status_only_commit_message,
    overall_status_from_run_outcome,
    parse_roadmap_stages,
    render_actions_summary,
    render_project_status_markdown,
    should_ignore_status_commit_for_product_progress,
    stage_completion_evidence,
    extract_recent_automation_tasks_from_gh_json,
    find_stale_automation_prs,
    COMPLETED_AND_CONTINUING,
)
from common import should_skip_next_task_for_merged_pr, AUTOMATION_PR_MARKER  # noqa: E402


class RoadmapParserTests(unittest.TestCase):
    def test_parse_roadmap_from_repo(self) -> None:
        text = (ROOT / "docs/IMPLEMENTATION_ROADMAP.md").read_text(encoding="utf-8")
        stages = parse_roadmap_stages(text)
        self.assertEqual(len(stages), TOTAL_STAGES)
        self.assertEqual(stages[0][0], 1)
        self.assertIn("Customer", stages[2][1])
        self.assertEqual(stages[-1][0], 23)

    def test_parse_fallback_on_empty(self) -> None:
        stages = parse_roadmap_stages("")
        self.assertEqual(len(stages), 23)


class StageEvidenceTests(unittest.TestCase):
    def test_workflow_run_number_not_used(self) -> None:
        # Evidence API has no run_number parameter — progress is file/merge based.
        import inspect

        sig = inspect.signature(stage_completion_evidence)
        self.assertNotIn("run", "".join(sig.parameters.keys()).lower())
        self.assertNotIn("workflow", "".join(sig.parameters.keys()).lower())

    def test_zero_of_twenty_three_empty_tree(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "docs").mkdir()
            evidence = stage_completion_evidence(root, merged_task_ids=set(), commit_summary="")
            self.assertTrue(all(v == "remaining" for v in evidence.values()))
            snap = build_snapshot(root, merged_task_ids=set(), commit_summary="")
            self.assertEqual(snap.completed_count(), 0)
            self.assertEqual(snap.overall_status, RUNNING)

    def test_partial_and_completed_on_real_repo(self) -> None:
        evidence = stage_completion_evidence(ROOT, merged_task_ids=set(), commit_summary="")
        # Bootstrap + auth should be complete on this repo
        self.assertEqual(evidence[1], "completed")
        self.assertEqual(evidence[2], "completed")
        # Something not yet done (connectors / future)
        self.assertIn(evidence[12], {"remaining", "in_progress"})
        completed = [n for n, s in evidence.items() if s == "completed"]
        self.assertGreaterEqual(len(completed), 3)
        self.assertLess(len(completed), 23)

    def test_roadmap_complete_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            # Force all completed via monkeypatch by building snapshot with fake stages
            # Use real builder but override after: call build then mutate
            snap = build_snapshot(root, run_outcome=ROADMAP_COMPLETE)
            # empty tree won't be ROADMAP_COMPLETE from evidence; pass overall override
            snap = build_snapshot(root, overall_status=ROADMAP_COMPLETE, run_outcome=ROADMAP_COMPLETE)
            self.assertEqual(snap.overall_status, ROADMAP_COMPLETE)
            md = render_project_status_markdown(snap)
            self.assertIn("ROADMAP_COMPLETE", md)
            self.assertIn("Current task: None", md)
            summary = render_actions_summary(snap)
            self.assertIn("🎉 DOP canonical roadmap complete", summary)

    def test_hard_blocked_and_recovering(self) -> None:
        snap = build_snapshot(ROOT, overall_status=HARD_BLOCKED, run_outcome=HARD_BLOCKED)
        self.assertEqual(snap.overall_status, HARD_BLOCKED)
        self.assertIn("HARD_BLOCKED", render_actions_summary(snap))
        snap2 = build_snapshot(ROOT, overall_status=RECOVERING, run_outcome=RECOVERING)
        self.assertEqual(snap2.overall_status, RECOVERING)
        self.assertIn("RECOVERING", render_actions_summary(snap2))


class RecentAndStaleTests(unittest.TestCase):
    def test_recently_completed_extraction(self) -> None:
        rows = [
            {
                "number": 10,
                "title": "Customer",
                "body": f"{AUTOMATION_PR_MARKER}\n- **task_id:** `customer-1`\n",
                "mergedAt": "2026-08-07T01:00:00Z",
                "mergeCommit": {"oid": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},
                "headRefName": "feat/customer",
            },
            {
                "number": 9,
                "title": "chore",
                "body": "no automation",
                "mergedAt": "2026-08-07T00:00:00Z",
                "mergeCommit": {"oid": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"},
                "headRefName": "chore/x",
            },
        ]
        recent = extract_recent_automation_tasks_from_gh_json(rows, limit=10)
        self.assertEqual(len(recent), 1)
        self.assertEqual(recent[0].task_id, "customer-1")
        self.assertEqual(recent[0].pr_number, "10")

    def test_stale_pr_detection(self) -> None:
        open_prs = [
            {
                "number": 28,
                "title": "stale",
                "body": f"{AUTOMATION_PR_MARKER}\n- **task_id:** `run-foundation`\n",
                "headRefName": "feature/pipeline-run-foundation",
            }
        ]
        stale = find_stale_automation_prs(open_prs, merged_task_ids={"run-foundation"})
        self.assertEqual(len(stale), 1)
        self.assertEqual(stale[0].reason, "Superseded / stale")


class StatusCommitIgnoreTests(unittest.TestCase):
    def test_status_only_commits_ignored(self) -> None:
        self.assertTrue(is_status_only_commit_message("chore(status): update DOP project status"))
        self.assertTrue(
            should_ignore_status_commit_for_product_progress(
                commit_message="chore(status): update DOP project status",
                changed_files=["docs/PROJECT_STATUS.md"],
            )
        )
        self.assertTrue(
            should_skip_next_task_for_merged_pr(
                "main",
                pr_title="chore(status): update DOP project status",
                changed_files=["docs/PROJECT_STATUS.md"],
            )
        )

    def test_status_update_does_not_create_duplicate_dispatch(self) -> None:
        # Status commits are skip-next-task; dispatch_eligible still requires merged product approval.
        self.assertTrue(
            should_skip_next_task_for_merged_pr(
                "chore/status-update",
                "chore(status): update DOP project status",
                ["docs/PROJECT_STATUS.md"],
            )
        )


class OverallMappingTests(unittest.TestCase):
    def test_completed_and_continuing_maps_to_running(self) -> None:
        self.assertEqual(
            overall_status_from_run_outcome(COMPLETED_AND_CONTINUING),
            RUNNING,
        )


if __name__ == "__main__":
    unittest.main()
