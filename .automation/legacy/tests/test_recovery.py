"""Tests for DOP Autopilot self-healing / recovery helpers (legacy archive)."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
import sys

LEGACY = Path(__file__).resolve().parents[1]
AUTOMATION = LEGACY.parent
ROOT = AUTOMATION.parent
sys.path.insert(0, str(AUTOMATION))
sys.path.insert(0, str(LEGACY))

from common import is_safe_product_spec_path  # noqa: E402
from recovery import (  # noqa: E402
    DEPENDENCY_OR_ENV,
    HARD_BLOCKER,
    IMPLEMENTATION_FAILURE,
    MAX_IMPLEMENTATION_FIX,
    MAX_INFRA_RETRY,
    MAX_REVIEWER_RETRY,
    REVIEW_FAILURE,
    SECURITY_BLOCKER,
    TRANSIENT_INFRA,
    build_recovery_payload,
    can_retry,
    classify_failure,
    increment_retry_count,
    parse_recovery_client_payload,
    recovery_should_run_architect,
    repair_invalid_product_spec_paths,
    should_ignore_stale_recovery,
    would_create_infinite_recovery_loop,
)


class ClassifyFailureTests(unittest.TestCase):
    def test_transient_infra(self) -> None:
        self.assertEqual(
            classify_failure(step_name="Install Composer dependencies", error_text="curl: (28) timed out"),
            TRANSIENT_INFRA,
        )

    def test_dependency_or_env(self) -> None:
        self.assertEqual(
            classify_failure(
                step_name="Initial quality gates",
                error_text="No application encryption key has been specified. Missing APP_KEY",
            ),
            DEPENDENCY_OR_ENV,
        )

    def test_implementation_failure(self) -> None:
        self.assertEqual(
            classify_failure(
                step_name="Initial quality gates",
                error_text="FAIL  Tests\\Feature\\FooTest\nThere were 2 failures",
            ),
            IMPLEMENTATION_FAILURE,
        )
        self.assertEqual(
            classify_failure(
                step_name="Run Architect",
                error_text="Architect JSON failed validation: unsafe or invalid product_spec_path: docs/website/DIAGNOSIS_CATALOG.md",
            ),
            IMPLEMENTATION_FAILURE,
        )

    def test_reviewer_auth_is_hard_blocker(self) -> None:
        self.assertEqual(
            classify_failure(step_name="Review and fix loop", error_text="Incorrect API key provided"),
            HARD_BLOCKER,
        )

    def test_reviewer_timeout_transient(self) -> None:
        self.assertEqual(
            classify_failure(step_name="review", error_text="OpenAI API timeout after 60s"),
            TRANSIENT_INFRA,
        )

    def test_security_blocker(self) -> None:
        self.assertEqual(
            classify_failure(step_name="Final merge", error_text="Secret-like paths detected: .env"),
            SECURITY_BLOCKER,
        )

    def test_product_patch_credential_scan_is_implementation(self) -> None:
        self.assertEqual(
            classify_failure(
                step_name="Create PR",
                error_text="Credential-like patterns in product patch",
            ),
            IMPLEMENTATION_FAILURE,
        )


class RetryLimitTests(unittest.TestCase):
    def test_transient_retry_budget(self) -> None:
        counts = {"infra": 0, "implementation": 0, "reviewer": 0, "architect": 0, "dependency": 0}
        for i in range(MAX_INFRA_RETRY):
            self.assertTrue(can_retry(TRANSIENT_INFRA, counts))
            counts = increment_retry_count(TRANSIENT_INFRA, counts)
        self.assertFalse(can_retry(TRANSIENT_INFRA, counts))

    def test_implementation_fix_budget(self) -> None:
        counts = {}
        for _ in range(MAX_IMPLEMENTATION_FIX):
            self.assertTrue(can_retry(IMPLEMENTATION_FAILURE, counts))
            counts = increment_retry_count(IMPLEMENTATION_FAILURE, counts)
        self.assertFalse(can_retry(IMPLEMENTATION_FAILURE, counts))

    def test_reviewer_retry_budget(self) -> None:
        counts = {}
        for _ in range(MAX_REVIEWER_RETRY):
            self.assertTrue(can_retry(REVIEW_FAILURE, counts))
            counts = increment_retry_count(REVIEW_FAILURE, counts)
        self.assertFalse(can_retry(REVIEW_FAILURE, counts))

    def test_hard_blocker_after_max_retries(self) -> None:
        counts = increment_retry_count(TRANSIENT_INFRA, {"infra": MAX_INFRA_RETRY})
        self.assertFalse(can_retry(TRANSIENT_INFRA, counts))
        self.assertTrue(
            would_create_infinite_recovery_loop(
                failure_class=TRANSIENT_INFRA,
                retry_counts=counts,
            )
        )


class RecoverTaskSemanticsTests(unittest.TestCase):
    def test_dop_recover_does_not_choose_new_architect_when_task_exists(self) -> None:
        payload = parse_recovery_client_payload(
            {
                "original_task_id": "website-diagnosis-catalog",
                "original_branch": "feature/website-diagnosis",
                "failure_class": IMPLEMENTATION_FAILURE,
                "retry_counts": {"implementation": 1},
                "stage": "initial_quality_gates",
            }
        )
        self.assertFalse(recovery_should_run_architect(payload))

    def test_architect_stage_recover_may_rerun_architect(self) -> None:
        payload = parse_recovery_client_payload(
            {
                "failure_class": IMPLEMENTATION_FAILURE,
                "stage": "architect",
                "retry_counts": {"implementation": 1},
            }
        )
        self.assertTrue(recovery_should_run_architect(payload))

    def test_branch_without_open_pr_reruns_architect(self) -> None:
        payload = parse_recovery_client_payload(
            {
                "original_task_id": "core-connections-filament",
                "original_branch": "feat/core-connections-filament-resources",
                "failure_class": IMPLEMENTATION_FAILURE,
                "stage": "create_pr",
            }
        )
        self.assertTrue(
            recovery_should_run_architect(payload, open_pr_branches=set())
        )

    def test_merged_task_ignores_stale_recovery(self) -> None:
        self.assertTrue(
            should_ignore_stale_recovery(
                task_id="task-a",
                branch="feature/a",
                merged_task_ids={"task-a"},
                merged_branches={"feature/a"},
            )
        )

    def test_open_branch_not_stale(self) -> None:
        self.assertFalse(
            should_ignore_stale_recovery(
                task_id="task-a",
                branch="feature/a",
                merged_task_ids=set(),
                merged_branches=set(),
                open_pr_branches={"feature/a"},
            )
        )

    def test_no_infinite_recovery_loop_guard(self) -> None:
        counts = {"infra": MAX_INFRA_RETRY, "implementation": 0, "reviewer": 0, "architect": 0, "dependency": 0}
        self.assertTrue(
            would_create_infinite_recovery_loop(
                failure_class=TRANSIENT_INFRA,
                retry_counts=counts,
                failed_run_id="1",
            )
        )

    def test_build_recovery_payload_shape(self) -> None:
        payload = build_recovery_payload(
            task_id="t1",
            branch="feat/t1",
            failure_class=TRANSIENT_INFRA,
            retry_counts={"infra": 1},
            failed_run_id="99",
            stage="composer",
        )
        self.assertEqual(payload["original_task_id"], "t1")
        self.assertEqual(payload["original_branch"], "feat/t1")
        self.assertEqual(payload["failure_class"], TRANSIENT_INFRA)
        self.assertEqual(payload["failed_run_id"], "99")
        self.assertIn("retry_counts", payload)


class PathRepairTests(unittest.TestCase):
    def test_repairs_docs_website_diagnosis_catalog(self) -> None:
        repaired = repair_invalid_product_spec_paths(
            ["docs/website/DIAGNOSIS_CATALOG.md"],
            repo_root=ROOT,
            is_safe_path=is_safe_product_spec_path,
        )
        self.assertEqual(repaired, ["docs/product/website/DIAGNOSIS.md"])


class DependencyRecoveryHintTests(unittest.TestCase):
    def test_dependency_class_is_retryable(self) -> None:
        self.assertTrue(can_retry(DEPENDENCY_OR_ENV, {}))
        counts = increment_retry_count(DEPENDENCY_OR_ENV, {})
        self.assertEqual(counts["dependency"], 1)


if __name__ == "__main__":
    unittest.main()
