# WordPress Connection

## Purpose

Website diagnosis'ı zenginleştiren read-only Website Connection.

## User value

CMS sürümü, eklenti/tema, içerik yapısı sinyalleri teşhise girer.

## Core concepts

WordPress = connection, asset değil. Harici write yok; minimum permission.

## MVP behavior

Capability-based (use-case first): WP version, active theme, plugins, update status, posts/pages, useful taxonomies, content dates, stale content signals, Site Health where safe, cron signals, REST state, CMS structure context.  
Sadece güvenli read-only erişilebilenler MVP'ye girer.

## Important data / attributes

Connection credentials encrypted; evidence normalized — not raw dump as Finding.

## Relationships

Website asset → WordPress connection → Runs/Evidence.

## Main screens / workflows

Attach WordPress connection; test read; include in diagnosis.

## Rules / invariants

No content publish/update/delete. No 'field exists so fetch'.

## Derived information

Update risk, stale content candidates from evidence.

## Later enhancements

Deeper plugin risk scoring.

## Explicit non-goals

Automatic WP fixes; write actions.

## Acceptance intent

Read-only connector diagnosis'a kanıt sağlar.
