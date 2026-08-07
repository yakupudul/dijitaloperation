#!/usr/bin/env python3
from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = ROOT.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from common import (  # noqa: E402
    AUTOMATION_PR_MARKER,
    FIX_COMMIT_MESSAGE,
    HARD_BLOCKER_ISSUE_MARKER,
    MAX_FIX_ATTEMPTS,
    count_fix_attempts_from_commit_messages,
    diff_is_suspiciously_large,
    dispatch_eligible,
    extract_task_ids_from_pr_bodies,
    final_merge_gate_errors,
    find_secret_like_paths,
    format_hard_blocker_issue_body,
    has_automation_pr_marker,
    is_repeated_task,
    is_safe_branch_name,
    is_safe_product_spec_path,
    list_product_spec_files,
    load_product_specs,
    parse_json_object,
    remaining_fix_attempts,
    review_marker_for_verdict,
    scan_diff_for_credential_leaks,
    should_create_hard_blocker_issue,
    should_skip_next_task_for_merged_pr,
    validate_architect_task,
    validate_product_spec_paths,
    validate_reviewer_result,
)


def _task_ready(**overrides):
    data = {
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
        "product_spec_paths": ["docs/product/CUSTOMER.md"],
        "reason": "Next roadmap item",
    }
    data.update(overrides)
    return data


class BranchValidationTests(unittest.TestCase):
    def test_accepts_safe_slugs(self) -> None:
        self.assertTrue(is_safe_branch_name("feat/customer-crud"))

    def test_rejects_unsafe_names(self) -> None:
        self.assertFalse(is_safe_branch_name("main"))
        self.assertFalse(is_safe_branch_name("../etc/passwd"))


class MarkerTests(unittest.TestCase):
    def test_automation_marker_detection(self) -> None:
        self.assertTrue(has_automation_pr_marker(f"hello\n{AUTOMATION_PR_MARKER}\n"))
        self.assertFalse(has_automation_pr_marker("ordinary PR"))


class FixAttemptTests(unittest.TestCase):
    def test_max_attempts(self) -> None:
        messages = [FIX_COMMIT_MESSAGE] * MAX_FIX_ATTEMPTS
        self.assertEqual(remaining_fix_attempts(messages), 0)
        self.assertEqual(count_fix_attempts_from_commit_messages(messages), 3)


class SecretPathTests(unittest.TestCase):
    def test_detects_secret_files(self) -> None:
        hits = find_secret_like_paths([".env", "app/Models/User.php", "keys/id_rsa", "cert.pem"])
        self.assertEqual(hits, [".env", "keys/id_rsa", "cert.pem"])

    def test_diff_credential_scan(self) -> None:
        dirty = "password = 'super-secret-value'"
        self.assertTrue(scan_diff_for_credential_leaks(dirty))
        self.assertFalse(scan_diff_for_credential_leaks("password length must be >= 8"))


class SuspiciousDiffTests(unittest.TestCase):
    def test_large_diff(self) -> None:
        self.assertTrue(diff_is_suspiciously_large("x" * 300_000))
        self.assertFalse(diff_is_suspiciously_large("diff --git a/app/x.php\n+hi\n"))


class ProductSpecPathTests(unittest.TestCase):
    def test_connection_and_module_platform_paths(self) -> None:
        for path in (
            "docs/product/CONNECTION.md",
            "docs/product/MODULE_PLATFORM.md",
            "docs/product/CUSTOMER.md",
        ):
            self.assertTrue(is_safe_product_spec_path(path))
            self.assertEqual(validate_product_spec_paths([path], repo_root=REPO_ROOT), [])

    def test_rejects_traversal(self) -> None:
        self.assertFalse(is_safe_product_spec_path("docs/product/../MASTER_SPEC.md"))

    def test_task_ready_empty_product_specs_rejected(self) -> None:
        data = _task_ready(product_spec_paths=[])
        errors = validate_architect_task(data, repo_root=REPO_ROOT)
        self.assertTrue(any("must not be empty" in e for e in errors))

    def test_catalog_includes_new_blueprints(self) -> None:
        catalog = list_product_spec_files(REPO_ROOT)
        self.assertIn("docs/product/CONNECTION.md", catalog)
        self.assertIn("docs/product/MODULE_PLATFORM.md", catalog)


class RepeatedTaskTests(unittest.TestCase):
    def test_repeated_task_id(self) -> None:
        task = _task_ready(task_id="task-customer-1")
        self.assertTrue(is_repeated_task(task, merged_task_ids={"task-customer-1"}))
        self.assertFalse(is_repeated_task(task, merged_task_ids={"other"}))

    def test_extract_task_ids(self) -> None:
        body = f"{AUTOMATION_PR_MARKER}\n- **task_id:** `task-abc`\n"
        self.assertIn("task-abc", extract_task_ids_from_pr_bodies([body]))


class HardBlockerTests(unittest.TestCase):
    def test_hard_blocker_helper(self) -> None:
        self.assertTrue(should_create_hard_blocker_issue("HUMAN_REQUIRED", "missing secret"))
        self.assertFalse(should_create_hard_blocker_issue("TASK_READY", "x"))
        body = format_hard_blocker_issue_body(reason="blocked", task={"task_id": "x"})
        self.assertIn(HARD_BLOCKER_ISSUE_MARKER, body)


class MergeGateTests(unittest.TestCase):
    def test_final_merge_gate(self) -> None:
        task = _task_ready()
        errors = final_merge_gate_errors(
            branch_name="feat/customer-model",
            pr_body=f"{AUTOMATION_PR_MARKER}\n",
            task=task,
            secret_paths=[],
            suspicious_diff=False,
            tests_passed=True,
            mergeable=True,
            repo_root=REPO_ROOT,
        )
        self.assertEqual(errors, [])
        bad = final_merge_gate_errors(
            branch_name="main",
            pr_body="no marker",
            task=task,
            secret_paths=[".env"],
            suspicious_diff=True,
            tests_passed=False,
            mergeable=False,
            repo_root=REPO_ROOT,
        )
        self.assertGreaterEqual(len(bad), 4)

    def test_dispatch_eligible(self) -> None:
        self.assertTrue(
            dispatch_eligible(
                merged=True,
                automation_pr=True,
                verdict_approved=True,
                hard_blocker=False,
                roadmap_complete=False,
            )
        )
        self.assertFalse(
            dispatch_eligible(
                merged=True,
                automation_pr=True,
                verdict_approved=True,
                hard_blocker=True,
                roadmap_complete=False,
            )
        )


class SchemaValidationTests(unittest.TestCase):
    def test_architect_task_ready(self) -> None:
        self.assertEqual(validate_architect_task(_task_ready(), repo_root=REPO_ROOT), [])

    def test_architect_requires_product_spec_paths_field(self) -> None:
        data = _task_ready()
        del data["product_spec_paths"]
        errors = validate_architect_task(data)
        self.assertTrue(any("product_spec_paths" in e for e in errors))

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


class ReviewerTaskFileTests(unittest.TestCase):
    def test_reviewer_task_file_context_loading(self) -> None:
        from reviewer import build_review_payload

        task = _task_ready(product_spec_paths=["docs/product/CONNECTION.md"])
        payload = build_review_payload(
            title="feat: connection",
            body=f"{AUTOMATION_PR_MARKER}\n",
            changed_files="app/Models/Connection.php\n",
            diff_text="diff --git a/x\n",
            test_notes="ok",
            task_json=task,
        )
        self.assertIn("docs/product/CONNECTION.md", payload)
        self.assertIn("Connection", payload)


class SkipNextTaskTests(unittest.TestCase):
    def test_skips_chore(self) -> None:
        self.assertTrue(should_skip_next_task_for_merged_pr("chore/dop-autopilot"))


if __name__ == "__main__":
    unittest.main()
