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
    diff_is_suspiciously_large,
    extract_response_text,
    has_automation_pr_marker,
    load_prompt,
    parse_json_object,
    review_marker_for_verdict,
    validate_reviewer_result,
)

PROMPTS_DIR = AUTOMATION_DIR / "prompts"
DEFAULT_MODEL = "gpt-4.1"

DOC_PATHS = (
    "docs/MASTER_SPEC.md",
    "docs/IMPLEMENTATION_ROADMAP.md",
    "docs/foundation/DECISION_LOG.md",
    "AGENTS.md",
)


def _read_docs() -> str:
    chunks: list[str] = []
    for rel in DOC_PATHS:
        path = ROOT / rel
        if not path.exists():
            chunks.append(f"### {rel}\n(missing)\n")
            continue
        chunks.append(f"### {rel}\n{path.read_text(encoding='utf-8')}\n")
    return "\n".join(chunks)


def build_review_payload(
    *,
    title: str,
    body: str,
    changed_files: str,
    diff_text: str,
    test_notes: str,
) -> str:
    truncated_diff = diff_text
    note = ""
    if diff_is_suspiciously_large(diff_text):
        note = (
            "\n\nWARNING: Diff is suspiciously large. "
            "Prefer HUMAN_REQUIRED unless the task clearly justifies it.\n"
        )
        truncated_diff = diff_text[:200_000] + "\n\n... diff truncated for reviewer context ...\n"

    return (
        "Review this DOP automation PR and return JSON only.\n\n"
        f"Automation marker required in body: {AUTOMATION_PR_MARKER}\n"
        f"Marker present: {has_automation_pr_marker(body)}\n\n"
        f"## PR title\n{title}\n\n"
        f"## PR body\n{body}\n\n"
        "## Project documents\n"
        f"{_read_docs()}\n\n"
        f"## Changed files\n{changed_files}\n\n"
        f"## Test notes\n{test_notes or '(none provided)'}\n"
        f"{note}\n"
        f"## Git diff\n```diff\n{truncated_diff}\n```\n"
    )


def format_review_comment(data: dict) -> str:
    marker = review_marker_for_verdict(str(data["verdict"]))
    lines = [
        marker,
        "",
        f"## DOP automated review: {data['verdict']}",
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


def call_reviewer(model: str, payload: str) -> dict:
    from openai import OpenAI

    api_key = os.environ.get("OPENAI_API_KEY")
    if not api_key:
        raise RuntimeError("OPENAI_API_KEY is not set")

    client = OpenAI(api_key=api_key)
    system_prompt = load_prompt(PROMPTS_DIR, "reviewer.md")
    response = client.responses.create(
        model=model,
        instructions=system_prompt,
        input=payload,
        temperature=0.1,
    )
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
    parser.add_argument("--output", default="-")
    parser.add_argument("--comment-output", default="")
    parser.add_argument(
        "--model",
        default=os.environ.get("OPENAI_REVIEWER_MODEL", DEFAULT_MODEL),
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
        try:
            data = call_reviewer(
                args.model,
                build_review_payload(
                    title=args.title,
                    body=body,
                    changed_files=changed_files,
                    diff_text=diff_text,
                    test_notes=test_notes,
                ),
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
