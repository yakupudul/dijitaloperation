# Module Platform

## Purpose

DOP'un modular monolith ürün davranışı: modüller Composer paketleri olarak yaşar; Core yalnızca minimal enable/disable registry tutar.

## User value

Yeni yetenekler modül olarak eklenir; disable edilince UI/analysis durur ama veri kaybolmaz.

## Core concepts

* Modular monolith
* Modules = repository içi Composer packages under `app-modules/`
* `internachi/modular` + Laravel package discovery
* Minimal Module Registry: module_id + enabled/disabled (+ optional installed_version)
* Module code may remain installed while disabled
* **Module ≠ Integration ≠ Agent ≠ Skill** (see `docs/foundation/MODULE_ARCHITECTURE.md`)

## MVP behavior

* Registry enable/disable for **business capability** modules
* Operator Module Registry shows: Website, Google Ads, Google Business Profile
* `sample-module` is a developer/packaging fixture — seeded for smoke tests, **hidden** from normal operator Module Registry UI
* Provider Integrations (OpenAI, DataForSEO, …) are **not** Modules
* Disabled → DOP UI/navigation gizli/erişilemez
* Disabled → DOP-specific scheduled/analysis jobs çalışmaz
* Disabled → data silinmez; Composer package kalabilir
* No external ZIP install, marketplace, dynamic code download
* No custom compatibility engine, lifecycle FSM, custom migrator
* Framework/package discovery yeniden yazılmaz (ADR-033/035)

## Important data / attributes

module_id, enabled boolean, optional installed_version (informational).

## Relationships

Core registry ↔ module packages; modules own domain UI/jobs when enabled.

## Main screens / workflows

Modules list with enable/disable; no install-from-URL.

## Rules / invariants

ADR-033/035/037. Sample module is smoke/fixture only and must not appear as a normal operator capability. Custom plugin framework forbidden in MVP. Integrations are never listed as Modules.

## Derived information

Health of modules is not a mandatory heavy framework (ADR-037).

## Later enhancements

Richer module metadata UI; future non-MVP compatibility gates if ever needed.

## Explicit non-goals

Marketplace, ZIP install, purge-on-disable, reinventing Composer/Filament discovery.

## Acceptance intent

Operator can see modules and disable one so its DOP UI/jobs stop without deleting data or uninstalling code.
