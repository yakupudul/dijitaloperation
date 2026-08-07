"""Shared helpers for DOP automation (no secrets, no network)."""

from __future__ import annotations

import json
import os
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

# Product Autopilot PRs must not ship automation/infra changes unless Architect
# explicitly scopes them via files_or_areas.
PRODUCT_INFRA_PATH_PREFIXES = (
    ".github/",
    ".automation/",
)

PRODUCT_SPEC_PREFIX = "docs/product/"
SAFE_PRODUCT_SPEC_RE = re.compile(r"^docs/product/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_][A-Za-z0-9_-]*\.md$")

DEFAULT_ARCHITECT_MODEL = "gpt-5-mini"
DEFAULT_REVIEWER_MODEL = "gpt-5-mini"
DEFAULT_ESCALATION_MODEL = "gpt-5"
DEFAULT_REASONING_EFFORT = "low"
CORE_RULES_REL = ".automation/context/CORE_RULES.md"
MAX_DOC_CHARS = 60_000
MAX_DIFF_CHARS_FOR_REVIEW = 120_000

# Roadmap-ordered domain → product blueprints (INDEX / IMPLEMENTATION_ROADMAP).
# Used to load only the next relevant specs instead of all docs/product/**.
ROADMAP_DOMAIN_SPECS: tuple[tuple[str, tuple[str, ...], tuple[str, ...]], ...] = (
    ("customer", ("customer",), ("docs/product/CUSTOMER.md",)),
    ("brand", ("brand",), ("docs/product/BRAND.md",)),
    (
        "digital-asset",
        ("digital-asset", "digital_asset", "digitalasset"),
        ("docs/product/DIGITAL_ASSET.md",),
    ),
    (
        "connection",
        ("connection", "credential"),
        ("docs/product/CONNECTION.md", "docs/product/DIGITAL_ASSET.md"),
    ),
    (
        "module-registry",
        ("module-registry", "module_platform", "module-platform"),
        ("docs/product/MODULE_PLATFORM.md",),
    ),
    (
        "analysis-pipeline",
        ("analysis-pipeline", "evidence", "finding", "recommendation", "pipeline"),
        ("docs/product/ANALYSIS_PIPELINE.md",),
    ),
    (
        "website",
        ("website-module", "website_module"),
        ("docs/product/website/WEBSITE.md",),
    ),
    (
        "diagnosis",
        ("diagnosis",),
        (
            "docs/product/website/DIAGNOSIS.md",
            "docs/product/website/WEBSITE.md",
            "docs/product/ANALYSIS_PIPELINE.md",
        ),
    ),
    (
        "wordpress",
        ("wordpress",),
        ("docs/product/website/WORDPRESS.md", "docs/product/website/WEBSITE.md"),
    ),
    (
        "search-console",
        ("search-console", "search_console"),
        ("docs/product/website/SEARCH_CONSOLE.md", "docs/product/website/WEBSITE.md"),
    ),
    (
        "ga4",
        ("ga4",),
        ("docs/product/website/GA4.md", "docs/product/ANALYSIS_PIPELINE.md"),
    ),
    (
        "pagespeed",
        ("pagespeed", "lighthouse"),
        ("docs/product/website/PAGESPEED_LIGHTHOUSE.md", "docs/product/website/WEBSITE.md"),
    ),
    (
        "dataforseo",
        ("dataforseo",),
        ("docs/product/website/DATAFORSEO.md", "docs/product/website/WEBSITE.md"),
    ),
    (
        "ai-insights",
        ("ai-insights", "ai_insights"),
        ("docs/product/website/AI_INSIGHTS.md", "docs/product/website/WEBSITE.md"),
    ),
    (
        "dashboard",
        ("dashboard",),
        ("docs/product/DASHBOARD.md",),
    ),
)


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def resolve_model(env_name: str, default: str) -> str:
    return (os.environ.get(env_name) or "").strip() or default


def load_core_rules(repo_root: Path) -> str:
    path = repo_root / CORE_RULES_REL
    if not path.is_file():
        return f"(missing {CORE_RULES_REL})\n"
    return path.read_text(encoding="utf-8")


def truncate_text(text: str, max_chars: int = MAX_DOC_CHARS) -> str:
    if len(text) <= max_chars:
        return text
    return text[:max_chars] + "\n\n... truncated for token budget ...\n"


def extract_markdown_by_headings(text: str, heading_needles: list[str], max_chars: int = MAX_DOC_CHARS) -> str:
    """Return sections whose heading line contains any needle (case-insensitive)."""
    if not heading_needles:
        return truncate_text(text, max_chars)
    needles = [n.lower() for n in heading_needles if n]
    lines = text.splitlines()
    keep: list[str] = []
    capturing = False
    for line in lines:
        if line.startswith("#"):
            capturing = any(n in line.lower() for n in needles)
        if capturing:
            keep.append(line)
        if sum(len(x) + 1 for x in keep) >= max_chars:
            keep.append("... truncated ...")
            break
    return "\n".join(keep).strip() or truncate_text(text, max_chars)


def find_adr_ids(*texts: str) -> list[str]:
    found: list[str] = []
    seen: set[str] = set()
    pattern = re.compile(r"\bADR-(\d{3})\b", re.I)
    for text in texts:
        for match in pattern.finditer(text or ""):
            adr = f"ADR-{match.group(1)}"
            key = adr.upper()
            if key not in seen:
                seen.add(key)
                found.append(adr)
    return found


def load_adr_excerpts(repo_root: Path, adr_ids: list[str], max_chars: int = 20_000) -> str:
    if not adr_ids:
        return "(no task-related ADRs selected)\n"
    path = repo_root / "docs" / "foundation" / "DECISION_LOG.md"
    if not path.is_file():
        return "(DECISION_LOG.md missing)\n"
    text = path.read_text(encoding="utf-8")
    excerpts = extract_markdown_by_headings(text, adr_ids, max_chars=max_chars)
    return excerpts or "(no matching ADR sections)\n"


def _domain_mentioned(corpus: str, keywords: tuple[str, ...]) -> bool:
    return any(keyword in corpus for keyword in keywords)


def select_candidate_product_specs(
    repo_root: Path,
    *,
    merged_task_ids: set[str] | None = None,
    commit_summary: str = "",
    max_domains: int = 2,
) -> list[str]:
    """Pick only the next relevant product blueprints (not the full docs/product tree)."""
    merged_task_ids = merged_task_ids or set()
    corpus = " ".join(sorted(merged_task_ids)).lower() + "\n" + (commit_summary or "").lower()

    last_progress_idx: int | None = None
    first_open_idx: int | None = None
    for index, (_name, keywords, _specs) in enumerate(ROADMAP_DOMAIN_SPECS):
        if _domain_mentioned(corpus, keywords):
            last_progress_idx = index
        elif first_open_idx is None:
            first_open_idx = index

    chosen_indexes: list[int] = []
    if last_progress_idx is not None:
        chosen_indexes.append(last_progress_idx)
        # Include the next unfinished domain so Architect can advance when the
        # current domain's remaining work is done.
        nxt = last_progress_idx + 1
        if nxt < len(ROADMAP_DOMAIN_SPECS) and len(chosen_indexes) < max_domains:
            chosen_indexes.append(nxt)
    elif first_open_idx is not None:
        chosen_indexes.append(first_open_idx)
    else:
        chosen_indexes.append(0)

    paths: list[str] = []
    seen: set[str] = set()
    for index in chosen_indexes[:max_domains]:
        for rel in ROADMAP_DOMAIN_SPECS[index][2]:
            if rel in seen:
                continue
            if not (repo_root / rel).is_file():
                continue
            if not is_safe_product_spec_path(rel):
                continue
            seen.add(rel)
            paths.append(rel)
    return paths


def load_docs_capped(repo_root: Path, relative_paths: list[str], max_chars_each: int = MAX_DOC_CHARS) -> str:
    chunks: list[str] = []
    for rel in relative_paths:
        path = repo_root / rel
        if not path.is_file():
            chunks.append(f"### {rel}\n(missing)\n")
            continue
        text = path.read_text(encoding="utf-8")
        if rel == "AGENTS.md":
            # Keep DOP agent rules; drop huge embedded Laravel Boost guideline dump.
            for marker in ("<laravel-boost-guidelines>", "=== foundation rules ===", "=== boost rules ==="):
                if marker in text:
                    text = text.split(marker, 1)[0].rstrip() + "\n"
                    break
        if rel == "docs/foundation/DECISION_LOG.md" and len(text) > max_chars_each:
            # Prefer recent accepted ADR body over ancient superseded preamble when capped.
            text = extract_markdown_by_headings(
                text,
                [f"ADR-{n:03d}" for n in range(15, 39)] + ["Karar indeksi"],
                max_chars=max_chars_each,
            )
        chunks.append(f"### {rel}\n{truncate_text(text, max_chars_each)}\n")
    return "\n".join(chunks)


def extract_usage_metrics(response: Any) -> dict[str, Any]:
    """Pull token usage from an OpenAI Responses API object (best-effort)."""
    usage = getattr(response, "usage", None)
    if usage is None and isinstance(response, dict):
        usage = response.get("usage")
    if usage is None:
        return {
            "input_tokens": None,
            "cached_input_tokens": None,
            "output_tokens": None,
            "total_tokens": None,
        }

    def _get(obj: Any, *names: str) -> Any:
        for name in names:
            if isinstance(obj, dict) and name in obj:
                return obj[name]
            if hasattr(obj, name):
                return getattr(obj, name)
        return None

    input_tokens = _get(usage, "input_tokens", "prompt_tokens")
    output_tokens = _get(usage, "output_tokens", "completion_tokens")
    total_tokens = _get(usage, "total_tokens")
    cached = None
    details = _get(usage, "input_tokens_details", "prompt_tokens_details")
    if details is not None:
        cached = _get(details, "cached_tokens", "cached_input_tokens")
    if cached is None:
        cached = _get(usage, "cached_input_tokens", "cached_tokens")
    return {
        "input_tokens": input_tokens,
        "cached_input_tokens": cached,
        "output_tokens": output_tokens,
        "total_tokens": total_tokens,
    }


def format_usage_summary(role: str, model: str, usage: dict[str, Any], *, request_count: int = 1) -> str:
    return (
        f"- **{role}** model=`{model}` requests={request_count} "
        f"input_tokens={usage.get('input_tokens')} "
        f"cached_input_tokens={usage.get('cached_input_tokens')} "
        f"output_tokens={usage.get('output_tokens')}"
    )


def append_usage_record(repo_root: Path, role: str, model: str, usage: dict[str, Any]) -> Path:
    runtime = repo_root / ".automation" / "runtime"
    runtime.mkdir(parents=True, exist_ok=True)
    path = runtime / "usage.jsonl"
    record = {"role": role, "model": model, **usage}
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(record, ensure_ascii=False) + "\n")
    return path


def openai_create_response(
    *,
    client: Any,
    model: str,
    instructions: str,
    input_text: str,
    reasoning_effort: str | None = None,
    temperature: float | None = 0.2,
) -> Any:
    """Create a Responses API call; retry without reasoning/temperature if unsupported."""
    effort = (reasoning_effort if reasoning_effort is not None else DEFAULT_REASONING_EFFORT) or ""
    effort = effort.strip().lower()
    attempts: list[dict[str, Any]] = []
    if effort and effort not in {"none", "off", "0"}:
        payload: dict[str, Any] = {
            "model": model,
            "instructions": instructions,
            "input": input_text,
            "reasoning": {"effort": effort},
        }
        attempts.append(payload)
    base: dict[str, Any] = {
        "model": model,
        "instructions": instructions,
        "input": input_text,
    }
    if temperature is not None:
        base_with_temp = dict(base)
        base_with_temp["temperature"] = temperature
        attempts.append(base_with_temp)
    attempts.append(base)

    last_error: Exception | None = None
    for kwargs in attempts:
        try:
            return client.responses.create(**kwargs)
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            continue
    assert last_error is not None
    raise last_error


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


def find_product_infra_paths(paths: list[str]) -> list[str]:
    """Paths that product Autopilot PRs must not change by default."""
    hits: list[str] = []
    for path in paths:
        normalized = path.replace("\\", "/")
        while normalized.startswith("./"):
            normalized = normalized[2:]
        for prefix in PRODUCT_INFRA_PATH_PREFIXES:
            root = prefix.rstrip("/")
            if normalized == root or normalized.startswith(prefix):
                hits.append(path)
                break
    return hits


def task_allows_infra_paths(task: dict[str, Any] | None, paths: list[str]) -> bool:
    """True when Architect files_or_areas explicitly scopes every infra path."""
    if not task or not paths:
        return False

    def _norm(value: str) -> str:
        normalized = value.replace("\\", "/")
        while normalized.startswith("./"):
            normalized = normalized[2:]
        return normalized

    areas = [_norm(str(item)) for item in (task.get("files_or_areas") or []) if str(item).strip()]
    explicit_infra_areas = [
        area
        for area in areas
        if any(area == p.rstrip("/") or area.startswith(p) for p in PRODUCT_INFRA_PATH_PREFIXES)
    ]
    if not explicit_infra_areas:
        return False
    for path in paths:
        normalized = _norm(path)
        if not any(
            normalized == area.rstrip("/")
            or normalized.startswith(area if area.endswith("/") else area + "/")
            for area in explicit_infra_areas
        ):
            return False
    return True


def assert_product_branch_diff_safe(
    paths: list[str],
    *,
    task: dict[str, Any] | None = None,
) -> list[str]:
    """Return errors if product branch touches automation/workflow infra."""
    infra = find_product_infra_paths(paths)
    if not infra:
        return []
    if task_allows_infra_paths(task, infra):
        return []
    return [
        "product branch must not change automation/infra paths: " + ", ".join(infra)
    ]


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
        "__pycache__",
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

# Obvious placeholders / UI field wiring — not literal leaked secrets.
_CREDENTIAL_FALSE_POSITIVE = re.compile(
    r"(?i)(::make\(|fillForm\(|assertFormSet\(|->state\(|encrypted:array|"
    r"passwordConfirmation|current_password|type\(Password::class\)|"
    r"['\"]password['\"]\s*=>\s*['\"]password['\"]|"
    r"['\"]secret['\"]\s*=>\s*['\"]secret['\"]|"
    r"['\"]token['\"]\s*=>\s*['\"]token['\"]|"
    r"example\.com|changeme|placeholder|your[_-]?api[_-]?key)"
)


def _credential_scan_lines(diff_text: str) -> list[str]:
    """Prefer added lines from unified diffs; otherwise scan full text."""
    text = diff_text or ""
    if "\ndiff --git " in text or text.startswith("diff --git "):
        lines: list[str] = []
        for line in text.splitlines():
            if line.startswith("+") and not line.startswith("+++"):
                lines.append(line[1:])
        return lines
    return text.splitlines()


def scan_diff_for_credential_leaks(diff_text: str) -> list[str]:
    hits: list[str] = []
    for line in _credential_scan_lines(diff_text):
        if _CREDENTIAL_FALSE_POSITIVE.search(line):
            continue
        for pattern in CREDENTIAL_DIFF_PATTERNS:
            if pattern.search(line):
                hits.append(pattern.pattern)
                break
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
    review_evidence: dict[str, Any] | None = None,
    current_head_sha: str | None = None,
    require_review_approval: bool = True,
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
    if require_review_approval:
        errors.extend(
            validate_review_evidence(
                review_evidence,
                current_head_sha=current_head_sha or "",
                expected_task_id=str(task.get("task_id") or "") or None,
            )
        )
    return errors


def build_review_evidence(
    *,
    task_id: str,
    reviewed_head_sha: str,
    verdict: str,
    model: str,
    run_id: str = "",
    reviewer_role: str = "reviewer",
    reviewed_at: str | None = None,
) -> dict[str, Any]:
    from datetime import datetime, timezone

    return {
        "task_id": task_id,
        "reviewed_head_sha": (reviewed_head_sha or "").strip().lower(),
        "verdict": verdict,
        "model": model,
        "reviewed_at": reviewed_at or datetime.now(timezone.utc).isoformat(),
        "run_id": run_id or os.environ.get("GITHUB_RUN_ID", ""),
        "reviewer_role": reviewer_role,
    }


def validate_review_evidence(
    evidence: dict[str, Any] | None,
    *,
    current_head_sha: str,
    expected_task_id: str | None = None,
) -> list[str]:
    """Fail-closed checks for Autopilot merge. Tests alone never satisfy this."""
    errors: list[str] = []
    if evidence is None:
        errors.append("review evidence missing")
        return errors
    if not isinstance(evidence, dict):
        errors.append("review evidence must be an object")
        return errors

    for key in (
        "task_id",
        "reviewed_head_sha",
        "verdict",
        "model",
        "reviewed_at",
        "run_id",
    ):
        value = evidence.get(key)
        if not isinstance(value, str) or not value.strip():
            errors.append(f"review evidence missing field: {key}")

    verdict = evidence.get("verdict")
    if verdict != "APPROVED":
        errors.append(f"reviewer verdict is not APPROVED ({verdict!r})")

    reviewed = str(evidence.get("reviewed_head_sha") or "").strip().lower()
    current = (current_head_sha or "").strip().lower()
    if not current:
        errors.append("current HEAD SHA missing for review evidence check")
    elif reviewed and current and reviewed != current:
        errors.append(
            "reviewer approved SHA does not match current HEAD; fresh review required "
            f"(approved={reviewed}, head={current})"
        )

    if expected_task_id:
        evidence_task = str(evidence.get("task_id") or "").strip()
        if evidence_task and evidence_task != expected_task_id:
            errors.append(
                f"review evidence task_id mismatch (evidence={evidence_task}, task={expected_task_id})"
            )

    return errors


def load_review_evidence(path: Path) -> dict[str, Any] | None:
    if not path.is_file():
        return None
    try:
        data = parse_json_object(path.read_text(encoding="utf-8"))
    except (OSError, ValueError, json.JSONDecodeError):
        return None
    return data if isinstance(data, dict) else None


def write_review_evidence(path: Path, evidence: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


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
