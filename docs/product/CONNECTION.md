# Connection

## Purpose

Connection, bir Digital Asset hakkında veri okumamızı sağlayan bağlantı / kaynaktır. Digital Asset değildir.

## User value

Ekip asset'e güvenli read-only veri kaynakları bağlar; diagnosis/confidence zenginleşir; secret'lar sızmaz.

## Core concepts

**Digital Asset** = yönetilen gerçek dijital varlık (Website, GBP, Ads account, …).  
**Connection** = o asset'i incelemek için veri sağlayan kaynak.

Örnek Website connections:
* WordPress
* GA4
* Search Console
* PageSpeed / Lighthouse
* DataForSEO

Connection olmadan asset var olabilir; connection varlığı diagnosis kapsamını artırabilir.

## MVP behavior

* Connection bir Digital Asset'e bağlıdır
* type, display name, enabled/disabled veya operational status
* configuration (non-secret)
* credential presence/state (secret değeri değil)
* connection test / read health
* last successful read + last error context (derived veya hafif alan; şişirme yok)
* Harici write yok
* Credentials ayrı encrypted credential store (ADR-027)
* Secret UI/log/API response'a sızmaz
* Disabled connection veriyi otomatik silmez
* Connection silmek/disable etmek asset'i silmez
* Optional connections diagnosis confidence'ı artırabilir

## Important data / attributes

asset_id, type, name, status/enabled, config, credential_ref/state, last_success_at, last_error summary. Schema kilidi yok; implementer framework-native seçer.

## Relationships

Digital Asset → Connections → Runs/Evidence. Credentials 1:1 veya typed store (ADR-027).

## Main screens / workflows

Asset detail → Connections list; add/test/disable connection; never show raw secrets.

## Rules / invariants

* Read-only external access
* No marketplace/ZIP credential packs
* Disabled ≠ purge
* Missing connection ≠ invalid asset
* ADR-027 uyumu zorunlu

## Derived information

Health / last success / last error Run veya connection attempt kayıtlarından UI'da derive edilebilir.

## Later enhancements

Richer health widgets, scheduled verify, multi-account mapping UX.

## Explicit non-goals

External write; treating GA4/GSC as separate Digital Assets; secret logging.

## Acceptance intent

Ajans bir Website asset'ine güvenli read-only connection ekleyip test edebilir; secret sızmaz; disable veri silmez.
