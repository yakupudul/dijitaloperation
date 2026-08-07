#!/usr/bin/env python3
"""DOP Reviewer — review automation PRs against docs and task scope."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

AUTOMATION_DIR = Path(__file__).resolve().parent
ROOT = AUTOMATION_DIR.parent
if str(AUTOMATION_DIR) not in sys.path:
    sys.path.insert(0, str(AUTOMATION_DIR))

from common import (  # noqa: E402
    AUTOMATION_PR_MARKER,
    DEFAULT_ESCALATION_MODEL,
    DEFAULT_REASONING_EFFORT,
    DEFAULT_REVIEWER_MODEL,
    MAX_DIFF_CHARS_FOR_REVIEW,
    append_usage_record,
    diff_is_suspiciously_large,
    extract_response_text,
    extract_usage_metrics,
    find_adr_ids,
    format_usage_summary,
    has_automation_pr_marker,
    load_adr_excerpts,
    load_core_rules,
    load_product_specs,
    load_prompt,
    openai_create_response,
    parse_json_object,
    resolve_model,
    review_marker_for_verdict,
    validate_product_spec_paths,
    validate_reviewer_result,
)

PROMPTS_DIR = AUTOMATION_DIR / "prompts"


def build_review_payload(
    *,
    title: str,
    body: str,
    changed_files: str,
    diff_text: str,
    test_notes: str,
    task_json: dict | None = None,
    previous_issues_summary: str = "",
) -> str:
    """Minimal reviewer context in cache-friendly order (stable → dynamic)."""
    truncated_diff = diff_text
    note = ""
    if diff_is_suspiciously_large(diff_text):
        note = (
            "\n\nWARNING: Diff is suspiciously large. "
            "Prefer HUMAN_REQUIRED unless the task clearly justifies it.\n"
        )
        truncated_diff = (
            diff_text[:MAX_DIFF_CHARS_FOR_REVIEW]
            + "\n\n... diff truncated for reviewer context ...\n"
        )
    elif len(diff_text) > MAX_DIFF_CHARS_FOR_REVIEW:
        truncated_diff = (
            diff_text[:MAX_DIFF_CHARS_FOR_REVIEW]
            + "\n\n... diff truncated for reviewer context ...\n"
        )

    task_section = "(no architect task file provided)\n"
    product_section = "(no product_spec_paths)\n"
    adr_section = "(no task-related ADRs selected)\n"
    if task_json is not None:
        task_section = json.dumps(task_json, ensure_ascii=False, indent=2) + "\n"
        paths = task_json.get("product_spec_paths") or []
        if paths:
            errors = validate_product_spec_paths(paths, repo_root=ROOT)
            if errors:
                product_section = "INVALID product_spec_paths: " + "; ".join(errors) + "\n"
            else:
                product_section = load_product_specs(ROOT, paths)
        adr_ids = find_adr_ids(
            product_section,
            json.dumps(task_json, ensure_ascii=False),
            body,
        )
        # Always include core decision ADRs referenced by invariants when mentioned in specs.
        adr_section = load_adr_excerpts(ROOT, adr_ids)

    previous = previous_issues_summary.strip()
    previous_section = (
        f"## Previous reviewer issues (fix round)\n{previous}\n\n" if previous else ""
    )

    # Order: stable CORE_RULES → product specs → ADRs → dynamic task/diff.
    return (
        "## CORE_RULES (stable)\n"
        f"{load_core_rules(ROOT)}\n\n"
        f"## Product blueprints for this task\n{product_section}\n"
        f"## Relevant ADR excerpts\n{adr_section}\n"
        f"{previous_section}"
        "Review this DOP automation PR and return JSON only.\n"
        f"Automation marker required in body: {AUTOMATION_PR_MARKER}\n"
        f"Marker present: {has_automation_pr_marker(body)}\n"
        "Check Product Blueprint behavior for product_spec_paths only. "
        "Do NOT block on nice-to-haves absent from the blueprint. "
        "MASTER_SPEC / CORE_RULES win on conflict.\n"
        "If APPROVED: keep summary/checks to one short sentence each; issues=[].\n\n"
        f"## Architect task JSON\n{task_section}\n"
        f"## PR title\n{title}\n\n"
        f"## PR body (marker / task metadata)\n{body}\n\n"
        f"## Changed files\n{changed_files}\n\n"
        f"## Test notes\n{test_notes or '(none provided)'}\n"
        f"{note}\n"
        f"## Git diff (changed hunks only)\n```diff\n{truncated_diff}\n```\n"
    )


def format_review_comment(data: dict) -> str:
    marker = review_marker_for_verdict(str(data["verdict"]))
    verdict = str(data.get("verdict") or "")
    if verdict == "APPROVED":
        lines = [
            marker,
            "",
            "## DOP automated review: APPROVED",
            "",
            data.get("summary", "OK"),
            "",
        ]
        return "\n".join(lines)

    lines = [
        marker,
        "",
        f"## DOP automated review: {verdict}",
        "",
        data.get("summary", ""),
        "",
        "### Scope check",
        data.get("scope_check", ""),
        "",
        "### Architecture check",
        data.get("architecture_check", ""),
        "",
        "### Test check",
        data.get("test_check", ""),
        "",
        "### Issues",
    ]
    issues = data.get("issues") or []
    if not issues:
        lines.append("- none")
    else:
        for issue in issues:
            lines.append(
                f"- **{issue.get('severity', '?')}** `{issue.get('file', '')}`: "
                f"{issue.get('problem', '')} → {issue.get('required_fix', '')}"
            )
    lines.append("")
    lines.append("<details><summary>Raw reviewer JSON</summary>")
    lines.append("")
    lines.append("```json")
    lines.append(json.dumps(data, ensure_ascii=False, indent=2))
    lines.append("```")
    lines.append("</details>")
    return "\n".join(lines) + "\n"


def call_reviewer(
    model: str,
    payload: str,
    *,
    role: str = "reviewer",
    reasoning_effort: str | None = None,
) -> dict:
    from openai import OpenAI

    api_key = os.environ.get("OPENAI_API_KEY")
    if not api_key:
        raise RuntimeError("OPENAI_API_KEY is not set")

    client = OpenAI(api_key=api_key)
    system_prompt = load_prompt(PROMPTS_DIR, "reviewer.md")
    response = openai_create_response(
        client=client,
        model=model,
        instructions=system_prompt,
        input_text=payload,
        reasoning_effort=reasoning_effort
        if reasoning_effort is not None
        else (os.environ.get("OPENAI_REASONING_EFFORT") or DEFAULT_REASONING_EFFORT),
        temperature=0.1,
    )
    usage = extract_usage_metrics(response)
    append_usage_record(ROOT, role, model, usage)
    print(format_usage_summary(role, model, usage), flush=True)

    raw = extract_response_text(response)
    data = parse_json_object(raw)
    errors = validate_reviewer_result(data)
    if errors:
        raise ValueError("Reviewer JSON failed validation: " + "; ".join(errors))
    return data


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="DOP Reviewer")
    parser.add_argument("--title", default="")
    parser.add_argument("--body-file", required=False)
    parser.add_argument("--diff-file", required=False)
    parser.add_argument("--files-file", required=False)
    parser.add_argument("--test-notes-file", required=False)
    parser.add_argument("--task-file", required=False, help="Architect task JSON path")
    parser.add_argument("--previous-issues-file", required=False)
    parser.add_argument("--output", default="-")
    parser.add_argument("--comment-output", default="")
    parser.add_argument(
        "--model",
        default=resolve_model("OPENAI_REVIEWER_MODEL", DEFAULT_REVIEWER_MODEL),
    )
    parser.add_argument(
        "--escalate",
        action="store_true",
        help="Use OPENAI_ESCALATION_MODEL for a second-opinion review",
    )
    parser.add_argument("--validate-only", help="Validate an existing reviewer JSON file")
    parser.add_argument(
        "--require-automation-marker",
        action="store_true",
        help="Fail if PR body lacks automation marker",
    )
    args = parser.parse_args(argv)

    if args.validate_only:
        data = parse_json_object(Path(args.validate_only).read_text(encoding="utf-8"))
        errors = validate_reviewer_result(data)
        if errors:
            print("INVALID: " + "; ".join(errors), file=sys.stderr)
            return 1
        print("VALID")
        return 0

    body = Path(args.body_file).read_text(encoding="utf-8") if args.body_file else ""
    if args.require_automation_marker and not has_automation_pr_marker(body):
        print("PR body does not contain DOP automation marker; skipping.", file=sys.stderr)
        return 2

    diff_text = Path(args.diff_file).read_text(encoding="utf-8") if args.diff_file else ""
    changed_files = Path(args.files_file).read_text(encoding="utf-8") if args.files_file else ""
    test_notes = (
        Path(args.test_notes_file).read_text(encoding="utf-8") if args.test_notes_file else ""
    )
    previous_issues = (
        Path(args.previous_issues_file).read_text(encoding="utf-8")
        if args.previous_issues_file
        else ""
    )
    task_json = None
    if args.task_file:
        task_json = parse_json_object(Path(args.task_file).read_text(encoding="utf-8"))

    if diff_is_suspiciously_large(diff_text):
        data = {
            "verdict": "HUMAN_REQUIRED",
            "summary": "Diff is too large for safe automated review.",
            "issues": [
                {
                    "severity": "high",
                    "file": "(diff)",
                    "problem": "PR diff exceeds automated size safeguards.",
                    "required_fix": "Split the work or obtain human review.",
                }
            ],
            "scope_check": "Unable to confidently assess scope for an oversized diff.",
            "architecture_check": "Deferred to human due to diff size.",
            "test_check": "Deferred to human due to diff size.",
        }
    else:
        model = args.model
        role = "reviewer"
        effort = os.environ.get("OPENAI_REASONING_EFFORT") or DEFAULT_REASONING_EFFORT
        if args.escalate:
            model = resolve_model("OPENAI_ESCALATION_MODEL", DEFAULT_ESCALATION_MODEL)
            role = "reviewer_escalation"
            effort = os.environ.get("OPENAI_ESCALATION_REASONING_EFFORT") or "medium"
        try:
            data = call_reviewer(
                model,
                build_review_payload(
                    title=args.title,
                    body=body,
                    changed_files=changed_files,
                    diff_text=diff_text,
                    test_notes=test_notes,
                    task_json=task_json,
                    previous_issues_summary=previous_issues,
                ),
                role=role,
                reasoning_effort=effort,
            )
        except Exception as exc:  # noqa: BLE001
            print(f"Reviewer failed: {exc}", file=sys.stderr)
            return 1

    payload = json.dumps(data, ensure_ascii=False, indent=2) + "\n"
    if args.output == "-":
        sys.stdout.write(payload)
    else:
        Path(args.output).write_text(payload, encoding="utf-8")

    if args.comment_output:
        Path(args.comment_output).write_text(format_review_comment(data), encoding="utf-8")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
