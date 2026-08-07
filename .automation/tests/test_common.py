#!/usr/bin/env python3
from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from common import (  # noqa: E402
    AUTOMATION_PR_MARKER,
    FIX_COMMIT_MESSAGE,
    MAX_FIX_ATTEMPTS,
    count_fix_attempts_from_commit_messages,
    find_secret_like_paths,
    has_automation_pr_marker,
    is_safe_branch_name,
    parse_json_object,
    remaining_fix_attempts,
    review_marker_for_verdict,
    should_skip_next_task_for_merged_pr,
    validate_architect_task,
    validate_reviewer_result,
)


class BranchValidationTests(unittest.TestCase):
    def test_accepts_safe_slugs(self) -> None:
        self.assertTrue(is_safe_branch_name("feat/customer-crud"))
        self.assertTrue(is_safe_branch_name("fix/panel-access"))

    def test_rejects_unsafe_names(self) -> None:
        self.assertFalse(is_safe_branch_name("main"))
        self.assertFalse(is_safe_branch_name("../etc/passwd"))
        self.assertFalse(is_safe_branch_name("Feat/Customer"))
        self.assertFalse(is_safe_branch_name("has space"))
        self.assertFalse(is_safe_branch_name("-bad"))


class MarkerTests(unittest.TestCase):
    def test_automation_marker_detection(self) -> None:
        self.assertTrue(has_automation_pr_marker(f"hello\n{AUTOMATION_PR_MARKER}\n"))
        self.assertFalse(has_automation_pr_marker("ordinary PR"))

    def test_review_markers(self) -> None:
        self.assertIn("APPROVED", review_marker_for_verdict("APPROVED"))
        self.assertIn("FIX_REQUIRED", review_marker_for_verdict("FIX_REQUIRED"))
        self.assertIn("HUMAN_REQUIRED", review_marker_for_verdict("HUMAN_REQUIRED"))


class FixAttemptTests(unittest.TestCase):
    def test_counts_exact_fix_commits(self) -> None:
        messages = [
            FIX_COMMIT_MESSAGE,
            "feat: something else",
            FIX_COMMIT_MESSAGE + "\n\nbody",
        ]
        self.assertEqual(count_fix_attempts_from_commit_messages(messages), 2)
        self.assertEqual(remaining_fix_attempts(messages), MAX_FIX_ATTEMPTS - 2)

    def test_max_attempts_exhausted(self) -> None:
        messages = [FIX_COMMIT_MESSAGE] * MAX_FIX_ATTEMPTS
        self.assertEqual(remaining_fix_attempts(messages), 0)


class SkipNextTaskTests(unittest.TestCase):
    def test_skips_chore_and_docs(self) -> None:
        self.assertTrue(should_skip_next_task_for_merged_pr("chore/dop-development-loop"))
        self.assertTrue(should_skip_next_task_for_merged_pr("feat/x", pr_title="docs: update"))
        self.assertTrue(
            should_skip_next_task_for_merged_pr(
                "feat/x",
                pr_title="feat: docs only",
                changed_files=["docs/MASTER_SPEC.md", "AGENTS.md"],
            )
        )

    def test_allows_product_feature_branch(self) -> None:
        self.assertFalse(
            should_skip_next_task_for_merged_pr(
                "feat/customer-crud",
                pr_title="feat: add customers",
                changed_files=["app/Models/Customer.php"],
            )
        )


class SecretPathTests(unittest.TestCase):
    def test_detects_secret_files(self) -> None:
        hits = find_secret_like_paths([".env", "app/Models/User.php", "keys/id_rsa", "cert.pem"])
        self.assertEqual(hits, [".env", "keys/id_rsa", "cert.pem"])


class SchemaValidationTests(unittest.TestCase):
    def test_architect_task_ready(self) -> None:
        data = parse_json_object(
            """
            {
              "status": "TASK_READY",
              "task_id": "task-customer-1",
              "title": "Add Customer model",
              "branch_name": "feat/customer-model",
              "objective": "Create Customer CRUD basics",
              "instructions": "Implement Customer model and Filament resource",
              "acceptance_criteria": ["Customer can be created"],
              "files_or_areas": ["app/", "database/"],
              "must_not_do": ["Do not add Brand"],
              "tests_required": ["php artisan test --filter=Customer"],
              "reason": "Next roadmap item"
            }
            """
        )
        self.assertEqual(validate_architect_task(data), [])

    def test_architect_rejects_bad_branch(self) -> None:
        data = {
            "status": "TASK_READY",
            "task_id": "x",
            "title": "x",
            "branch_name": "Main",
            "objective": "x",
            "instructions": "x",
            "acceptance_criteria": ["a"],
            "files_or_areas": ["a"],
            "must_not_do": ["a"],
            "tests_required": ["a"],
            "reason": "x",
        }
        errors = validate_architect_task(data)
        self.assertTrue(any("branch_name" in error for error in errors))

    def test_reviewer_fix_requires_issues(self) -> None:
        data = {
            "verdict": "FIX_REQUIRED",
            "summary": "Needs work",
            "issues": [],
            "scope_check": "ok",
            "architecture_check": "ok",
            "test_check": "missing",
        }
        errors = validate_reviewer_result(data)
        self.assertTrue(any("issue" in error for error in errors))

    def test_reviewer_approved(self) -> None:
        data = {
            "verdict": "APPROVED",
            "summary": "Looks good",
            "issues": [],
            "scope_check": "in scope",
            "architecture_check": "aligned",
            "test_check": "covered",
        }
        self.assertEqual(validate_reviewer_result(data), [])


if __name__ == "__main__":
    unittest.main()
