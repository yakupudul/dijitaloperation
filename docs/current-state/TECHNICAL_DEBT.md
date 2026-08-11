# TECHNICAL_DEBT

> **HISTORICAL SNAPSHOT**  
> This document reflects an earlier project state and is **NOT** the canonical current product truth.  
> For current truth consult: `docs/MASTER_SPEC.md`, accepted ADRs, `PROJECT_MEMORY.md`, `PRODUCT_CAPABILITY_LEDGER.md`, and `docs/PROJECT_STATUS.md` where current.


> İnceleme tarihi: 2026-08-06  
> Not: Klasik teknik borç (tekrarlayan kod, kırık testler vb.) için kaynak kod gerekir. Bu depoda kaynak kod olmadığı için borç “ürün/altyapı boşluğu” ve “gelecek risk” olarak belgelenmiştir. Tahminler gerçek durum gibi sunulmamıştır.

## Tekrarlanan kodlar

**Uygulanabilir değil.** Tekrarlanacak uygulama kodu yok.

## Birbirine aşırı bağımlı bölümler

**Uygulanabilir değil.** Modül / paket bağımlılık grafiği yok.

## Güvenlik riskleri

| Risk | Durum | Not |
|------|--------|-----|
| Hard-coded secret | Tespit edilmedi | Kaynak dosya yok |
| Auth zafiyeti | Uygulanabilir değil | Auth yok |
| Bağımlılık CVE’leri | Uygulanabilir değil | Bağımlılık manifesti yok |
| Açık repo / yanlış erişim | **Doğrulanamadı** | Repo görünürlüğü ve ekip yetkileri bu incelemede doğrulanmadı |
| README dışı hassas içerik | Yok | İzlenen tek dosya README |

## Performans riskleri

**Uygulanabilir değil.** Çalışan uygulama veya veri katmanı yok.

## Eksik testler

| Alan | Durum |
|------|--------|
| Unit test | Yok |
| Integration test | Yok |
| E2E test | Yok |
| Test runner yapılandırması | Yok |

Test eksikliği, henüz kod olmadığı için “borç”tan çok “başlanmamış katman”dır.

## Bozuk veya kullanılmayan kodlar

**Yok.** Dead code, orphan import veya kırık build script tespit edilmedi.

## Modüler mimariye geçişi zorlaştırabilecek noktalar

Mevcut kod tabanı boş olduğu için geçişi zorlaştıran somut bir bağımlılık yumağı **yok**. Buna karşılık, ileride borç oluşturabilecek **erken riskler** (henüz gerçekleşmemiş):

| Risk | Neden “risk” sayıldı | Kanıt durumu |
|------|----------------------|--------------|
| Ürün vizyonu belirsizliği | README tek cümle; domain modeli yok | Doğrulandı (README içeriği) |
| Teknoloji seçiminin gecikmesi | Stack dosyası yok; yanlış erken seçim riski henüz oluşmadı | Doğrulandı (manifest yok) |
| Dokümantasyon / kod ayrışması | Bu `docs/current-state` seti boş bir kod tabanını yansıtır; kod eklendikçe dokümanlar bayatlayabilir | Süreç riski; kod eklendiğinde yeniden doğrulanmalı |

## Sonuç

Klasik teknik borç envanteri boştur çünkü uygulama yoktur. Asıl bulgu: platform henüz iskelet aşamasında bile değildir; borç birikiminden önce temel mimari ve domain modelinin tanımlanması gerekir.
