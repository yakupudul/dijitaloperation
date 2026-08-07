# IMPLEMENTATION_ROADMAP

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Bu belge uygulama sırasını tanımlar. Ürün kapsamını genişletmez.  
> Bu PR’da uygulama kodu yazılmaz.

## Faz özeti

| Sıra | Faz | Hedef |
|------|-----|--------|
| 0 | Core application | Laravel + Filament iskeleti, auth, Admin/Team Member, Customer/Brand/Asset/Connection çekirdeği |
| 1 | Module platform | Manifest, registry, enable/disable, migrations, extension points, jobs, events |
| 2 | Sample module | SDK’yı doğrulayan minimal yerel modül |
| 3 | Website asset | Website digital asset kaydı ve paneli |
| 4 | Website diagnosis | Tarama → Evidence → Finding → Recommendation (connector zorunlu değil) |
| 5 | Website connectors | WordPress, GSC, GA4, PageSpeed/Lighthouse, DataForSEO (read-only) |
| 6 | AI insights | Finding/Evidence üzerinde açıklama ve taslak öneri (kanıt zorunlu) |
| 7 | Sonraki digital asset’ler | GBP, Google Ads, Meta Ads, Instagram, … |

---

## Faz 0 — Core application

**Çıktılar**

* Laravel 13 + PHP 8.3+ uygulama iskeleti
* Filament 5 panel (ajans içi)
* Auth + Users + Roles (Admin, Team Member)
* Customers, Customer contacts, Brands
* Digital assets + Connections (çekirdek modeller)
* Encrypted credentials
* Tasks, Notes, Attachments, Tags (temel)
* Audit logs, Application settings, Health checks
* MySQL 8, database queue, Laravel scheduler

**Kabul kriterleri**

* Müşteri girişi yok; yalnızca ajans kullanıcıları
* Workspace / tenant tabloları yok
* Harici write API yok

**Bağımlılık:** Yok (ilk faz)

---

## Faz 1 — Module platform

**Çıktılar**

* Module registry + enable/disable
* `module.manifest.json` yükleme (yerel Composer/Filament plugin paketleri)
* Navigation extension points
* Module-scoped migrations
* Events / Jobs kayıt yüzeyi
* Run history + Error logs altyapısı
* Evidence / Findings / Recommendations çekirdek kayıt modelleri (ortak)

**Kabul kriterleri**

* `docs/module-sdk` sözleşmelerine uyum
* Disable edilen modül paneli/job’u düşürmez
* Marketplace / ZIP upload yok

---

## Faz 2 — Sample module

**Çıktılar**

* `sample-module` (veya eşdeğeri) yerel paket
* Brand sekmesi, permission, setting, job, event, kendi tablosu, health check
* Pest testleri + `MODULE_TEST_CHECKLIST` kapısı

**Kabul kriterleri**

* Enable/disable duman testi geçer
* SDK regresyonu için referans kalır

---

## Faz 3 — Website asset

**Çıktılar**

* Website digital asset tipi
* Domain ve temel site bilgileri
* Connection bağlama UI (henüz tüm connector’lar zorunlu değil)

**Kabul kriterleri**

* Customer → Brand → Website kaydı uçtan uca yapılır
* Connection’lar Website’e bağlanır (GA4/GSC/DataForSEO asset değildir)

---

## Faz 4 — Website diagnosis

**Çıktılar**

* Diagnosis run başlatma (background job)
* Evidence toplama (temel teknik/içerik, connector’suz)
* Finding + severity
* Recommendation üretimi
* Kullanıcının Recommendation → Task manuel dönüşümü

**Kabul kriterleri**

* GA4 / GSC / DataForSEO olmadan temel teşhis çalışır
* Uzun tarama HTTP sync değildir
* Harici sisteme yazma yoktur

---

## Faz 5 — Website connectors

Sıra önerisi (paralelize edilebilir; hepsi read-only):

1. WordPress Connector  
2. Search Console Connector  
3. GA4 Connector  
4. PageSpeed / Lighthouse Connector  
5. DataForSEO Connector  

**Kabul kriterleri**

* En düşük salt okunur yetki
* Bağlantı eklendikçe diagnosis kapsamı/güven artışı gözlemlenir
* Credential’lar Laravel encryption ile saklanır

---

## Faz 6 — AI insights

**Çıktılar**

* AI Insights modülü
* Girdi: Evidence + Finding (+ normalize veri özetleri)
* Çıktı: açıklama, ilişki, olası neden, öncelik, Recommendation/Task taslağı
* Kanıtsız kesin hüküm engeli

**Kabul kriterleri**

* Ham kontrolsüz dump ile AI çağrısı yok
* MCP / multi-agent yok
* Kullanıcı Task’ı onaylamadan otomatik harici aksiyon yok (zaten yasak)

---

## Faz 7 — Sonraki digital asset modülleri

Sıra:

1. Google Business Profile  
2. Google Ads  
3. Meta Ads  
4. Instagram  
5. Diğerleri  

Her biri: Asset modülü + ilgili read-only connector’lar + (gerekirse) diagnosis.  
Write action eklenmez.

---

## Fazlar arası kurallar

1. Üst fazın kabul kriterleri geçmeden alt faz “tamam” sayılmaz.  
2. SaaS / Client Portal / marketplace maddeleri roadmap’e girmez.  
3. Redis/Horizon ancak Faz 0–5 sırasında ölçülen kuyruk ihtiyacıyla ADR açılarak eklenir.  
4. Teknoloji sapması yeni ADR gerektirir.

## Kodlamaya başlamadan önce zorunlu açık sorular

1. Filament panel URL / auth guard yapısı (tek panel mi)?  
2. Connection credential şeması: provider bazlı JSON mi, normalize kolonlar mı?  
3. Evidence/Finding/Recommendation ortak tablolarının minimal alan seti nedir?  
4. Recommendation → Task manuel dönüşümde hangi alanlar kopyalanır?  
5. AI sağlayıcı seçimi ve key yönetimi (ürün write yasağını bozmadan)?  
6. Website Diagnosis’in connector’suz “temel” kural kataloğu kapsamı nedir?

Bu sorular ürünü SaaS’a çevirmeden cevaplanmalıdır.
