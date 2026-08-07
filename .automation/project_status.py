"""Deterministic DOP Autopilot project progress status (no OpenAI calls)."""

from __future__ import annotations

import json
import re
import subprocess
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

from recovery import (
    COMPLETED_AND_CONTINUING,
    HARD_BLOCKED,
    RECOVERING,
    ROADMAP_COMPLETE,
    RUN_SUMMARY_STATUSES,
)

# Project-level overall status (human-facing PROJECT_STATUS.md).
RUNNING = "RUNNING"

PROJECT_OVERALL_STATUSES = (
    RUNNING,
    RECOVERING,
    HARD_BLOCKED,
    ROADMAP_COMPLETE,
)

PROJECT_STATUS_REL = "docs/PROJECT_STATUS.md"
ROADMAP_REL = "docs/IMPLEMENTATION_ROADMAP.md"
STATUS_COMMIT_PREFIX = "chore(status):"
STATUS_COMMIT_MESSAGE = "chore(status): update DOP project status"

# Parsed from IMPLEMENTATION_ROADMAP.md; kept as fallback if parse fails.
FALLBACK_STAGES: tuple[tuple[int, str], ...] = (
    (1, "Laravel / Filament bootstrap"),
    (2, "Auth + users / roles / permissions"),
    (3, "Customer"),
    (4, "Brand"),
    (5, "Digital Asset"),
    (6, "Connection + encrypted credentials"),
    (7, "Minimal Module Registry"),
    (8, "Run / Evidence / Finding / Recommendation / Task"),
    (9, "Website module"),
    (10, "Website Diagnosis Catalog"),
    (11, "Website Diagnosis implementation"),
    (12, "WordPress Connector"),
    (13, "Search Console Connector"),
    (14, "GA4 Connector"),
    (15, "PageSpeed / Lighthouse Connector"),
    (16, "DataForSEO Connector"),
    (17, "Website AI Insights"),
    (18, "Google Business Profile product spec + first module"),
    (19, "Google Ads product spec + first module"),
    (20, "Meta Ads product spec + first module"),
    (21, "Instagram product spec + first module"),
    (22, "Cross-asset / cross-channel analysis"),
    (23, "Action-oriented agency operations dashboard / first production hardening"),
)

TOTAL_STAGES = 23

_ROADMAP_ROW_RE = re.compile(
    r"^\|\s*(\d+)\s*\|\s*(.+?)\s*\|\s*(.*?)\s*\|\s*$",
    re.MULTILINE,
)


@dataclass
class StageInfo:
    number: int
    name: str
    status: str  # completed | in_progress | remaining


@dataclass
class RecentTask:
    task_id: str
    pr_number: str = ""
    merge_sha: str = ""
    completed_at: str = ""
    title: str = ""
    branch: str = ""


@dataclass
class StalePr:
    number: str
    title: str
    branch: str
    task_id: str
    reason: str = "Superseded / stale"


@dataclass
class ProjectStatusSnapshot:
    overall_status: str
    run_outcome: str
    last_updated: str
    current_stage_number: int | None
    current_stage_name: str
    current_task_id: str
    current_task_title: str
    current_branch: str
    current_pr: str
    reviewer_verdict: str
    retry_recovery_state: str
    automation_run_id: str
    automation_run_url: str
    completed_stage_numbers: list[int] = field(default_factory=list)
    in_progress_stage_numbers: list[int] = field(default_factory=list)
    remaining_stage_numbers: list[int] = field(default_factory=list)
    stages: list[StageInfo] = field(default_factory=list)
    recently_completed: list[RecentTask] = field(default_factory=list)
    blockers: list[dict[str, str]] = field(default_factory=list)
    next_expected: str = ""
    stale_prs: list[StalePr] = field(default_factory=list)
    repo: str = ""

    def completed_count(self) -> int:
        return len(self.completed_stage_numbers)


def parse_roadmap_stages(roadmap_text: str) -> list[tuple[int, str]]:
    """Parse canonical stage list from IMPLEMENTATION_ROADMAP.md table."""
    stages: list[tuple[int, str]] = []
    for match in _ROADMAP_ROW_RE.finditer(roadmap_text or ""):
        number = int(match.group(1))
        name = match.group(2).strip()
        if number < 1 or number > TOTAL_STAGES:
            continue
        # Strip trailing parenthetical notes like "(tamamlandı: ...)"
        name = re.sub(r"\s*\(.*\)\s*$", "", name).strip()
        stages.append((number, name))
    if len(stages) >= TOTAL_STAGES:
        return stages[:TOTAL_STAGES]
    # Fallback complete list if table incomplete
    by_num = {n: name for n, name in stages}
    return [(n, by_num.get(n, name)) for n, name in FALLBACK_STAGES]


def overall_status_from_run_outcome(run_outcome: str, *, roadmap_complete: bool = False) -> str:
    """Map run outcome → PROJECT_STATUS overall field."""
    if roadmap_complete or run_outcome == ROADMAP_COMPLETE:
        return ROADMAP_COMPLETE
    if run_outcome == HARD_BLOCKED:
        return HARD_BLOCKED
    if run_outcome == RECOVERING:
        return RECOVERING
    # COMPLETED_AND_CONTINUING and unknown → project still RUNNING
    return RUNNING


def is_status_only_commit_message(message: str) -> bool:
    first = (message or "").strip().splitlines()[0] if message else ""
    return first.startswith(STATUS_COMMIT_PREFIX) or first.startswith("chore(status)")


def should_ignore_status_commit_for_product_progress(
    *,
    branch_name: str = "",
    pr_title: str = "",
    changed_files: list[str] | None = None,
    commit_message: str = "",
) -> bool:
    """Status-only maintenance must not count as product progress / next-task."""
    if is_status_only_commit_message(commit_message) or is_status_only_commit_message(pr_title):
        return True
    title = (pr_title or "").strip().lower()
    if title.startswith("chore(status)"):
        return True
    files = changed_files or []
    if files and all(
        f.replace("\\", "/") in {PROJECT_STATUS_REL, "README.md"}
        or f.replace("\\", "/").startswith("docs/PROJECT_STATUS")
        for f in files
    ):
        return True
    branch = (branch_name or "").strip().lower()
    if "project-progress-status" in branch or branch.startswith("chore/status"):
        return True
    return False


def _exists(root: Path, rel: str) -> bool:
    return (root / rel).exists()


def _any_exists(root: Path, rels: list[str]) -> bool:
    return any(_exists(root, rel) for rel in rels)


def _merged_corpus(merged_task_ids: set[str], commit_summary: str) -> str:
    return " ".join(sorted(merged_task_ids)).lower() + "\n" + (commit_summary or "").lower()


def stage_completion_evidence(
    root: Path,
    *,
    merged_task_ids: set[str] | None = None,
    commit_summary: str = "",
) -> dict[int, str]:
    """Return stage_number → completed|in_progress|remaining using file + merge evidence.

    Never uses workflow run numbers.
    """
    merged_task_ids = merged_task_ids or set()
    corpus = _merged_corpus(merged_task_ids, commit_summary)

    def mentioned(*keys: str) -> bool:
        return any(k.lower() in corpus for k in keys)

    evidence: dict[int, tuple[bool, bool]] = {}
    # (strong_complete, partial)

    bootstrap_ok = _exists(root, "composer.json") and _any_exists(
        root,
        [
            "app/Providers/Filament/AppPanelProvider.php",
            "app/Providers/Filament/AdminPanelProvider.php",
        ],
    )
    evidence[1] = (bootstrap_ok, bootstrap_ok or _exists(root, "composer.json"))
    evidence[2] = (
        _exists(root, "app/Models/User.php")
        and (
            _exists(root, "config/permission.php")
            or (
                _exists(root, "composer.json")
                and "spatie/laravel-permission"
                in (root / "composer.json").read_text(encoding="utf-8")
            )
        ),
        _exists(root, "app/Models/User.php"),
    )
    evidence[3] = (
        _exists(root, "app/Models/Customer.php")
        and _any_exists(
            root,
            [
                "app/Filament/App/Resources/Customers/CustomerResource.php",
                "app/Filament/Resources/CustomerResource.php",
            ],
        ),
        _exists(root, "app/Models/Customer.php") or mentioned("customer"),
    )
    evidence[4] = (
        _exists(root, "app/Models/Brand.php")
        and (
            _any_exists(
                root,
                [
                    "app/Filament/App/Resources/Customers/Resources/Brands/BrandResource.php",
                ],
            )
            or mentioned("brand-crud", "brand-filament", "brand-resource")
            or any("brand" in tid and "filament" in tid for tid in merged_task_ids)
            or any("brand" in tid and "crud" in tid for tid in {t.lower() for t in merged_task_ids})
        ),
        _exists(root, "app/Models/Brand.php") or mentioned("brand"),
    )
    # Brand Filament may be nested; also accept RelationManagers under Customer
    if not evidence[4][0] and _exists(root, "app/Models/Brand.php"):
        brand_ui = list((root / "app/Filament").rglob("*Brand*")) if (root / "app/Filament").exists() else []
        evidence[4] = (bool(brand_ui), True)

    evidence[5] = (
        _exists(root, "app/Models/DigitalAsset.php")
        and bool(list((root / "app/Filament").rglob("*DigitalAsset*")) if (root / "app/Filament").exists() else []),
        _exists(root, "app/Models/DigitalAsset.php") or mentioned("digital-asset", "digital_asset"),
    )
    evidence[6] = (
        _exists(root, "app/Models/CoreConnection.php")
        and _exists(root, "app/Models/CoreConnectionCredential.php"),
        _exists(root, "app/Models/CoreConnection.php") or mentioned("connection", "credential"),
    )
    evidence[7] = (
        _exists(root, "app/Models/ModuleRegistry.php")
        and bool(list((root / "app/Filament").rglob("*Module*") if (root / "app/Filament").exists() else [])),
        _exists(root, "app/Models/ModuleRegistry.php") or mentioned("module-registry", "module_registry"),
    )
    pipeline_models = all(
        _exists(root, p)
        for p in (
            "app/Models/Run.php",
            "app/Models/Finding.php",
            "app/Models/Recommendation.php",
            "app/Models/Task.php",
            "app/Models/Evidence.php",
        )
    )
    pipeline_ui = bool(
        _exists(root, "app/Filament/App/Resources/Runs/RunResource.php")
        and _exists(root, "app/Filament/App/Resources/Findings/FindingResource.php")
    )
    evidence[8] = (
        pipeline_models and pipeline_ui,
        pipeline_models or mentioned("finding", "recommendation", "run-foundation", "analysis-pipeline", "task-foundation"),
    )
    evidence[9] = (
        _exists(root, "app-modules/website/src/Providers/WebsiteServiceProvider.php"),
        _exists(root, "app-modules/website") or mentioned("website-module", "website_module"),
    )
    evidence[10] = (
        _exists(root, "docs/website/DIAGNOSIS_CATALOG.md"),
        mentioned("diagnosis_catalog", "diagnosis-catalog") or _exists(root, "docs/website/DIAGNOSIS_CATALOG.md"),
    )
    # Diagnosis implementation is multi-task; first service/job => in_progress only.
    # Mark completed once a later connector stage has started (WordPress+) or an
    # explicit diagnosis-complete signal appears in merged task ids.
    diag_impl = _exists(root, "app/Services/WebsiteDiagnosisService.php") or _exists(
        root, "app/Jobs/DiagnoseWebsiteJob.php"
    )
    diag_done = mentioned(
        "wordpress-connector",
        "wordpress_connector",
        "diagnosis-complete",
        "website-diagnosis-complete",
    )
    evidence[11] = (diag_impl and diag_done, diag_impl or mentioned("website-diagnosis", "diagnosis-reachability", "ssl"))

    # Connectors 12–17: only implementation under app/ or app-modules/ counts.
    for num, _key, patterns in (
        (12, "wordpress", ("*WordPress*", "*Wordpress*")),
        (13, "search-console", ("*SearchConsole*", "*Search_Console*")),
        (14, "ga4", ("*Ga4*", "*GA4*")),
        (15, "pagespeed", ("*PageSpeed*", "*Lighthouse*", "*Pagespeed*")),
        (16, "dataforseo", ("*DataForSeo*", "*Dataforseo*")),
        (17, "ai-insights", ("*AiInsight*", "*AIInsight*", "*AiInsights*")),
    ):
        impl = False
        for base in (root / "app", root / "app-modules"):
            if not base.exists():
                continue
            for pat in patterns:
                if list(base.rglob(pat)):
                    impl = True
                    break
            if impl:
                break
        evidence[num] = (impl, impl)

    # Future digital assets (18–21): never partial from shared stubs alone.
    for num in (18, 19, 20, 21):
        evidence[num] = (False, False)

    dashboard_impl = bool(list((root / "app").rglob("*Dashboard*"))) if (root / "app").exists() else False
    evidence[22] = (False, False)  # requires explicit cross-asset work beyond blueprints
    evidence[23] = (dashboard_impl and mentioned("hardening", "production-hardening"), dashboard_impl)

    # Enforce sequential completion: a stage cannot be completed if a prior stage is incomplete.
    result: dict[int, str] = {}
    blocked = False
    for num in range(1, TOTAL_STAGES + 1):
        complete, partial = evidence.get(num, (False, False))
        if blocked:
            result[num] = "remaining"
            continue
        if complete:
            result[num] = "completed"
        elif partial:
            result[num] = "in_progress"
            blocked = True  # later stages cannot be completed yet
        else:
            result[num] = "remaining"
            blocked = True
    return result


def build_snapshot(
    root: Path,
    *,
    overall_status: str | None = None,
    run_outcome: str = COMPLETED_AND_CONTINUING,
    merged_task_ids: set[str] | None = None,
    commit_summary: str = "",
    recently_completed: list[RecentTask] | None = None,
    current_task: dict[str, Any] | None = None,
    current_branch: str = "",
    current_pr: str = "",
    reviewer_verdict: str = "",
    retry_recovery_state: str = "",
    automation_run_id: str = "",
    automation_run_url: str = "",
    blockers: list[dict[str, str]] | None = None,
    stale_prs: list[StalePr] | None = None,
    repo: str = "",
    now: datetime | None = None,
) -> ProjectStatusSnapshot:
    roadmap_path = root / ROADMAP_REL
    roadmap_text = roadmap_path.read_text(encoding="utf-8") if roadmap_path.is_file() else ""
    stages_meta = parse_roadmap_stages(roadmap_text)
    evidence = stage_completion_evidence(
        root,
        merged_task_ids=merged_task_ids,
        commit_summary=commit_summary,
    )

    stages: list[StageInfo] = []
    completed: list[int] = []
    in_progress: list[int] = []
    remaining: list[int] = []
    for number, name in stages_meta:
        status = evidence.get(number, "remaining")
        stages.append(StageInfo(number=number, name=name, status=status))
        if status == "completed":
            completed.append(number)
        elif status == "in_progress":
            in_progress.append(number)
        else:
            remaining.append(number)

    current_num: int | None = None
    current_name = "None"
    if in_progress:
        current_num = in_progress[0]
    elif remaining:
        current_num = remaining[0]
    if current_num is not None:
        current_name = next(s.name for s in stages if s.number == current_num)

    roadmap_complete = len(completed) == TOTAL_STAGES and not in_progress and not remaining
    outcome = run_outcome if run_outcome in RUN_SUMMARY_STATUSES else COMPLETED_AND_CONTINUING
    if roadmap_complete:
        outcome = ROADMAP_COMPLETE
    overall = overall_status or overall_status_from_run_outcome(outcome, roadmap_complete=roadmap_complete)
    if overall not in PROJECT_OVERALL_STATUSES:
        overall = RUNNING

    task = current_task or {}
    task_id = str(task.get("task_id") or "")
    task_title = str(task.get("title") or "")
    if overall == ROADMAP_COMPLETE:
        task_id = ""
        task_title = ""
        current_num = None
        current_name = "None"

    next_expected = "None"
    if overall != ROADMAP_COMPLETE and current_num is not None:
        next_expected = f"{current_num}. {current_name}"
        if task_title:
            next_expected += f" — expected focus: {task_title}"
        elif task_id:
            next_expected += f" — expected focus: `{task_id}`"

    ts = (now or datetime.now(timezone.utc)).strftime("%Y-%m-%dT%H:%M:%SZ")

    return ProjectStatusSnapshot(
        overall_status=overall,
        run_outcome=outcome,
        last_updated=ts,
        current_stage_number=current_num,
        current_stage_name=current_name,
        current_task_id=task_id,
        current_task_title=task_title,
        current_branch=current_branch or str(task.get("branch_name") or ""),
        current_pr=current_pr,
        reviewer_verdict=reviewer_verdict,
        retry_recovery_state=retry_recovery_state,
        automation_run_id=str(automation_run_id or ""),
        automation_run_url=str(automation_run_url or ""),
        completed_stage_numbers=completed,
        in_progress_stage_numbers=in_progress,
        remaining_stage_numbers=remaining,
        stages=stages,
        recently_completed=recently_completed or [],
        blockers=blockers or [],
        next_expected=next_expected,
        stale_prs=stale_prs or [],
        repo=repo,
    )


def render_project_status_markdown(snapshot: ProjectStatusSnapshot) -> str:
    lines: list[str] = []
    lines.append(
        "This file is generated/maintained by DOP Autopilot and represents "
        "implementation progress, not product requirements."
    )
    lines.append("")
    lines.append("# DOP Project Status")
    lines.append("")
    lines.append(f"Last updated: {snapshot.last_updated}")
    lines.append("")
    lines.append("Overall status:")
    lines.append(snapshot.overall_status)
    lines.append("")
    if snapshot.current_stage_number is None:
        lines.append("Current roadmap stage: — / 23")
    else:
        lines.append(f"Current roadmap stage: {snapshot.current_stage_number} / 23")
    lines.append("")
    lines.append(f"Current stage: {snapshot.current_stage_name}")
    lines.append("")
    lines.append(f"Current task: {snapshot.current_task_id or 'None'}")
    lines.append("")
    lines.append("Current task title:")
    lines.append("")
    lines.append(snapshot.current_task_title or "None")
    lines.append("")
    lines.append("Current automation run:")
    if snapshot.automation_run_url:
        lines.append(snapshot.automation_run_url)
    elif snapshot.automation_run_id:
        lines.append(snapshot.automation_run_id)
    else:
        lines.append("None")
    lines.append("")
    lines.append("## Progress")
    lines.append("")
    lines.append(f"* Completed stages: {snapshot.completed_count()} / 23")
    in_prog = ", ".join(str(n) for n in snapshot.in_progress_stage_numbers) or "—"
    rem = ", ".join(str(n) for n in snapshot.remaining_stage_numbers) or "—"
    lines.append(f"* In progress stages: {in_prog}")
    lines.append(f"* Remaining stages: {rem}")
    lines.append("")
    lines.append("## Roadmap")
    lines.append("")
    for stage in snapshot.stages:
        if stage.status == "completed":
            mark = "[x]"
        elif stage.status == "in_progress":
            mark = "[~]"
        else:
            mark = "[ ]"
        suffix = " — in progress" if stage.status == "in_progress" else ""
        lines.append(f"* {mark} {stage.number}. {stage.name}{suffix}")
    lines.append("")
    lines.append("## Current activity")
    lines.append("")
    lines.append("Last active task:")
    lines.append("")
    lines.append(f"* task id: `{snapshot.current_task_id or 'None'}`")
    lines.append(f"* branch: `{snapshot.current_branch or 'None'}`")
    lines.append(f"* PR: {snapshot.current_pr or 'None'}")
    lines.append(f"* reviewer verdict: {snapshot.reviewer_verdict or 'None'}")
    lines.append(f"* retry/recovery state: {snapshot.retry_recovery_state or snapshot.run_outcome}")
    lines.append("")
    lines.append("## Recently completed")
    lines.append("")
    if not snapshot.recently_completed:
        lines.append("None yet.")
    else:
        for item in snapshot.recently_completed[:10]:
            lines.append(
                f"* `{item.task_id}` — PR {item.pr_number or '—'} — "
                f"`{item.merge_sha or '—'}` — {item.completed_at or '—'}"
            )
    lines.append("")
    lines.append("## Blockers")
    lines.append("")
    if not snapshot.blockers:
        lines.append("None")
    else:
        for blocker in snapshot.blockers:
            lines.append(
                f"* {blocker.get('issue_link') or 'issue n/a'} — "
                f"{blocker.get('classification') or 'HARD_BLOCKED'} — "
                f"{blocker.get('reason') or ''}"
            )
    lines.append("")
    if snapshot.stale_prs:
        lines.append("## Stale automation PRs")
        lines.append("")
        for stale in snapshot.stale_prs:
            lines.append(
                f"* #{stale.number} `{stale.task_id or '—'}` — {stale.reason} "
                f"(branch `{stale.branch}`)"
            )
        lines.append("")
    lines.append("## Next expected")
    lines.append("")
    lines.append(snapshot.next_expected or "None")
    lines.append("")
    return "\n".join(lines)


def render_actions_summary(snapshot: ProjectStatusSnapshot) -> str:
    lines = [
        "# DOP Autopilot Status",
        "",
        f"Overall: **{snapshot.overall_status}**",
        f"Run outcome: `{snapshot.run_outcome}`",
        "",
        f"Progress: **{snapshot.completed_count()} / 23** stages completed",
        "",
        "Current:",
        f"{snapshot.current_stage_number or '—'}. {snapshot.current_stage_name}",
        "",
        "Task:",
        snapshot.current_task_id or "None",
        "",
        "PR:",
        snapshot.current_pr or "None",
        "",
        "Reviewer:",
        snapshot.reviewer_verdict or "None",
        "",
        "Next:",
        snapshot.next_expected or "None",
        "",
    ]
    if snapshot.overall_status == ROADMAP_COMPLETE:
        lines.append("🎉 DOP canonical roadmap complete")
        lines.append("")
    if snapshot.overall_status == HARD_BLOCKED:
        lines.append("⛔ HARD_BLOCKED — see Blockers in `docs/PROJECT_STATUS.md`")
        lines.append("")
    if snapshot.overall_status == RECOVERING:
        lines.append("♻️ RECOVERING — dop-recover-task in progress")
        lines.append("")
    return "\n".join(lines)


def write_project_status_file(root: Path, snapshot: ProjectStatusSnapshot) -> Path:
    path = root / PROJECT_STATUS_REL
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(render_project_status_markdown(snapshot), encoding="utf-8")
    return path


def extract_recent_automation_tasks_from_gh_json(
    rows: list[dict[str, Any]],
    *,
    limit: int = 10,
) -> list[RecentTask]:
    """Build recently completed list from `gh pr list --json` rows (merged)."""
    from common import AUTOMATION_PR_MARKER, extract_task_ids_from_pr_bodies

    recent: list[RecentTask] = []
    for row in rows:
        body = str(row.get("body") or "")
        if AUTOMATION_PR_MARKER not in body and "task_id" not in body:
            continue
        ids = extract_task_ids_from_pr_bodies([body])
        if not ids:
            continue
        task_id = sorted(ids)[0]
        merge = row.get("mergeCommit") or {}
        sha = ""
        if isinstance(merge, dict):
            sha = str(merge.get("oid") or "")
        recent.append(
            RecentTask(
                task_id=task_id,
                pr_number=str(row.get("number") or ""),
                merge_sha=sha[:12] if sha else "",
                completed_at=str(row.get("mergedAt") or "")[:19],
                title=str(row.get("title") or ""),
                branch=str(row.get("headRefName") or ""),
            )
        )
        if len(recent) >= limit:
            break
    return recent


def find_stale_automation_prs(
    open_prs: list[dict[str, Any]],
    *,
    merged_task_ids: set[str],
    completed_stage_numbers: list[int] | None = None,
) -> list[StalePr]:
    from common import AUTOMATION_PR_MARKER, extract_task_ids_from_pr_bodies

    completed = set(completed_stage_numbers or [])
    stale: list[StalePr] = []
    seen: set[str] = set()
    for row in open_prs:
        body = str(row.get("body") or "")
        if AUTOMATION_PR_MARKER not in body:
            continue
        ids = extract_task_ids_from_pr_bodies([body])
        overlap = ids & merged_task_ids
        branch = str(row.get("headRefName") or "")
        title = str(row.get("title") or "")
        number = str(row.get("number") or "")
        reason = ""
        task_id = sorted(overlap)[0] if overlap else (sorted(ids)[0] if ids else "")
        if overlap:
            reason = "Superseded / stale"
        else:
            blob = f"{branch} {title} {task_id}".lower()
            # Semantic supersession when the roadmap stage is already complete.
            if 8 in completed and any(
                k in blob for k in ("pipeline-run", "run-foundation", "run foundation")
            ):
                reason = "Superseded / stale"
            elif 10 in completed and "diagnosis-catalog" in blob.replace("_", "-"):
                reason = "Superseded / stale"
        if not reason:
            continue
        if number in seen:
            continue
        seen.add(number)
        stale.append(
            StalePr(
                number=number,
                title=title,
                branch=branch,
                task_id=task_id,
                reason=reason,
            )
        )
    return stale


def collect_merged_task_ids_via_gh(repo_root: Path) -> set[str]:
    from common import extract_task_ids_from_pr_bodies

    try:
        out = subprocess.check_output(
            [
                "gh",
                "pr",
                "list",
                "--state",
                "merged",
                "--base",
                "main",
                "--limit",
                "80",
                "--json",
                "body,title,headRefName",
            ],
            cwd=repo_root,
            text=True,
        )
        rows = json.loads(out)
        return extract_task_ids_from_pr_bodies([str(r.get("body") or "") for r in rows])
    except (OSError, subprocess.CalledProcessError, json.JSONDecodeError):
        return set()


def collect_recent_and_stale_via_gh(repo_root: Path) -> tuple[list[RecentTask], list[StalePr], set[str]]:
    from common import extract_task_ids_from_pr_bodies

    merged_ids: set[str] = set()
    recent: list[RecentTask] = []
    stale: list[StalePr] = []
    try:
        out = subprocess.check_output(
            [
                "gh",
                "pr",
                "list",
                "--state",
                "merged",
                "--base",
                "main",
                "--limit",
                "80",
                "--json",
                "number,title,body,headRefName,mergedAt,mergeCommit",
            ],
            cwd=repo_root,
            text=True,
        )
        rows = json.loads(out)
        merged_ids = extract_task_ids_from_pr_bodies([str(r.get("body") or "") for r in rows])
        recent = extract_recent_automation_tasks_from_gh_json(rows, limit=10)
    except (OSError, subprocess.CalledProcessError, json.JSONDecodeError):
        pass
    try:
        out = subprocess.check_output(
            [
                "gh",
                "pr",
                "list",
                "--state",
                "open",
                "--base",
                "main",
                "--limit",
                "40",
                "--json",
                "number,title,body,headRefName",
            ],
            cwd=repo_root,
            text=True,
        )
        open_rows = json.loads(out)
        # provisional evidence for semantic stale detection
        evidence = stage_completion_evidence(
            repo_root,
            merged_task_ids=merged_ids,
            commit_summary=recent_commit_summary_safe(repo_root),
        )
        completed_nums = [n for n, s in evidence.items() if s == "completed"]
        stale = find_stale_automation_prs(
            open_rows,
            merged_task_ids=merged_ids,
            completed_stage_numbers=completed_nums,
        )
    except (OSError, subprocess.CalledProcessError, json.JSONDecodeError):
        pass
    return recent, stale, merged_ids


def recent_commit_summary_safe(repo_root: Path) -> str:
    try:
        from common import recent_commit_summary

        return recent_commit_summary(repo_root)
    except Exception:
        return ""


def close_stale_prs_best_effort(repo_root: Path, stale: list[StalePr]) -> list[str]:
    """Close superseded automation PRs when permissions allow. Never raises."""
    closed: list[str] = []
    for item in stale:
        if not item.number:
            continue
        try:
            result = subprocess.run(
                [
                    "gh",
                    "pr",
                    "close",
                    str(item.number),
                    "--comment",
                    f"Superseded / stale — task `{item.task_id}` already merged canonically.",
                ],
                cwd=repo_root,
                check=False,
                capture_output=True,
                text=True,
            )
            if result.returncode == 0:
                closed.append(str(item.number))
        except OSError:
            continue
    return closed


def commit_project_status_to_main(
    repo_root: Path,
    *,
    content: str | None = None,
    dry_run: bool = False,
) -> bool:
    """Commit+push only docs/PROJECT_STATUS.md on main. Does not dispatch next-task.

    Returns True if a commit was created (or would be in dry_run with changes).
    Never dispatches dop-next-task.
    """
    status_path = repo_root / PROJECT_STATUS_REL
    body = content
    if body is None:
        if not status_path.is_file():
            return False
        body = status_path.read_text(encoding="utf-8")

    try:
        subprocess.run(["git", "fetch", "origin", "main"], cwd=repo_root, check=False, capture_output=True)
        subprocess.run(
            ["git", "checkout", "--force", "-B", "main", "origin/main"],
            cwd=repo_root,
            check=False,
            capture_output=True,
        )
    except OSError:
        return False

    status_path.parent.mkdir(parents=True, exist_ok=True)
    status_path.write_text(body, encoding="utf-8")
    subprocess.run(["git", "add", "--", PROJECT_STATUS_REL], cwd=repo_root, check=False)
    staged = subprocess.check_output(
        ["git", "diff", "--cached", "--name-only"],
        cwd=repo_root,
        text=True,
    ).strip()
    if staged != PROJECT_STATUS_REL:
        subprocess.run(["git", "reset", "-q"], cwd=repo_root, check=False)
        return False

    unchanged = subprocess.run(["git", "diff", "--cached", "--quiet"], cwd=repo_root, check=False)
    if unchanged.returncode == 0:
        return False

    if dry_run:
        subprocess.run(["git", "reset", "-q"], cwd=repo_root, check=False)
        return True

    subprocess.run(["git", "config", "user.name", "dop-automation"], cwd=repo_root, check=False)
    subprocess.run(
        ["git", "config", "user.email", "dop-automation@users.noreply.github.com"],
        cwd=repo_root,
        check=False,
    )
    commit = subprocess.run(
        ["git", "commit", "-m", STATUS_COMMIT_MESSAGE],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=False,
    )
    if commit.returncode != 0:
        return False
    push = subprocess.run(
        ["git", "push", "origin", "HEAD:main"],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=False,
    )
    return push.returncode == 0


def snapshot_to_dict(snapshot: ProjectStatusSnapshot) -> dict[str, Any]:
    data = asdict(snapshot)
    return data
