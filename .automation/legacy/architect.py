#!/usr/bin/env python3
"""DOP Architect — select the smallest next implementation task."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

AUTOMATION_DIR = Path(__file__).resolve().parents[1]  # .automation/
LEGACY_DIR = Path(__file__).resolve().parent  # .automation/legacy/
ROOT = AUTOMATION_DIR.parent  # repository root
if str(AUTOMATION_DIR) not in sys.path:
    sys.path.insert(0, str(AUTOMATION_DIR))
if str(LEGACY_DIR) not in sys.path:
    sys.path.insert(0, str(LEGACY_DIR))

from common import (  # noqa: E402
    DEFAULT_ARCHITECT_MODEL,
    DEFAULT_REASONING_EFFORT,
    append_usage_record,
    extract_response_text,
    extract_usage_metrics,
    format_usage_summary,
    is_repeated_task,
    is_safe_product_spec_path,
    extract_task_ids_from_pr_bodies,
    load_core_rules,
    load_docs_capped,
    load_prompt,
    openai_create_response,
    parse_json_object,
    recent_commit_summary,
    resolve_model,
    select_candidate_product_specs,
    summarize_repo_tree,
    validate_architect_task,
)
from recovery import (  # noqa: E402
    MAX_ARCHITECT_RETRY,
    repair_invalid_product_spec_paths,
)

PROMPTS_DIR = LEGACY_DIR / "prompts"

# Stable planning docs (NOT full MASTER_SPEC; NOT full docs/product/**).
PLANNING_DOC_PATHS = (
    "docs/IMPLEMENTATION_ROADMAP.md",
    "docs/foundation/DECISION_LOG.md",
    "AGENTS.md",
    "docs/product/INDEX.md",
)


def _merged_automation_task_ids() -> set[str]:
    import subprocess

    try:
        result = subprocess.run(
            [
                "gh",
                "pr",
                "list",
                "--state",
                "merged",
                "--base",
                "main",
                "--limit",
                "40",
                "--json",
                "body,title,headRefName",
            ],
            cwd=ROOT,
            check=False,
            capture_output=True,
            text=True,
        )
        if result.returncode != 0 or not result.stdout.strip():
            return set()
        rows = json.loads(result.stdout)
        bodies = [str(row.get("body") or "") for row in rows]
        return extract_task_ids_from_pr_bodies(bodies)
    except (OSError, json.JSONDecodeError):
        return set()


def architect_context_files(
    *,
    merged_task_ids: set[str] | None = None,
    commit_summary: str | None = None,
) -> dict[str, list[str]]:
    """Return file lists used for Architect context (for tests / metrics)."""
    commits = commit_summary if commit_summary is not None else recent_commit_summary(ROOT)
    merged = merged_task_ids if merged_task_ids is not None else set()
    candidates = select_candidate_product_specs(
        ROOT,
        merged_task_ids=merged,
        commit_summary=commits,
    )
    return {
        "stable": [".automation/context/CORE_RULES.md"],
        "planning": list(PLANNING_DOC_PATHS),
        "product_specs": candidates,
    }


def build_user_payload(
    *,
    merged_task_ids: set[str] | None = None,
    commit_summary: str | None = None,
) -> str:
    """Cache-friendly order: stable rules → planning docs → candidate specs → dynamic state."""
    commits = commit_summary if commit_summary is not None else recent_commit_summary(ROOT)
    merged = merged_task_ids if merged_task_ids is not None else _merged_automation_task_ids()
    files = architect_context_files(merged_task_ids=merged, commit_summary=commits)
    core = load_core_rules(ROOT)
    planning = load_docs_capped(ROOT, files["planning"])
    product = load_docs_capped(ROOT, files["product_specs"])
    merged_ids = sorted(merged)

    return (
        "## CORE_RULES (stable)\n"
        f"{core}\n\n"
        "## Planning documents (stable-ish)\n"
        f"{planning}\n\n"
        "## Candidate product blueprints for the next domain(s) only\n"
        "Do NOT assume other blueprints are in scope. Set product_spec_paths to the files "
        "actually required for this task (usually a subset of the candidates below).\n\n"
        f"{product if product.strip() else '(no candidate product specs resolved)'}\n\n"
        "## Compact repository file list\n"
        f"{summarize_repo_tree(ROOT)}\n\n"
        "## Recent commits\n"
        f"{commits}\n\n"
        "## Previously merged automation task_ids (do not repeat)\n"
        f"{chr(10).join(merged_ids) if merged_ids else '(none discovered)'}\n\n"
        "Return the next smallest TASK_READY JSON (or ROADMAP_COMPLETE / HUMAN_REQUIRED).\n"
        "Keep reason to a few sentences. Avoid repeating MASTER_SPEC prose.\n"
    )


def call_architect(model: str, *, reasoning_effort: str | None = None) -> dict:
    from openai import OpenAI

    api_key = os.environ.get("OPENAI_API_KEY")
    if not api_key:
        raise RuntimeError("OPENAI_API_KEY is not set")

    client = OpenAI(api_key=api_key)
    system_prompt = load_prompt(PROMPTS_DIR, "architect.md")
    response = openai_create_response(
        client=client,
        model=model,
        instructions=system_prompt,
        input_text=build_user_payload(),
        reasoning_effort=reasoning_effort
        if reasoning_effort is not None
        else (os.environ.get("OPENAI_REASONING_EFFORT") or DEFAULT_REASONING_EFFORT),
        temperature=0.2,
    )
    usage = extract_usage_metrics(response)
    append_usage_record(ROOT, "architect", model, usage)
    print(format_usage_summary("architect", model, usage), flush=True)

    raw = extract_response_text(response)
    data = parse_json_object(raw)
    if data.get("status") == "TASK_READY" and isinstance(data.get("product_spec_paths"), list):
        repaired = repair_invalid_product_spec_paths(
            [str(p) for p in data.get("product_spec_paths") or []],
            repo_root=ROOT,
            is_safe_path=is_safe_product_spec_path,
        )
        if repaired != list(data.get("product_spec_paths") or []):
            print(
                "Architect product_spec_paths repaired: "
                f"{data.get('product_spec_paths')} -> {repaired}",
                flush=True,
            )
            data["product_spec_paths"] = repaired
    errors = validate_architect_task(data, repo_root=ROOT, require_product_specs=True)
    if errors:
        raise ValueError("Architect JSON failed validation: " + "; ".join(errors))
    if data.get("status") == "TASK_READY" and is_repeated_task(
        data, merged_task_ids=_merged_automation_task_ids()
    ):
        raise ValueError(
            "Architect returned a repeated automation task_id/branch; failing closed"
        )
    return data


def call_architect_with_retries(
    model: str,
    *,
    reasoning_effort: str | None = None,
    max_attempts: int = MAX_ARCHITECT_RETRY,
) -> dict:
    """Retry Architect on validation / transient failures (no new task selection between retries)."""
    last_error: Exception | None = None
    attempts = max(1, int(max_attempts))
    for attempt in range(1, attempts + 1):
        try:
            return call_architect(model, reasoning_effort=reasoning_effort)
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            print(f"Architect attempt {attempt}/{attempts} failed: {exc}", flush=True)
            if attempt >= attempts:
                break
    assert last_error is not None
    raise last_error


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="DOP Architect")
    parser.add_argument("--output", default="-", help="Output JSON path or - for stdout")
    parser.add_argument(
        "--model",
        default=resolve_model("OPENAI_ARCHITECT_MODEL", DEFAULT_ARCHITECT_MODEL),
        help="OpenAI model id",
    )
    parser.add_argument("--validate-only", help="Validate an existing JSON file and exit")
    parser.add_argument(
        "--require-product-specs",
        action="store_true",
        help="Require non-empty product_spec_paths when validating TASK_READY",
    )
    parser.add_argument(
        "--print-context-files",
        action="store_true",
        help="Print Architect context file lists as JSON and exit (no API call)",
    )
    args = parser.parse_args(argv)

    if args.print_context_files:
        payload = json.dumps(architect_context_files(), ensure_ascii=False, indent=2) + "\n"
        sys.stdout.write(payload)
        return 0

    if args.validate_only:
        data = parse_json_object(Path(args.validate_only).read_text(encoding="utf-8"))
        errors = validate_architect_task(
            data,
            repo_root=ROOT,
            require_product_specs=args.require_product_specs,
        )
        if errors:
            print("INVALID: " + "; ".join(errors), file=sys.stderr)
            return 1
        print("VALID")
        return 0

    try:
        data = call_architect_with_retries(args.model)
    except Exception as exc:  # noqa: BLE001
        print(f"Architect failed: {exc}", file=sys.stderr)
        return 1

    payload = json.dumps(data, ensure_ascii=False, indent=2) + "\n"
    if args.output == "-":
        sys.stdout.write(payload)
    else:
        Path(args.output).write_text(payload, encoding="utf-8")
        print(f"Wrote {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
