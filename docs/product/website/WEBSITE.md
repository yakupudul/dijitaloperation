# Website Digital Asset

## Purpose

Website, ilk ve birincil Digital Asset türüdür.

## User value

Domain üzerinden teknik/on-page teşhis ve connection zenginleştirmesi tek asset'te toplanır.

## Core concepts

Website asset; WordPress/GA4/GSC/DataForSEO/PageSpeed connection'lardır.

## MVP behavior

* Brand altında Website kaydı: domain, primary URL, CMS (opsiyonel), languages, target countries, site type, optional hosting context
* Related connections and source-specific collection state
* Ekran vizyonu: Overview, Connections, Runs, Findings, Recommendations, Tasks
* İlk akış: Customer → Brand → Website → Diagnosis → Evidence → Findings → Recommendations → Task
* WordPress status derived from the authenticated read-only WordPress connection when present
* Public Discovery remains active for WordPress and non-WordPress sites; it verifies externally published HTTP/HTML

## Important data / attributes

domain, primary_url, cms, languages, target_countries, site_type, hosting_context (optional).

## Relationships

Brand → Website asset → Connections; pipeline entities.

## Main screens / workflows

Website create/detail; attach connections; start diagnosis run.

## Rules / invariants

CMS-specific fields Core'a şişirilmez; module/connection'dan gelir. Integration ekranı collection truth gösterir;
Finding/Recommendation/Task yalnızca Website Digital Asset analizinde üretilir.

## Derived information

WordPress version/theme/plugin health from connector evidence; last diagnosis from runs.

## Later enhancements

Multi-environment sites, staging vs prod.

## Explicit non-goals

WordPress write; hosting control panel automation.

## Acceptance intent

Website asset kaydı diagnosis ve connection eklemeye hazır operasyon nesnesidir.
