# ARCHITECTURE

> **HISTORICAL SNAPSHOT**  
> This document reflects an earlier project state and is **NOT** the canonical current product truth.  
> For current truth consult: `docs/MASTER_SPEC.md`, accepted ADRs, `PROJECT_MEMORY.md`, `PRODUCT_CAPABILITY_LEDGER.md`, and `docs/PROJECT_STATUS.md` where current.


> İnceleme tarihi: 2026-08-06  
> Sonuç: Mimari katmanlar depoda henüz mevcut değil.

## Genel durum

Depoda frontend, backend, veritabanı, API veya auth uygulaması bulunmuyor. Aşağıdaki bölümler bu gerçeği alan alan doğrular.

## Frontend yapısı

**Mevcut değil.**

- UI framework / kütüphane dosyası yok
- Sayfa, component, route tanımı yok
- Statik asset veya stil dosyası yok

## Backend yapısı

**Mevcut değil.**

- Sunucu / API uygulama kodu yok
- Modül, service, controller katmanı yok
- Monorepo veya çoklu paket yapısı yok

## Veritabanı yapısı

**Mevcut değil.**

- Schema, migration, seed dosyası yok
- ORM model tanımı yok
- Veritabanı bağlantı yapılandırması yok

Ayrıntılar için `DATABASE.md` dosyasına bakın.

## API yapısı

**Mevcut değil.**

- REST / GraphQL / RPC endpoint tanımı yok
- OpenAPI / Swagger dosyası yok
- Route handler veya controller yok

## Authentication ve authorization

**Mevcut değil.**

- Auth provider entegrasyonu yok
- Session / JWT / OAuth kodu yok
- Rol / izin modeli yok

## Background job veya queue

**Mevcut değil.**

- Queue worker, cron job tanımı, job processor dosyası yok
- Redis / RabbitMQ / SQS vb. yapılandırma yok

## Harici servisler

**Mevcut değil / doğrulanamadı.**

Kod veya yapılandırma olmadığı için harici servis bağlantısı tespit edilemedi. README’deki “AI-powered” ifadesi bir niyet bildirimi olarak okunabilir; hangi AI sağlayıcısının kullanılacağı **doğrulanamadı**.

## Dağıtım / altyapı mimarisi

**Mevcut değil.**

- Infrastructure-as-code yok
- Container tanımı yok
- Hosting / deploy script’i yok

## Sonuç

Mimari henüz “yeşil alan” (greenfield) aşamasındadır. Ürün vizyonu README’de kısaca geçmektedir; teknik mimari kararları depoda somutlaşmamıştır.
