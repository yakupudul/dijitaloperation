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
    assert_product_branch_diff_safe,
    build_review_evidence,
    count_fix_attempts_from_commit_messages,
    diff_is_suspiciously_large,
    dispatch_eligible,
    extract_task_ids_from_pr_bodies,
    final_merge_gate_errors,
    find_product_infra_paths,
    find_secret_like_paths,
    format_hard_blocker_issue_body,
    has_automation_pr_marker,
    is_repeated_task,
    is_safe_branch_name,
    is_safe_product_spec_path,
    list_product_spec_files,
    load_product_specs,
    load_review_evidence,
    parse_json_object,
    remaining_fix_attempts,
    review_marker_for_verdict,
    scan_diff_for_credential_leaks,
    should_create_hard_blocker_issue,
    should_skip_next_task_for_merged_pr,
    task_allows_infra_paths,
    validate_architect_task,
    validate_product_spec_paths,
    validate_review_evidence,
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
    def _approved_evidence(self, sha: str = "abc123") -> dict:
        return build_review_evidence(
            task_id="task-customer-1",
            reviewed_head_sha=sha,
            verdict="APPROVED",
            model="gpt-5-mini",
            run_id="123",
        )

    def test_final_merge_gate(self) -> None:
        task = _task_ready()
        sha = "deadbeefcafebabe"
        errors = final_merge_gate_errors(
            branch_name="feat/customer-model",
            pr_body=f"{AUTOMATION_PR_MARKER}\n",
            task=task,
            secret_paths=[],
            suspicious_diff=False,
            tests_passed=True,
            mergeable=True,
            repo_root=REPO_ROOT,
            review_evidence=self._approved_evidence(sha),
            current_head_sha=sha,
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
            review_evidence=None,
            current_head_sha=sha,
        )
        self.assertGreaterEqual(len(bad), 5)

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


class ReviewerFailClosedTests(unittest.TestCase):
    def _base_kwargs(self, **overrides):
        sha = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
        data = {
            "branch_name": "feat/customer-model",
            "pr_body": f"{AUTOMATION_PR_MARKER}\n",
            "task": _task_ready(),
            "secret_paths": [],
            "suspicious_diff": False,
            "tests_passed": True,
            "mergeable": True,
            "repo_root": REPO_ROOT,
            "review_evidence": build_review_evidence(
                task_id="task-customer-1",
                reviewed_head_sha=sha,
                verdict="APPROVED",
                model="gpt-5-mini",
                run_id="run-1",
            ),
            "current_head_sha": sha,
            "require_review_approval": True,
        }
        data.update(overrides)
        return data

    def test_missing_review_evidence_cannot_merge(self) -> None:
        errors = final_merge_gate_errors(**self._base_kwargs(review_evidence=None))
        self.assertTrue(any("review evidence missing" in e for e in errors))

    def test_missing_api_key_cannot_produce_approved_evidence(self) -> None:
        # Simulate reviewer process failure: no evidence file / incomplete evidence.
        incomplete = {"verdict": "APPROVED"}
        errors = validate_review_evidence(
            incomplete,
            current_head_sha="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
        )
        self.assertTrue(any("missing field" in e for e in errors))

    def test_reviewer_process_failure_cannot_merge(self) -> None:
        # No evidence path / load returns None after process failure.
        self.assertIsNone(load_review_evidence(REPO_ROOT / "does-not-exist-review.json"))
        errors = final_merge_gate_errors(**self._base_kwargs(review_evidence=None))
        self.assertTrue(errors)

    def test_fix_required_cannot_merge(self) -> None:
        evidence = build_review_evidence(
            task_id="task-customer-1",
            reviewed_head_sha="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            verdict="FIX_REQUIRED",
            model="gpt-5-mini",
            run_id="run-1",
        )
        errors = final_merge_gate_errors(**self._base_kwargs(review_evidence=evidence))
        self.assertTrue(any("not APPROVED" in e for e in errors))

    def test_human_required_cannot_merge(self) -> None:
        evidence = build_review_evidence(
            task_id="task-customer-1",
            reviewed_head_sha="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            verdict="HUMAN_REQUIRED",
            model="gpt-5-mini",
            run_id="run-1",
        )
        errors = final_merge_gate_errors(**self._base_kwargs(review_evidence=evidence))
        self.assertTrue(any("not APPROVED" in e for e in errors))

    def test_approved_with_all_gates_can_merge(self) -> None:
        errors = final_merge_gate_errors(**self._base_kwargs())
        self.assertEqual(errors, [])

    def test_approved_sha_mismatch_requires_fresh_review(self) -> None:
        errors = final_merge_gate_errors(
            **self._base_kwargs(current_head_sha="cccccccccccccccccccccccccccccccccccccccc")
        )
        self.assertTrue(any("does not match current HEAD" in e for e in errors))

    def test_tests_passing_without_approval_cannot_merge(self) -> None:
        errors = final_merge_gate_errors(
            **self._base_kwargs(review_evidence=None, tests_passed=True)
        )
        self.assertTrue(any("review evidence missing" in e for e in errors))


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
        self.assertIn("CORE_RULES", payload)
        # Minimal context: no full MASTER_SPEC body / unrelated blueprints / roadmap dump.
        self.assertNotIn("### docs/MASTER_SPEC.md", payload)
        self.assertNotIn("### docs/product/website/GA4.md", payload)
        self.assertNotIn("### docs/product/website/DATAFORSEO.md", payload)
        self.assertNotIn("### docs/IMPLEMENTATION_ROADMAP.md", payload)

    def test_reviewer_includes_previous_issues_on_fix_round(self) -> None:
        from reviewer import build_review_payload

        payload = build_review_payload(
            title="feat: x",
            body=f"{AUTOMATION_PR_MARKER}\n",
            changed_files="a.php\n",
            diff_text="diff\n",
            test_notes="ok",
            task_json=_task_ready(),
            previous_issues_summary="- [high] a.php: missing fillable => add fillable",
        )
        self.assertIn("Previous reviewer issues", payload)
        self.assertIn("missing fillable", payload)


class SkipNextTaskTests(unittest.TestCase):
    def test_skips_chore(self) -> None:
        self.assertTrue(should_skip_next_task_for_merged_pr("chore/dop-autopilot"))


class ModelDefaultTests(unittest.TestCase):
    def test_defaults_are_gpt_5_mini(self) -> None:
        from common import (
            DEFAULT_ARCHITECT_MODEL,
            DEFAULT_ESCALATION_MODEL,
            DEFAULT_REVIEWER_MODEL,
            resolve_model,
        )

        self.assertEqual(DEFAULT_ARCHITECT_MODEL, "gpt-5-mini")
        self.assertEqual(DEFAULT_REVIEWER_MODEL, "gpt-5-mini")
        self.assertTrue(DEFAULT_ESCALATION_MODEL)
        self.assertNotEqual(DEFAULT_ESCALATION_MODEL, "gpt-5-nano")

        import os

        os.environ.pop("OPENAI_ARCHITECT_MODEL", None)
        self.assertEqual(resolve_model("OPENAI_ARCHITECT_MODEL", DEFAULT_ARCHITECT_MODEL), "gpt-5-mini")
        os.environ["OPENAI_ARCHITECT_MODEL"] = ""
        self.assertEqual(resolve_model("OPENAI_ARCHITECT_MODEL", DEFAULT_ARCHITECT_MODEL), "gpt-5-mini")
        os.environ["OPENAI_ARCHITECT_MODEL"] = "gpt-4.1"
        self.assertEqual(resolve_model("OPENAI_ARCHITECT_MODEL", DEFAULT_ARCHITECT_MODEL), "gpt-4.1")
        os.environ.pop("OPENAI_ARCHITECT_MODEL", None)


class SelectiveContextTests(unittest.TestCase):
    def test_candidate_specs_exclude_unrelated_blueprints(self) -> None:
        from common import list_product_spec_files, select_candidate_product_specs

        all_specs = list_product_spec_files(REPO_ROOT)
        self.assertGreaterEqual(len(all_specs), 10)

        selected = select_candidate_product_specs(
            REPO_ROOT,
            merged_task_ids={"customer-model-and-migration", "customer-contact-model-and-migration"},
            commit_summary="feat: Customer Contact",
        )
        self.assertTrue(selected)
        self.assertLess(len(selected), len(all_specs))
        self.assertTrue(any(p.endswith("CUSTOMER.md") or p.endswith("BRAND.md") for p in selected))
        for banned in (
            "docs/product/website/GA4.md",
            "docs/product/website/DATAFORSEO.md",
            "docs/product/website/INSTAGRAM.md",
            "docs/product/future/DIGITAL_ASSETS.md",
        ):
            self.assertNotIn(banned, selected)

    def test_architect_payload_uses_core_rules_not_all_blueprints(self) -> None:
        from architect import architect_context_files, build_user_payload

        files = architect_context_files(
            merged_task_ids={"brand-model-and-migration"},
            commit_summary="feat: Brand model",
        )
        self.assertEqual(files["stable"], [".automation/context/CORE_RULES.md"])
        self.assertIn("docs/product/INDEX.md", files["planning"])
        self.assertNotIn("docs/MASTER_SPEC.md", files["planning"])
        self.assertTrue(files["product_specs"])
        self.assertNotIn("docs/product/website/GA4.md", files["product_specs"])

        payload = build_user_payload(
            merged_task_ids={"brand-model-and-migration"},
            commit_summary="feat: Brand model",
        )
        self.assertIn("CORE_RULES", payload)
        self.assertIn("docs/product/INDEX.md", payload)
        self.assertNotIn("### docs/MASTER_SPEC.md", payload)
        self.assertNotIn("### docs/product/website/GA4.md", payload)
        self.assertNotIn("### docs/product/website/DATAFORSEO.md", payload)
        # AGENTS should be truncated before Laravel Boost dump.
        self.assertNotIn("Laravel Boost Guidelines", payload)


class UsageExtractionTests(unittest.TestCase):
    def test_extract_usage_metrics(self) -> None:
        from types import SimpleNamespace

        from common import extract_usage_metrics, format_usage_summary

        response = SimpleNamespace(
            usage=SimpleNamespace(
                input_tokens=100,
                output_tokens=20,
                total_tokens=120,
                input_tokens_details=SimpleNamespace(cached_tokens=40),
            )
        )
        usage = extract_usage_metrics(response)
        self.assertEqual(usage["input_tokens"], 100)
        self.assertEqual(usage["cached_input_tokens"], 40)
        self.assertEqual(usage["output_tokens"], 20)
        line = format_usage_summary("architect", "gpt-5-mini", usage)
        self.assertIn("gpt-5-mini", line)
        self.assertIn("input_tokens=100", line)
        self.assertIn("cached_input_tokens=40", line)


class ProductInfraGateTests(unittest.TestCase):
    def test_detects_workflow_and_automation_paths(self) -> None:
        hits = find_product_infra_paths(
            [
                "app/Models/DigitalAsset.php",
                ".github/workflows/dop-autopilot.yml",
                ".automation/common.py",
            ]
        )
        self.assertEqual(
            hits,
            [".github/workflows/dop-autopilot.yml", ".automation/common.py"],
        )

    def test_product_diff_rejects_workflow_without_explicit_scope(self) -> None:
        errors = assert_product_branch_diff_safe(
            [".github/workflows/dop-autopilot.yml", "app/Models/X.php"],
            task=_task_ready(files_or_areas=["app/", "database/"]),
        )
        self.assertTrue(errors)
        self.assertIn(".github/workflows/dop-autopilot.yml", errors[0])

    def test_explicit_infra_scope_allows_automation_paths(self) -> None:
        task = _task_ready(files_or_areas=[".automation/", ".github/workflows/"])
        self.assertTrue(
            task_allows_infra_paths(
                task,
                [".automation/common.py", ".github/workflows/dop-autopilot.yml"],
            )
        )
        self.assertEqual(
            assert_product_branch_diff_safe(
                [".automation/scripts/prepare_product_branch.sh"],
                task=task,
            ),
            [],
        )

    def test_prepare_and_assert_scripts_exist(self) -> None:
        prepare = REPO_ROOT / ".automation" / "scripts" / "prepare_product_branch.sh"
        gate = REPO_ROOT / ".automation" / "scripts" / "assert_product_branch_infra.sh"
        self.assertTrue(prepare.is_file())
        self.assertTrue(gate.is_file())
        prepare_text = prepare.read_text(encoding="utf-8")
        self.assertIn("origin/main", prepare_text)
        self.assertIn("checkout --detach", prepare_text)
        gate_text = gate.read_text(encoding="utf-8")
        self.assertIn("origin/main...HEAD", gate_text)


class CiBootstrapTests(unittest.TestCase):
    """Fresh CI runners have no .env; quality gates must bootstrap a disposable APP_KEY."""

    def test_bootstrap_script_supports_missing_env(self) -> None:
        script = REPO_ROOT / ".automation" / "scripts" / "bootstrap_test_env.sh"
        self.assertTrue(script.is_file(), "bootstrap_test_env.sh must exist")
        text = script.read_text(encoding="utf-8")
        self.assertIn("cp .env.example .env", text)
        self.assertIn("key:generate --force", text)
        self.assertIn(">/dev/null", text)
        self.assertNotRegex(text, r"echo\s+.*APP_KEY")
        self.assertNotIn("git add .env", text)
        self.assertNotIn("gh secret", text)

    def test_quality_gates_invoke_bootstrap_before_laravel_tests(self) -> None:
        quality = (REPO_ROOT / ".automation" / "scripts" / "quality_gates.sh").read_text(
            encoding="utf-8"
        )
        self.assertIn("bootstrap_test_env.sh", quality)
        bootstrap_at = quality.index("bootstrap_test_env.sh")
        test_at = quality.index("php artisan test")
        self.assertLess(bootstrap_at, test_at)

    def test_phpunit_keeps_sqlite_memory_without_changing_env_example(self) -> None:
        phpunit = (REPO_ROOT / "phpunit.xml").read_text(encoding="utf-8")
        self.assertIn('name="DB_CONNECTION" value="sqlite"', phpunit)
        self.assertIn('name="DB_DATABASE" value=":memory:"', phpunit)
        example = (REPO_ROOT / ".env.example").read_text(encoding="utf-8")
        self.assertIn("DB_CONNECTION=mysql", example)


if __name__ == "__main__":
    unittest.main()
