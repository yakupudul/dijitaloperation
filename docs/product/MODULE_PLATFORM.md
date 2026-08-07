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

## MVP behavior

* Registry enable/disable
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

ADR-033/035/037. Sample module is smoke test only. Custom plugin framework forbidden in MVP.

## Derived information

Health of modules is not a mandatory heavy framework (ADR-037).

## Later enhancements

Richer module metadata UI; future non-MVP compatibility gates if ever needed.

## Explicit non-goals

Marketplace, ZIP install, purge-on-disable, reinventing Composer/Filament discovery.

## Acceptance intent

Operator can see modules and disable one so its DOP UI/jobs stop without deleting data or uninstalling code.
