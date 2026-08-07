# IMPLEMENTATION_ROADMAP

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Bu belge uygulama sırasını tanımlar. Ürün kapsamını genişletmez.  
> Bu PR’da uygulama kodu yazılmaz.

## Faz özeti

| Sıra | Faz | Hedef |
|------|-----|--------|
| 0 | Core application | Laravel + tek Filament `app` paneli, auth/RBAC, Customer/Brand/Asset/Connection/credential, analysis modelleri |
| 1 | Module platform | `app-modules/` + `internachi/modular`, registry, enable/disable, SDK sözleşmeleri |
| 2 | Sample module | SDK doğrulama modülü |
| 3 | Website asset | Website digital asset + connection bağlama |
| 3.5 | Diagnosis catalog | `docs/website/DIAGNOSIS_CATALOG.md` (Faz 4 kapısı) |
| 4 | Website diagnosis | Run → Evidence → Finding → Recommendation; manuel Task |
| 5 | Website connectors | Read-only connector’lar |
| 6 | AI insights | `laravel/ai`; Evidence/Finding üzerinde yorum |
| 7 | Sonraki digital asset’ler | GBP, Ads, Instagram, … |

---

## Faz 0 — Core application

**Çıktılar**

* Laravel 13 + PHP 8.3+ iskeleti
* Tek Filament panel: id `app`, path `/app`
* `web` session guard; public registration yok
* Admin kullanıcı oluşturur; password reset + profile
* Roller: Admin, Team Member (`spatie/laravel-permission`)
* Customers, contacts, Brands, Digital assets
* `core_connections` + `core_connection_credentials` (encrypted_payload; ham secret Livewire’a expose edilmez)
* Evidence / Finding / Recommendation / Task minimal alanları (ADR-028 / ADR-029)
* Notes, Attachments, Tags, Audit logs, Application settings, Health checks
* MySQL 8, database queue, Laravel scheduler

**Kabul kriterleri**

* Müşteri girişi / tenant / ikinci panel yok
* Harici write API yok
* Credential ham değeri Filament form state’inde tutulmaz

**Bağımlılık:** Yok

---

## Faz 1 — Module platform

**Çıktılar**

* `app-modules/` kökü + `internachi/modular`
* Module registry + enable/disable
* `module.manifest.json`
* Navigation extension points (Filament)
* Module-scoped migrations / providers
* Events / Jobs kayıt yüzeyi
* Run history + Error logs

**Kabul kriterleri**

* `docs/module-sdk` uyumu
* ZIP/marketplace yok
* Disable edilen modül sistemi düşürmez

---

## Faz 2 — Sample module

**Çıktılar**

* `app-modules/sample-module` (veya eşdeğer)
* Brand sekmesi, permission, setting, job, event, tablo, health
* Pest + `MODULE_TEST_CHECKLIST`

---

## Faz 3 — Website asset

**Çıktılar**

* Website digital asset tipi
* Domain / temel site bilgileri
* Connection bağlama UI (secret’sız config + ayrı credential akışı)

---

## Faz 3.5 — Diagnosis catalog (Faz 4 kapısı)

**Çıktı (dokümantasyon):** `docs/website/DIAGNOSIS_CATALOG.md`

Her teşhis contract’ı:

* Diagnosis id, Category, Purpose  
* Required / Optional evidence  
* Detection / Severity / Confidence rules  
* Finding output, Recommendation logic  
* Data/source dependency  

Kaynak: güvenilir açık kaynak audit/crawl araçları ve resmi web standartları — tek tek tahmin değil.

**Not:** Bu katalog Core (Faz 0) blocker değildir; **Website Diagnosis fazı öncesi zorunludur.**

---

## Faz 4 — Website diagnosis

**Önkoşul:** Faz 3.5 katalog mevcut.

**Çıktılar**

* Diagnosis run (background job)
* Evidence / Finding / Recommendation (connector’suz temel seviye mümkün)
* Kullanıcı Recommendation → Task manuel dönüşüm (snapshot; assignee/due uydurma yok)

---

## Faz 5 — Website connectors

Sıra (read-only): WordPress → Search Console → GA4 → PageSpeed/Lighthouse → DataForSEO  

Credential’lar `core_connection_credentials.encrypted_payload` içinde.

---

## Faz 6 — AI insights

* `laravel/ai` SDK  
* Provider/model env/config ile değiştirilebilir (ilk test: OpenAI olabilir)  
* API key panelden değil, environment’tan  
* Girdi: Evidence + Finding; uydurma yok  
* MCP / vector DB / multi-agent yok  

---

## Faz 7 — Sonraki digital asset modülleri

GBP → Google Ads → Meta Ads → Instagram → diğerleri. Write action yok.

---

## Fazlar arası kurallar

1. Üst faz kabul edilmeden alt faz tamam sayılmaz.  
2. SaaS / Client Portal / marketplace roadmap’e girmez.  
3. Redis/Horizon yalnızca ölçülen ihtiyaç + ADR ile.  
4. Teknoloji sapması yeni ADR gerektirir.

## Bloker durumu

| Konu | Durum |
|------|--------|
| Panel/auth, RBAC | Kararlı (ADR-026) — Core bloker yok |
| Connection/credential | Kararlı (ADR-027) — Core bloker yok |
| Analysis model alanları | Kararlı (ADR-028) — Core bloker yok |
| Recommendation→Task | Kararlı (ADR-029) — Core bloker yok |
| AI SDK/key | Kararlı (ADR-030) — Core bloker yok |
| Modül dizini | Kararlı (ADR-032) — Core bloker yok |
| Diagnosis catalog | Faz 4 öncesi zorunlu; **Core’u bloke etmez** (ADR-031) |
