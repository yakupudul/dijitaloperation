# PageSpeed / Lighthouse Connection

## Purpose

Performans ve web kalite sinyallerini Evidence olarak normalize etmek.

## User value

Lab/field ayrımıyla performans sorunları severity/confidence ile Findings olur.

## Core concepts

Connection/capability feeding Website Diagnosis. Raw API response ≠ Finding.

## MVP behavior

Önemli ölçümlerin anlamı, sorun koşulu, severity/confidence, lab/field ayrımı diagnosis catalog üzerinden tanımlanır.

## Important data / attributes

Normalized metrics + catalog-linked rule ids.

## Relationships

Website → PageSpeed/Lighthouse → evidence → findings.

## Main screens / workflows

Run performance collect; attach to diagnosis.

## Rules / invariants

Diagnosis-first; no uncontrolled metric spam.

## Derived information

Pass/fail against catalog thresholds.

## Later enhancements

CrUX trends, budgets.

## Explicit non-goals

Dumping entire Lighthouse JSON as findings.

## Acceptance intent

Performance signals catalog kurallarıyla Finding üretir.
