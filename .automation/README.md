# DOP Development Automation Loop

Bu klasör, DOP uygulama özelliklerini yazmaz.  
Amacı: **OpenAI’nin mimar/reviewer**, **Cursor CLI’ın implementer/fixer** olduğu bir GitHub Actions döngüsü kurmak.

## Kim ne yapıyor?

| Rol | Araç | Ne yapar? |
|-----|------|-----------|
| Architect | OpenAI (`architect.py`) | Roadmap + MASTER_SPEC + repo durumuna bakıp sıradaki **küçük** işi JSON olarak seçer |
| Implementer | Cursor CLI (`agent`) | O işi kodlar ve testleri çalıştırır (branch/PR yapmaz) |
| Git/PR | GitHub Actions | Branch, commit, push, PR oluşturur |
| Reviewer | OpenAI (`reviewer.py`) | Sadece automation marker’lı PR’ları inceler |
| Fixer | Cursor CLI | Reviewer’ın yazdığı sorunları aynı PR’da düzeltir (max 3 deneme) |

İlk sürümde **otomatik merge yok**. Onaylanan PR insan/sonraki süreçle birleştirilir.

## Gerekli GitHub Secrets

Repository → Settings → Secrets and variables → Actions:

* `OPENAI_API_KEY` — Architect ve Reviewer için
* `CURSOR_API_KEY` — Cursor CLI headless agent için

Opsiyonel:

* `OPENAI_ARCHITECT_MODEL` (varsayılan: `gpt-4.1`)
* `OPENAI_REVIEWER_MODEL` (varsayılan: `gpt-4.1`)
* `CURSOR_AGENT_MODEL` (Cursor agent model; boşsa CLI varsayılanı)

`GITHUB_TOKEN` Actions tarafından sağlanır; ekstra secret gerekmez.

## Workflow’lar

### 1) `dop-next-task.yml` — sıradaki işi başlat

**Trigger:**

* Manuel: Actions → **DOP Next Task** → Run workflow
* Otomatik: bir PR `main`’e **merge** edildiğinde  
  (ama `chore/`, `automation/`, `docs:` bakım PR’ları yeni ürün görevi başlatmaz)

**Akış:**

1. Architect JSON üretir  
2. `TASK_READY` değilse durur (`HUMAN_REQUIRED` / `ROADMAP_COMPLETE`)  
3. Cursor implementer çalışır  
4. Secret dosya kontrolü + testler  
5. Güvenli branch adı ile branch/commit/push  
6. `<!-- DOP_AUTOMATION_PR -->` marker’lı PR açılır (`base: main`)

### 2) `dop-review.yml` — automation PR review + fix loop

**Trigger:** PR opened / synchronize / reopened  
**Koşul:** PR body içinde `<!-- DOP_AUTOMATION_PR -->` olmalı

**Akış:**

1. Reviewer JSON üretir ve PR comment yazar  
2. `APPROVED` → sadece marker comment, kod değişmez  
3. `FIX_REQUIRED` → en fazla **3** otomatik fix; sonra `HUMAN_REQUIRED`  
4. `HUMAN_REQUIRED` → açıklama comment, dur

## HUMAN_REQUIRED ne demek?

Otomasyon emin değil veya politika/çelişki/risk var.  
Kod yazmayı bırakır; insanın bakması gerekir.

Örnekler:

* Roadmap belirsiz
* Diff çok büyük
* MASTER_SPEC ile çelişen istek
* 3 fix denemesi bitti

## Otomasyonu kapatma

* Workflow dosyalarını disable edin, veya
* Actions → ilgili workflow → Disable workflow, veya
* Secrets’ı kaldırın / geçersizleştirin (workflow’lar başarısız olup durur)

`chore/` branch merge’leri zaten yeni ürün görevi başlatmaz.

## Maliyet nereden gelir?

* OpenAI API: her next-task + her review çağrısı
* Cursor API: her implementation + her fix denemesi

Küçük görevler ve max 3 fix limiti maliyeti sınırlamak içindir.

## Yerel yardımcı komutlar

Secret değerlerini yazdırmayın.

```bash
cd .automation
python -m pip install -r requirements.txt
python -m unittest discover -s tests -v

# Örnek JSON doğrulama (API çağrısı yok)
python architect.py --validate-only /path/to/task.json
python reviewer.py --validate-only /path/to/review.json
```

## Manuel test (Secrets tanımlıysa)

1. `OPENAI_API_KEY` ve `CURSOR_API_KEY` secrets’larını ekleyin.  
2. Actions → **DOP Next Task** → Run workflow.  
3. Architect çıktısını ve oluşan PR’ı kontrol edin.  
4. Review workflow’unun PR comment’i yazdığını doğrulayın.  
5. Bilerek küçük bir hata bırakılmış test PR’sinde fix loop’u gözlemleyin (max 3).

## Güvenlik sınırları

* Workflow permission: `contents: write`, `pull-requests: write`
* Secret değerleri echo edilmez / Cursor promptuna gömülmez
* `.env`, private key, pem vb. commit engeli
* Cursor’a git push/PR yetkisi verilmez; git işlemleri Actions’ta deterministiktir
* Hiçbir workflow uygulama kodunu doğrudan `main`’e push etmez
* Oversized diff → `HUMAN_REQUIRED`
* Aynı anda tek next-task (concurrency)

## Dosyalar

* `architect.py` / `reviewer.py` — OpenAI çağrıları + JSON doğrulama
* `common.py` — marker, branch, schema, secret-path helpers
* `prompts/*.md` — rol talimatları
* `tests/` — unit testler (application testlerine dokunmaz)
