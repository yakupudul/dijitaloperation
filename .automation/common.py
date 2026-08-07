"""Shared helpers for DOP automation (no secrets, no network)."""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

AUTOMATION_PR_MARKER = "<!-- DOP_AUTOMATION_PR -->"
REVIEW_APPROVED_MARKER = "<!-- DOP_REVIEW:APPROVED -->"
REVIEW_FIX_REQUIRED_MARKER = "<!-- DOP_REVIEW:FIX_REQUIRED -->"
REVIEW_HUMAN_REQUIRED_MARKER = "<!-- DOP_REVIEW:HUMAN_REQUIRED -->"
FIX_COMMIT_MESSAGE = "fix: address automated DOP review"
MAX_FIX_ATTEMPTS = 3

ARCHITECT_STATUSES = {"TASK_READY", "ROADMAP_COMPLETE", "HUMAN_REQUIRED"}
REVIEW_VERDICTS = {"APPROVED", "FIX_REQUIRED", "HUMAN_REQUIRED"}
ISSUE_SEVERITIES = {"critical", "high", "medium", "low"}

SAFE_BRANCH_RE = re.compile(r"^[a-z0-9](?:[a-z0-9._/-]{0,78}[a-z0-9])?$")
UNSAFE_BRANCH_PARTS = ("..", "//", ".git", "\\")

SECRET_PATH_PATTERNS = (
    re.compile(r"(^|/)\.env(\.|$)"),
    re.compile(r"(^|/)id_rsa(\.|$)"),
    re.compile(r"(^|/)id_ed25519(\.|$)"),
    re.compile(r"\.pem$", re.I),
    re.compile(r"\.p12$", re.I),
    re.compile(r"\.pfx$", re.I),
    re.compile(r"(^|/)credentials(\.|$)"),
    re.compile(r"(^|/)secrets?\.(json|ya?ml|txt)$", re.I),
    re.compile(r"(^|/)\.npmrc$"),
    re.compile(r"(^|/)auth\.json$"),
)

EXCLUDED_NEXT_TASK_BRANCH_PREFIXES = (
    "chore/",
    "automation/",
    "docs/",
)

PRODUCT_SPEC_PREFIX = "docs/product/"
SAFE_PRODUCT_SPEC_RE = re.compile(r"^docs/product/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_][A-Za-z0-9_-]*\.md$")


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def load_prompt(prompts_dir: Path, name: str) -> str:
    return read_text(prompts_dir / name)


def parse_json_object(raw: str) -> dict[str, Any]:
    text = raw.strip()
    if text.startswith("```"):
        lines = text.splitlines()
        if lines and lines[0].startswith("```"):
            lines = lines[1:]
        if lines and lines[-1].strip() == "```":
            lines = lines[:-1]
        text = "\n".join(lines).strip()

    data = json.loads(text)
    if not isinstance(data, dict):
        raise ValueError("JSON root must be an object")
    return data


def is_safe_branch_name(name: str) -> bool:
    if not name or not isinstance(name, str):
        return False
    if name in {".", "..", "main", "master", "HEAD"}:
        return False
    if any(part in name for part in UNSAFE_BRANCH_PARTS):
        return False
    if name.startswith("/") or name.startswith("-") or name.endswith("/"):
        return False
    return bool(SAFE_BRANCH_RE.fullmatch(name))


def has_marker(text: str | None, marker: str) -> bool:
    if not text:
        return False
    return marker in text


def has_automation_pr_marker(body: str | None) -> bool:
    return has_marker(body, AUTOMATION_PR_MARKER)


def review_marker_for_verdict(verdict: str) -> str:
    mapping = {
        "APPROVED": REVIEW_APPROVED_MARKER,
        "FIX_REQUIRED": REVIEW_FIX_REQUIRED_MARKER,
        "HUMAN_REQUIRED": REVIEW_HUMAN_REQUIRED_MARKER,
    }
    try:
        return mapping[verdict]
    except KeyError as exc:
        raise ValueError(f"Unknown verdict: {verdict}") from exc


def count_fix_attempts_from_commit_messages(messages: list[str]) -> int:
    count = 0
    for message in messages:
        first_line = (message or "").strip().splitlines()[0] if message else ""
        if first_line == FIX_COMMIT_MESSAGE:
            count += 1
    return count


def remaining_fix_attempts(messages: list[str], maximum: int = MAX_FIX_ATTEMPTS) -> int:
    used = count_fix_attempts_from_commit_messages(messages)
    return max(0, maximum - used)


def should_skip_next_task_for_merged_pr(
    branch_name: str,
    pr_title: str = "",
    changed_files: list[str] | None = None,
) -> bool:
    """Return True when a merged PR must not start a new product implementation."""
    branch = (branch_name or "").strip().lower()
    title = (pr_title or "").strip().lower()
    files = changed_files or []

    if any(branch.startswith(prefix) for prefix in EXCLUDED_NEXT_TASK_BRANCH_PREFIXES):
        return True

    if title.startswith("chore:") or title.startswith("docs:") or title.startswith("ci:"):
        return True

    if files and all(_is_docs_or_meta_path(path) for path in files):
        return True

    return False


def _is_docs_or_meta_path(path: str) -> bool:
    normalized = path.replace("\\", "/")
    return (
        normalized.startswith("docs/")
        or normalized.startswith(".automation/")
        or normalized.startswith(".github/")
        or normalized in {"AGENTS.md", "README.md", "boost.json"}
        or normalized.startswith(".ai/")
    )


def find_secret_like_paths(paths: list[str]) -> list[str]:
    hits: list[str] = []
    for path in paths:
        normalized = path.replace("\\", "/")
        if any(pattern.search(normalized) for pattern in SECRET_PATH_PATTERNS):
            hits.append(path)
    return hits


def is_safe_product_spec_path(path: str) -> bool:
    if not path or not isinstance(path, str):
        return False
    normalized = path.replace("\\", "/").strip()
    if normalized.startswith("/") or normalized.startswith("~"):
        return False
    parts = normalized.split("/")
    if any(part in {"", ".", ".."} for part in parts):
        return False
    if ".." in normalized:
        return False
    if not normalized.startswith(PRODUCT_SPEC_PREFIX):
        return False
    if not normalized.endswith(".md"):
        return False
    return bool(SAFE_PRODUCT_SPEC_RE.fullmatch(normalized))


def validate_product_spec_paths(
    paths: Any,
    *,
    repo_root: Path | None = None,
    require_non_empty: bool = False,
) -> list[str]:
    errors: list[str] = []
    if not isinstance(paths, list) or not all(isinstance(item, str) for item in paths):
        return ["product_spec_paths must be a list of strings"]

    if require_non_empty and len(paths) < 1:
        errors.append("product_spec_paths must not be empty for this product task")

    seen: set[str] = set()
    for path in paths:
        if not is_safe_product_spec_path(path):
            errors.append(f"unsafe or invalid product_spec_path: {path}")
            continue
        if path in seen:
            errors.append(f"duplicate product_spec_path: {path}")
            continue
        seen.add(path)
        if repo_root is not None:
            candidate = (repo_root / path).resolve()
            root = repo_root.resolve()
            if root not in candidate.parents and candidate != root:
                errors.append(f"product_spec_path escapes repository: {path}")
            elif not candidate.is_file():
                errors.append(f"product_spec_path does not exist: {path}")
    return errors


def list_product_spec_files(repo_root: Path) -> list[str]:
    base = repo_root / "docs" / "product"
    if not base.exists():
        return []
    files: list[str] = []
    for path in sorted(base.rglob("*.md")):
        rel = path.relative_to(repo_root).as_posix()
        if is_safe_product_spec_path(rel):
            files.append(rel)
    return files


def load_product_specs(repo_root: Path, paths: list[str]) -> str:
    chunks: list[str] = []
    for rel in paths:
        errors = validate_product_spec_paths([rel], repo_root=repo_root)
        if errors:
            raise ValueError("; ".join(errors))
        text = (repo_root / rel).read_text(encoding="utf-8")
        chunks.append(f"### {rel}\n{text}\n")
    return "\n".join(chunks)


def validate_architect_task(
    data: dict[str, Any],
    *,
    repo_root: Path | None = None,
    require_product_specs: bool = False,
) -> list[str]:
    errors: list[str] = []
    status = data.get("status")
    if status not in ARCHITECT_STATUSES:
        errors.append("status must be TASK_READY | ROADMAP_COMPLETE | HUMAN_REQUIRED")

    if status == "TASK_READY":
        for key in (
            "task_id",
            "title",
            "branch_name",
            "objective",
            "instructions",
            "acceptance_criteria",
            "files_or_areas",
            "must_not_do",
            "tests_required",
            "product_spec_paths",
            "reason",
        ):
            if key not in data:
                errors.append(f"missing field: {key}")

        if "branch_name" in data and not is_safe_branch_name(str(data["branch_name"])):
            errors.append("branch_name is not a safe slug")

        for list_key in (
            "acceptance_criteria",
            "files_or_areas",
            "must_not_do",
            "tests_required",
            "product_spec_paths",
        ):
            value = data.get(list_key)
            if value is not None and (
                not isinstance(value, list) or not all(isinstance(item, str) for item in value)
            ):
                errors.append(f"{list_key} must be a list of strings")

        criteria = data.get("acceptance_criteria")
        if isinstance(criteria, list) and len(criteria) < 1:
            errors.append("acceptance_criteria must not be empty for TASK_READY")

        if "product_spec_paths" in data:
            errors.extend(
                validate_product_spec_paths(
                    data.get("product_spec_paths"),
                    repo_root=repo_root,
                    require_non_empty=True if require_product_specs or status == "TASK_READY" else False,
                )
            )

    if status in {"ROADMAP_COMPLETE", "HUMAN_REQUIRED"} and not data.get("reason"):
        errors.append("reason is required")

    return errors


def validate_reviewer_result(data: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    verdict = data.get("verdict")
    if verdict not in REVIEW_VERDICTS:
        errors.append("verdict must be APPROVED | FIX_REQUIRED | HUMAN_REQUIRED")

    for key in ("summary", "scope_check", "architecture_check", "test_check"):
        if not isinstance(data.get(key), str) or not data.get(key):
            errors.append(f"{key} must be a non-empty string")

    issues = data.get("issues")
    if not isinstance(issues, list):
        errors.append("issues must be a list")
    else:
        for index, issue in enumerate(issues):
            if not isinstance(issue, dict):
                errors.append(f"issues[{index}] must be an object")
                continue
            if issue.get("severity") not in ISSUE_SEVERITIES:
                errors.append(f"issues[{index}].severity is invalid")
            for field in ("file", "problem", "required_fix"):
                if not isinstance(issue.get(field), str):
                    errors.append(f"issues[{index}].{field} must be a string")

    if verdict == "FIX_REQUIRED" and isinstance(issues, list) and len(issues) < 1:
        errors.append("FIX_REQUIRED requires at least one issue")

    return errors


def summarize_repo_tree(root: Path, max_entries: int = 220) -> str:
    """Compact tree of meaningful project paths (not a full dump)."""
    ignore_dirs = {
        ".git",
        "vendor",
        "node_modules",
        "storage",
        "bootstrap/cache",
        "public/css",
        "public/js",
        "public/fonts",
        ".phpunit.cache",
    }
    allow_prefixes = (
        "app/",
        "app-modules/",
        "config/",
        "database/",
        "docs/",
        "resources/",
        "routes/",
        "tests/",
        ".automation/",
        ".github/",
    )
    allow_files = {
        "AGENTS.md",
        "README.md",
        "composer.json",
        "package.json",
        "phpunit.xml",
        "artisan",
        "boost.json",
        ".env.example",
    }

    entries: list[str] = []
    for path in sorted(root.rglob("*")):
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if any(rel == d or rel.startswith(d + "/") for d in ignore_dirs):
            continue
        if rel in allow_files or any(rel.startswith(prefix) for prefix in allow_prefixes):
            entries.append(rel)
        if len(entries) >= max_entries:
            entries.append("... truncated ...")
            break
    return "\n".join(entries)


def recent_commit_summary(root: Path, limit: int = 12) -> str:
    import subprocess

    try:
        result = subprocess.run(
            ["git", "log", f"-{limit}", "--pretty=format:%h %s"],
            cwd=root,
            check=True,
            capture_output=True,
            text=True,
        )
        return result.stdout.strip() or "(no commits)"
    except (OSError, subprocess.CalledProcessError) as exc:
        return f"(unable to read git log: {exc})"


def extract_response_text(response: Any) -> str:
    text = getattr(response, "output_text", None)
    if isinstance(text, str) and text.strip():
        return text

    output = getattr(response, "output", None) or []
    chunks: list[str] = []
    for item in output:
        contents = getattr(item, "content", None) or []
        for content in contents:
            value = getattr(content, "text", None)
            if isinstance(value, str):
                chunks.append(value)
    if chunks:
        return "\n".join(chunks)
    raise ValueError("OpenAI response did not contain text output")


def diff_is_suspiciously_large(diff_text: str, max_chars: int = 250_000, max_files: int = 80) -> bool:
    if len(diff_text) > max_chars:
        return True
    file_headers = [line for line in diff_text.splitlines() if line.startswith("diff --git ")]
    return len(file_headers) > max_files


HARD_BLOCKER_ISSUE_MARKER = "<!-- DOP_HARD_BLOCKER -->"

CREDENTIAL_DIFF_PATTERNS = (
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----"),
    re.compile(r"AKIA[0-9A-Z]{16}"),
    re.compile(r"(?i)(api[_-]?key|secret|password|token)\s*[:=]\s*['\"][^'\"]{12,}['\"]"),
)


def scan_diff_for_credential_leaks(diff_text: str) -> list[str]:
    hits: list[str] = []
    for pattern in CREDENTIAL_DIFF_PATTERNS:
        if pattern.search(diff_text or ""):
            hits.append(pattern.pattern)
    return hits


def extract_task_ids_from_pr_bodies(bodies: list[str]) -> set[str]:
    found: set[str] = set()
    pattern = re.compile(r"\*\*task_id:\*\*\s*`([^`]+)`")
    alt = re.compile(r'"task_id"\s*:\s*"([^"]+)"')
    for body in bodies:
        if not body:
            continue
        found.update(pattern.findall(body))
        found.update(alt.findall(body))
    return found


def is_repeated_task(
    task: dict[str, Any],
    *,
    merged_task_ids: set[str] | None = None,
    recent_branch_names: set[str] | None = None,
) -> bool:
    task_id = str(task.get("task_id") or "").strip()
    branch = str(task.get("branch_name") or "").strip()
    merged_task_ids = merged_task_ids or set()
    recent_branch_names = recent_branch_names or set()
    if task_id and task_id in merged_task_ids:
        return True
    if branch and branch in recent_branch_names:
        return True
    return False


def should_create_hard_blocker_issue(status: str | None, reason: str | None = None) -> bool:
    return status == "HUMAN_REQUIRED" and bool((reason or "").strip())


def format_hard_blocker_issue_body(*, reason: str, task: dict[str, Any] | None = None) -> str:
    payload = json.dumps(task or {}, ensure_ascii=False, indent=2)
    return (
        f"{HARD_BLOCKER_ISSUE_MARKER}\n\n"
        f"## DOP hard blocker\n\n"
        f"{reason.strip()}\n\n"
        f"<details><summary>Task context</summary>\n\n```json\n{payload}\n```\n</details>\n"
    )


def final_merge_gate_errors(
    *,
    branch_name: str,
    pr_body: str,
    task: dict[str, Any],
    secret_paths: list[str],
    suspicious_diff: bool,
    tests_passed: bool,
    mergeable: bool,
    repo_root: Path | None = None,
) -> list[str]:
    errors: list[str] = []
    if branch_name in {"main", "master", "HEAD"} or not is_safe_branch_name(branch_name):
        errors.append("branch is not a safe non-main automation branch")
    if not has_automation_pr_marker(pr_body):
        errors.append("PR body missing automation marker")
    errors.extend(validate_architect_task(task, repo_root=repo_root, require_product_specs=True))
    if secret_paths:
        errors.append("secret-like paths present: " + ", ".join(secret_paths))
    if suspicious_diff:
        errors.append("diff is suspiciously large")
    if not tests_passed:
        errors.append("required tests/gates have not passed")
    if not mergeable:
        errors.append("PR is not mergeable")
    return errors


def dispatch_eligible(
    *,
    merged: bool,
    automation_pr: bool,
    verdict_approved: bool,
    hard_blocker: bool,
    roadmap_complete: bool,
) -> bool:
    return bool(
        merged
        and automation_pr
        and verdict_approved
        and not hard_blocker
        and not roadmap_complete
    )
