"""Consistency checks for the Cursor PR-review fixer loop instructions."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SKILL = ROOT / ".cursor/skills/moxdop-pr-review-fixer/SKILL.md"
DASHBOARD = ROOT / ".cursor/skills/moxdop-pr-review-fixer/DASHBOARD_PROMPT.md"
AGENTS = ROOT / "AGENTS.md"

FORBIDDEN_EXIT_PHRASES = (
    "no-op approve",
    "noop approve",
    "no-op approval",
)


def _exit_without_changes_section(text: str) -> str:
    match = re.search(
        r"\*\*Exit without changes when:\*\*(.*?)(?:\n## |\nDo \*\*not\*\* exit)",
        text,
        flags=re.S,
    )
    if match:
        return match.group(1).lower()
    match = re.search(
        r"Exit without changes only when(.*?)(?:\n\nWhen Codex|\nWhen the review is clean)",
        text,
        flags=re.S,
    )
    return (match.group(1) if match else "").lower()


class PrReviewFixerLoopTests(unittest.TestCase):
    def test_skill_and_dashboard_prompt_exist(self) -> None:
        self.assertTrue(SKILL.is_file(), f"missing {SKILL}")
        self.assertTrue(DASHBOARD.is_file(), f"missing {DASHBOARD}")
        self.assertTrue(AGENTS.is_file(), f"missing {AGENTS}")

    def test_clean_approval_is_not_an_exit_condition(self) -> None:
        skill = SKILL.read_text(encoding="utf-8")
        dashboard = DASHBOARD.read_text(encoding="utf-8")
        agents = AGENTS.read_text(encoding="utf-8")

        for blob, name in ((skill, "SKILL.md"), (dashboard, "DASHBOARD_PROMPT.md")):
            lower = blob.lower()
            for phrase in FORBIDDEN_EXIT_PHRASES:
                self.assertNotIn(
                    phrase,
                    lower,
                    f"{name} must not treat a clean/no-comment approval as an automatic exit ({phrase!r})",
                )

        for blob, name in ((skill, "SKILL.md"), (dashboard, "DASHBOARD_PROMPT.md")):
            exit_section = _exit_without_changes_section(blob)
            self.assertNotEqual(
                exit_section.strip(),
                "",
                f"{name} is missing an explicit exit-without-changes section",
            )
            self.assertIn("wrong repository", exit_section)
            for phrase in ("approve", "no comments", "no-op", "clean review", "no actionable"):
                self.assertNotIn(
                    phrase,
                    exit_section,
                    f"{name} exit list must not include {phrase!r}; a clean Codex review is a continue event",
                )
            self.assertIn(
                "exit merely because the review is approved",
                blob.lower(),
                f"{name} must say a clean/no-comment approval is not an exit",
            )
            self.assertRegex(
                blob,
                r"(?is)Codex / OpenAI code review.{0,80}\bor\b.{0,80}actionable",
                f"{name} continue-gate must treat a Codex review as sufficient without requiring findings",
            )

        for blob, name in ((skill, "SKILL.md"), (dashboard, "DASHBOARD_PROMPT.md"), (agents, "AGENTS.md")):
            lower = blob.lower()
            self.assertIn(
                "continue",
                lower,
                f"{name} must instruct the loop to continue after a clean review",
            )
            self.assertTrue(
                "next highest-value incomplete" in lower or "next incomplete requirement" in lower,
                f"{name} must send a clean review to the next incomplete milestone requirement",
            )

        self.assertIn("clean Codex approval with no comments is a continue event", skill)
        self.assertIn("clean Codex approval with no comments is a continue event", dashboard)
        self.assertIn("must **not** terminate the loop", agents)


if __name__ == "__main__":
    unittest.main()
