#!/usr/bin/env python3
"""DOP Architect — select the smallest next implementation task."""

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
    extract_response_text,
    list_product_spec_files,
    load_prompt,
    parse_json_object,
    recent_commit_summary,
    summarize_repo_tree,
    validate_architect_task,
)

PROMPTS_DIR = AUTOMATION_DIR / "prompts"
DEFAULT_MODEL = "gpt-4.1"

DOC_PATHS = (
    "docs/MASTER_SPEC.md",
    "docs/IMPLEMENTATION_ROADMAP.md",
    "docs/foundation/DECISION_LOG.md",
    "AGENTS.md",
    "docs/product/INDEX.md",
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


def _read_all_product_blueprints() -> str:
    paths = list_product_spec_files(ROOT)
    chunks: list[str] = [f"## Product blueprint catalog ({len(paths)} files)\n"]
    for rel in paths:
        text = (ROOT / rel).read_text(encoding="utf-8")
        chunks.append(f"### {rel}\n{text}\n")
    return "\n".join(chunks)


def build_user_payload() -> str:
    return (
        "Analyze the current DOP repository state and return the next task JSON.\n\n"
        "## Project documents\n"
        f"{_read_docs()}\n\n"
        f"{_read_all_product_blueprints()}\n\n"
        "## Compact repository file list\n"
        f"{summarize_repo_tree(ROOT)}\n\n"
        "## Recent commits\n"
        f"{recent_commit_summary(ROOT)}\n"
    )


def call_architect(model: str) -> dict:
    from openai import OpenAI

    api_key = os.environ.get("OPENAI_API_KEY")
    if not api_key:
        raise RuntimeError("OPENAI_API_KEY is not set")

    client = OpenAI(api_key=api_key)
    system_prompt = load_prompt(PROMPTS_DIR, "architect.md")
    response = client.responses.create(
        model=model,
        instructions=system_prompt,
        input=build_user_payload(),
        temperature=0.2,
    )
    raw = extract_response_text(response)
    data = parse_json_object(raw)
    errors = validate_architect_task(data, repo_root=ROOT)
    if errors:
        raise ValueError("Architect JSON failed validation: " + "; ".join(errors))
    return data


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="DOP Architect")
    parser.add_argument("--output", default="-", help="Output JSON path or - for stdout")
    parser.add_argument(
        "--model",
        default=os.environ.get("OPENAI_ARCHITECT_MODEL", DEFAULT_MODEL),
        help="OpenAI model id",
    )
    parser.add_argument("--validate-only", help="Validate an existing JSON file and exit")
    parser.add_argument(
        "--require-product-specs",
        action="store_true",
        help="Require non-empty product_spec_paths when validating TASK_READY",
    )
    args = parser.parse_args(argv)

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
        data = call_architect(args.model)
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
