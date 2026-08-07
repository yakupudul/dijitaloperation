"""Tests for Autopilot v2 PR gate (no planning/recovery orchestration)."""

from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path
import sys

AUTOMATION = Path(__file__).resolve().parents[1]
ROOT = AUTOMATION.parent
sys.path.insert(0, str(AUTOMATION))

from common import (  # noqa: E402
    AUTOMATION_PR_MARKER,
    build_review_evidence,
    final_merge_gate_errors,
    validate_review_evidence,
)
from pr_gate import (  # noqa: E402
    active_workflow_forbids_legacy_dispatch,
    extract_task_json_from_pr_body,
    format_fix_required_ci_failure,
    is_automation_product_pr,
    legacy_stub_is_manual_only,
    pr_gate_may_merge,
    reviewer_verdict_blocks_merge,
    status_only_commit_must_not_dispatch,
)
from project_status import (  # noqa: E402
    HARD_BLOCKED,
    RECOVERING,
    ROADMAP_COMPLETE,
    RUNNING,
    build_snapshot,
    render_actions_summary,
    render_project_status_markdown,
)


SAMPLE_BODY = f"""{AUTOMATION_PR_MARKER}

## Automated DOP Autopilot task

- **task_id:** `website-diagnosis-ssl-check`
- **title:** Add deterministic SSL certificate check
- **branch:** `dop/website-diagnosis-ssl-check`

### Product specs
- `docs/product/website/DIAGNOSIS.md`

<details><summary>Architect task JSON</summary>

```json
{{
  "status": "TASK_READY",
  "task_id": "website-diagnosis-ssl-check",
  "title": "Add deterministic SSL certificate check",
  "branch_name": "dop/website-diagnosis-ssl-check",
  "objective": "SSL check",
  "instructions": "Implement SSL check",
  "acceptance_criteria": ["Evidence created"],
  "files_or_areas": ["app/Services/WebsiteDiagnosisService.php"],
  "must_not_do": ["No secrets"],
  "tests_required": ["PHPUnit"],
  "product_spec_paths": ["docs/product/website/DIAGNOSIS.md"],
  "reason": "Roadmap stage 11"
}}
```
</details>
"""


def _task() -> dict:
    return extract_task_json_from_pr_body(SAMPLE_BODY) or {}


class LegacyInactiveTests(unittest.TestCase):
    def test_legacy_stub_is_manual_only(self) -> None:
        text = (ROOT / ".github/workflows/dop-autopilot.yml").read_text(encoding="utf-8")
        self.assertTrue(legacy_stub_is_manual_only(text))
        self.assertNotRegex(text, r"(?m)^\s*repository_dispatch\s*:")

    def test_pr_gate_has_no_legacy_orchestration(self) -> None:
        text = (ROOT / ".github/workflows/dop-pr-gate.yml").read_text(encoding="utf-8")
        errors = active_workflow_forbids_legacy_dispatch(text)
        self.assertEqual(errors, [])

    def test_pr_gate_does_not_select_tasks(self) -> None:
        text = (ROOT / ".github/workflows/dop-pr-gate.yml").read_text(encoding="utf-8")
        self.assertNotIn("Run Architect", text)
        self.assertNotIn("architect.py", text)

    def test_pr_gate_does_not_implement_recovery(self) -> None:
        text = (ROOT / ".github/workflows/dop-pr-gate.yml").read_text(encoding="utf-8")
        self.assertNotIn("recover_supervisor", text)
        self.assertNotIn("quality_gates_with_fix", text)
        self.assertNotIn("event_type=", text)


class PrMetadataTests(unittest.TestCase):
    def test_extract_task_from_body(self) -> None:
        task = extract_task_json_from_pr_body(SAMPLE_BODY)
        assert task is not None
        self.assertEqual(task["task_id"], "website-diagnosis-ssl-check")
        self.assertTrue(is_automation_product_pr(SAMPLE_BODY))


class ReviewerCiSignalTests(unittest.TestCase):
    def test_fix_required_produces_readable_failure(self) -> None:
        review = {
            "verdict": "FIX_REQUIRED",
            "summary": "SSL evidence incomplete",
            "model": "gpt-5-mini",
            "reviewed_head_sha": "abc123",
            "issues": [
                {
                    "severity": "high",
                    "file": "app/Services/WebsiteDiagnosisService.php",
                    "problem": "missing evidence type",
                    "required_fix": "add ssl_certificate evidence",
                }
            ],
        }
        text = format_fix_required_ci_failure(review)
        self.assertIn("FIX_REQUIRED", text)
        self.assertIn("missing evidence type", text)
        self.assertIn("Cursor Automation", text)
        self.assertTrue(reviewer_verdict_blocks_merge("FIX_REQUIRED"))
        self.assertTrue(reviewer_verdict_blocks_merge("HUMAN_REQUIRED"))
        self.assertFalse(reviewer_verdict_blocks_merge("APPROVED"))


class MergeGateTests(unittest.TestCase):
    def _approved_evidence(self, sha: str = "deadbeef") -> dict:
        return build_review_evidence(
            task_id="website-diagnosis-ssl-check",
            reviewed_head_sha=sha,
            verdict="APPROVED",
            model="gpt-5-mini",
            run_id="1",
        )

    def test_approved_matching_sha_permits_merge(self) -> None:
        errors = pr_gate_may_merge(
            tests_passed=True,
            pint_passed=True,
            secrets_passed=True,
            infra_passed=True,
            reviewer_verdict="APPROVED",
            review_evidence=self._approved_evidence("abc"),
            current_head_sha="abc",
            task=_task(),
            pr_body=SAMPLE_BODY,
            branch_name="dop/website-diagnosis-ssl-check",
            mergeable=True,
            repo_root=ROOT,
        )
        self.assertEqual(errors, [])

    def test_sha_mismatch_blocks_merge(self) -> None:
        errors = validate_review_evidence(
            self._approved_evidence("aaa"),
            current_head_sha="bbb",
            expected_task_id="website-diagnosis-ssl-check",
        )
        self.assertTrue(any("does not match" in e for e in errors))

    def test_failed_phpunit_blocks_merge(self) -> None:
        errors = pr_gate_may_merge(
            tests_passed=False,
            pint_passed=True,
            secrets_passed=True,
            infra_passed=True,
            reviewer_verdict="APPROVED",
            review_evidence=self._approved_evidence("abc"),
            current_head_sha="abc",
            task=_task(),
            pr_body=SAMPLE_BODY,
            branch_name="dop/website-diagnosis-ssl-check",
            mergeable=True,
            repo_root=ROOT,
        )
        self.assertTrue(errors)

    def test_failed_pint_blocks_merge(self) -> None:
        errors = pr_gate_may_merge(
            tests_passed=True,
            pint_passed=False,
            secrets_passed=True,
            infra_passed=True,
            reviewer_verdict="APPROVED",
            review_evidence=self._approved_evidence("abc"),
            current_head_sha="abc",
            task=_task(),
            pr_body=SAMPLE_BODY,
            branch_name="dop/website-diagnosis-ssl-check",
            mergeable=True,
            repo_root=ROOT,
        )
        self.assertTrue(errors)

    def test_no_merge_without_reviewer(self) -> None:
        errors = final_merge_gate_errors(
            branch_name="dop/website-diagnosis-ssl-check",
            pr_body=SAMPLE_BODY,
            task=_task(),
            secret_paths=[],
            suspicious_diff=False,
            tests_passed=True,
            mergeable=True,
            repo_root=ROOT,
            review_evidence=None,
            current_head_sha="abc",
            require_review_approval=True,
        )
        self.assertTrue(any("review evidence missing" in e for e in errors))


class StatusV2Tests(unittest.TestCase):
    def test_status_still_renders(self) -> None:
        snap = build_snapshot(ROOT, overall_status=RUNNING, run_outcome="COMPLETED_AND_CONTINUING")
        md = render_project_status_markdown(snap)
        self.assertIn("DOP Project Status", md)
        self.assertIn("/ 23", md)
        self.assertNotIn("workflow run number", md.lower())

    def test_roadmap_complete_does_not_start_further_work(self) -> None:
        snap = build_snapshot(ROOT, overall_status=ROADMAP_COMPLETE, run_outcome=ROADMAP_COMPLETE)
        summary = render_actions_summary(snap)
        self.assertIn("ROADMAP_COMPLETE", summary)
        self.assertIn("🎉 DOP canonical roadmap complete", summary)
        # PR gate must not dispatch; status commit skip remains true.
        self.assertTrue(
            status_only_commit_must_not_dispatch(
                "chore(status): update DOP project status",
                ["docs/PROJECT_STATUS.md"],
            )
        )

    def test_hard_blocked_and_recovering_render(self) -> None:
        self.assertIn("HARD_BLOCKED", render_actions_summary(build_snapshot(ROOT, overall_status=HARD_BLOCKED)))
        self.assertIn("RECOVERING", render_actions_summary(build_snapshot(ROOT, overall_status=RECOVERING)))

    def test_status_only_commits_cannot_create_duplicate_product_tasks(self) -> None:
        self.assertTrue(
            status_only_commit_must_not_dispatch(
                "chore(status): update DOP project status",
                ["docs/PROJECT_STATUS.md"],
            )
        )


if __name__ == "__main__":
    unittest.main()
