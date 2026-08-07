# Website AI Insights

## Purpose

Normalize Evidence + Findings (+ relevant history) üzerinden yorum ve eylem taslağı üretmek.

## User value

Ajans kanıtı anlaşılır öncelikli öneriye çevirir.

## Core concepts

AI yardımcı katmandır; kanıtın yerine geçmez.  
Pipeline: Evidence + Findings + history → interpretation.  
Stack: `laravel/ai` (ADR-030). MVP: no MCP, no vector DB, no multi-agent product orchestration.

## MVP behavior

AI may: summarize, relate findings, explain likely causes grounded in evidence, business impact, suggest priority, draft clear work, draft Recommendation/Task text.  
AI must not: external write, claim without evidence, invent data, auto-stop campaigns, edit WordPress, Ads write. Assignee/due date uydurma.

## Important data / attributes

Model/provider via env/config; prompt inputs = normalized evidence ids/finding ids.

## Relationships

Findings/Evidence → AI → Recommendation drafts → human Task.

## Main screens / workflows

Request AI insight on finding set; accept/edit recommendation; create task manually.

## Rules / invariants

Deterministic layer first when possible. MASTER_SPEC AI limits hold.

## Derived information

Priority suggestions are advisory.

## Later enhancements

Domain skills packs as future evolution (instruction/capability packages).

## Explicit non-goals

Autonomous remediation; MCP/vector/multi-agent MVP.

## Acceptance intent

AI only explains/acts on evidence; never writes externally.
