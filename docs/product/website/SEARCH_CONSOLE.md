# Search Console Connection

## Purpose

Website görünürlük kanıtı üreten Website Connection.

## User value

CTR/position/query-page fırsat ve düşüşleri Findings'e döner.

## Core concepts

Dimensions/capabilities: clicks, impressions, CTR, avg position, query, page, country, device.

## MVP behavior

Raw dashboard değil. Use-cases: visibility decline, quick wins, high impressions/low CTR, query-page relations, cannibalization signals, country/device diffs, indexing evidence where supported. Kanıtsız kesin hüküm yok.

## Important data / attributes

Normalized evidence rows + finding fingerprints.

## Relationships

Website → GSC connection → evidence/findings.

## Main screens / workflows

Connect property; collect; review findings.

## Rules / invariants

Read-only. No Search Console write.

## Derived information

Opportunity scores from thresholds in catalog/rules.

## Later enhancements

Richer cannibalization models.

## Explicit non-goals

Full GSC UI clone.

## Acceptance intent

GSC verisi Evidence/Finding üretir; BI ekranı değildir.
