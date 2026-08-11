# DIRECTORY_STRUCTURE

> **HISTORICAL SNAPSHOT**  
> This document reflects an earlier project state and is **NOT** the canonical current product truth.  
> For current truth consult: `docs/MASTER_SPEC.md`, accepted ADRs, `PROJECT_MEMORY.md`, `PRODUCT_CAPABILITY_LEDGER.md`, and `docs/PROJECT_STATUS.md` where current.


> İnceleme tarihi: 2026-08-06  
> Not: Yalnızca anlamlı / izlenen proje dosyaları listelenmiştir. `.git` içeriği ve örnek hook dosyaları “önemsiz / otomatik” kabul edilip detaylandırılmamıştır.

## Mevcut yapı

```text
/
└── README.md
```

İnceleme anında izlenen (tracked) tek dosya budur. `docs/current-state/` bu inceleme sonucunda eklenen dokümantasyon klasörüdür; uygulama kodu değildir.

## Önemli klasörler

| Klasör | Durum | Görev |
|--------|--------|--------|
| `src/`, `app/`, `frontend/`, `backend/`, `api/`, `lib/`, `packages/` vb. | **Yok** | — |
| `docs/` | Bu inceleme ile oluşturuluyor | Mevcut durum belgelendirmesi |
| `.github/` | **Yok** | Workflow / template yok |
| `node_modules/`, `dist/`, `build/`, `.next/` vb. | **Yok** | — |

## Ana dosyaların görevleri

| Dosya | Görev |
|-------|--------|
| `README.md` | Proje adı (`dijitaloperation`) ve tek cümlelik ürün açıklaması. Kurulum, mimari veya kullanım yönergesi içermez. |

## Otomatik / önemsiz kabul edilenler

Aşağıdakiler standart Git deposu artıklarıdır; proje mimarisinin parçası değildir:

- `.git/` ve altındaki nesne deposu, config, refs
- `.git/hooks/*.sample` örnek hook dosyaları
- `.git/info/exclude` (varsayılan yorum satırları; özel ignore kuralı yok)
- Cursor ortamına ait `.git/cursor/` meta verisi (varsa)

## Eksik ama tipik olarak beklenen yapılar

Aşağıdakiler depoda **bulunamadı** (tahmin değil; dosya taraması sonucu):

- Uygulama kaynak klasörleri
- Test klasörleri
- Yapılandırma dosyaları (`tsconfig`, `vite.config`, `next.config`, vb.)
- Bağımlılık manifestleri
- Ortam değişkeni örnekleri (`.env.example` vb.)
- Veritabanı şema / migration klasörleri
