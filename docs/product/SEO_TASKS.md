# SEO Görevleri

> **Durum:** `chatgpt/search-demand-foundation` üzerinde kodlandı, PHPUnit ile doğrulandı. Gerçek marka verisiyle operatör UAT'si henüz yapılmadı.
> Bu sayfa gerçekten yapılanı anlatır. Kaynak öncelik sırası için `PROJECT_MEMORY.md`.

## Amaç

Her web sitesi varlığı için haftada bir, operatörün doğrudan uygulayabileceği kısa bir SEO görev listesi üretmek. Ana odak: **hangi marka için web sitesine hangi içeriğin yazılacağı** — site başına haftada en az 4 içerik önerisi (`oluştur` tipi), her biri içerik briefiyle.

Dört görev türü:

| Tür | Kaynak | Örnek |
| --- | --- | --- |
| `düzelt` (fix) | Açık web sitesi bulguları + sayfa envanteri kuralları | "Meta açıklaması olmayan sayfalar (7)" |
| `güçlendir` (strengthen) | Yıldızlı hizmetlerin 5–20. sıradaki GSC sorguları | "İmplant için sayfayı güçlendir: /implant/" |
| `oluştur` (create) | Sayfası olmayan sorgu kovaları + kütüphane sorguları + sayfasız öncelikli hizmet | "İmplant nasıl yapılır: adım adım rehber" |
| `ai-görünürlük` (ai_visibility) | Organization şeması, robots.txt bot engeli, FAQPage bloğu | "robots.txt şu botları engelliyor: OAI-SearchBot" |
| `soru` (question) | Hizmet ↔ sayfa eşleşmesi belirsiz | "\"Zirkonyum\" hizmetinin sayfası hangisi?" |

## Tablolar

- `seo_plans` — bir sitenin bir koşusu. Her koşu yeni satır (`version` artar); `input_summary`, `llm_summary`, `result_summary`, `summary_text` ("12 görev: 4 düzelt, 5 güçlendir, 3 oluştur").
- `seo_tasks` — görev. `task_key = sha256(tür|kural|url/hizmet imzası)`; aynı anahtar güncellenir, yeni eklenir, bu koşuda üretilmeyen açık görev `stale` olur. `done`/`skipped` görevlere dokunulmaz. Alanlar: müşteri, marka, varlık, hizmet, tür, ciddiyet, `priority_score`, `estimated_extra_clicks`, başlık, neden, `evidence` (JSON), `checklist` (JSON), `target_url`, `is_new_page`, `content_brief` (JSON), `llm_payload`, durum, ilk/son görüldüğü plan.
- `service_page_assignments` — hizmet ↔ site sayfası. `decision_source=auto` her koşuda yeniden hesaplanır; `operator` cevabı kalıcıdır ve bir daha sorulmaz.
- `brand_offerings.is_priority` — yıldız. Migrasyon mevcut `priority_rank` dolu olanları yıldızlar; marka formundaki öncelik sırası kaydı bayrağı senkron tutar.

Mevcut `Task` / `Finding` / `Recommendation` tablolarına dokunulmadı. Kümeleme (search demand cluster) tablolarına bağımlılık yok.

## Motor — `App\Services\SeoTasks\SeoPlanRunner`

Tetik: site sayfasındaki "Planı yenile", `/seo-tasks` sayfasındaki "Tümünü yenile", haftalık zamanlayıcı (`moxdop:seo:plan --scheduled`, pazartesi 06:30, yalnızca Search Console bağlı siteler) veya `php artisan moxdop:seo:plan --asset=ID [--sync]`. Kuyrukta çalışır (`RunSeoPlanJob`), Etkinlik ekranında `seo_plan` operasyonu olarak görünür.

**A — Paket topla** (`SeoPlanInputCollector`, sadece DB):
`gsc_query_page_daily` (son 90 gün, sorgu×sayfa toplamı, gösterim ağırlıklı pozisyon `metadata.provider_average_position`), `website_page_profiles` (`source_states.website.*` + WordPress SEO alanları), açık `findings` (website / website-diagnosis), marka hizmetleri (isimler, takma adlar, hizmet eşleşme kelimeleri, portföy sorguları), hizmet bölgeleri, GA4 iniş sayfası (`sessions`, `engagedSessions`, `keyEvents`), robots.txt gövdesi (`evidence.type=robots`), mevcut eşlemeler.

**B — Kurallar** (`SeoTaskRuleEngine`, deterministik):
- Hizmet sayfası tespiti: slug/başlık/H1 kimlik eşleşmesi + hizmet sorgularının GSC gösterim payı → ≥ 0.60 otomatik ata, ≥ 0.25 soru, altı "sayfası yok".
- Düzelt: bulgular (critical/high/medium; low olmaz) + envanter kuralları (5xx, başlık yok, meta yok, H1 yok, yönlendirme zinciri ≥ 2, canonical çelişkisi, < 150 kelime, kritik tarama hatası, site geneli noindex). Envanter kuralları sayfa listesiyle tek görevde toplanır.
- Güçlendir (yalnızca yıldızlı hizmetler): sorgu 5–20. sırada, hiçbir sayfa 5'in üstünde değil, ≥ 100 gösterim. Puan = gösterim × (CTR(3) − CTR(mevcut)). Checklist: ana sorgu title/H1/meta'da yoksa ekle, < 800 kelime ise genişlet, ikinci sayfa ≥ %25 pay alıyorsa "niyeti ayır" (otomatik 301 asla), GA4 oturum var/dönüşüm yoksa CTA. Hizmet başına en fazla 2.
- Oluştur: (1) ≥ 30 gösterimli, ilk 20'de sayfası olmayan GSC sorguları hizmet × niyet (hizmet / rehber / SSS / konum) kovalarına; (2) hiçbir sayfanın başlık/H1/slug ile karşılamadığı kütüphane sorguları; (3) sayfası olmayan yıldızlı hizmet. Kovalar beklenen tıklamaya göre sıralanır; **asgari 4** için yıldızlı hizmetlerden başlayarak rehber/SSS/konum briefleriyle tamamlanır (kaynak `fallback` olarak işaretlenir). Her görevde deterministik brief: sayfa başlığı, tür, karar (yeni sayfa / mevcut sayfaya bölüm), hedef URL, H2 taslağı, kapsanacak sorgular, hedef uzunluk, iç linkler.
- AI görünürlük: ana sayfada Organization/LocalBusiness şeması; robots.txt'de OAI-SearchBot / ChatGPT-User / Claude-SearchBot / ClaudeBot / Googlebot / Google-Extended / PerplexityBot için `Disallow: /` (Googlebot ise kritik); yıldızlı hizmet sayfalarında FAQPage.
- Kotalar: site başına 15 açık görev; oluştur en fazla 6 (asgari 4 korunur), güçlendir 6, düzelt 6, AI görünürlük 3.

**C — LLM** (`SeoPlanAiEnricher`, tek çağrı, isteğe bağlı):
Rota `seo_tasks.content_planner` (varsayılan: Anthropic `claude-sonnet-5`, yedek OpenAI; AI Control Plane'den değiştirilebilir). Anahtar: entegrasyon kaydı veya `.env` `ANTHROPIC_API_KEY`. Girdi: B'nin en fazla 12 adayı + 60 sayfa özeti. Çıktı (yapılandırılmış şema): aday başına Türkçe başlık, neden, checklist; `oluştur` için karar + brief. Uygulanan doğrulamalar: bilinmeyen `candidate_id` atılır, kanıtta olmayan sorgular atılır, farklı host'taki URL'ler atılır, puan ve `task_key` hiç değişmez. Sağlayıcı yoksa / şema uymazsa / hata olursa kural görevleri olduğu gibi yazılır; plan yine tamamlanır (`llm_summary.skipped_reason`). `SEO_TASKS_LLM_ENABLED=false` ile kapatılır.

**D — Yaz** (`SeoPlanWriter`): eşlemeler + görev diff'i (yukarıda). Plan satırına özet.

## Arayüz

- Ana menü → **SEO Görevleri** (`/seo-tasks`): tüm müşteriler, öncelik sıralı. Filtre: müşteri, site, tür, durum. Satır aç → yapılacaklar, kanıt (sorgu tablosu / URL listesi), içerik briefi, soru cevabı. `Yapıldı` / `Atla` / `Yeniden aç`. "Tümünü yenile".
- Web sitesi varlık sayfası → **SEO Görevleri** sekmesi (`/assets/website/{id}?tab=seo`): aynı bileşen, siteye filtreli, "Planı yenile" + son koşu özeti (GSC var/yok, sorgu/sayfa sayısı, AI durumu).
- Marka sayfası → Business sekmesi → Offerings: hizmet yanında ★ (tek tık).

## Sabitler

`config/moxdop-seo-tasks.php`: pencere, CTR eğrisi (pozisyon → CTR, doğrusal enterpolasyon), güçlendir/oluştur/düzelt eşikleri, hizmet sayfası puan eşikleri, bot listesi, kotalar, LLM sınırları, zamanlayıcı günü/saati. "Çok kasıyor / az kasıyor" durumunda kod değil sayı değişir.

Env: `SEO_TASKS_ENABLED`, `SEO_TASKS_QUEUE_CONNECTION`, `SEO_TASKS_QUEUE`, `SEO_TASKS_SCHEDULE_ENABLED`, `SEO_TASKS_LLM_ENABLED`, `ANTHROPIC_API_KEY`.

## Bilinen sınırlar

- Çift H1 ve alt metin eksikliği sayfa profilinde yok (HTML okunmadan bilinemez); bu iki kural bu sürümde üretilmez.
- Organization şemasında `sameAs` içeriği kontrol edilmez; sadece şema türü kontrol edilir.
- GSC bağlı değilse `güçlendir` üretilmez; `oluştur` kütüphane ve tahmini brieflerle asgariyi doldurur ve bunu `fallback` olarak işaretler.
- Hizmet tanımlı değilse plan yalnızca düzelt / AI görünürlük üretir; içerik önerisi için önce marka hizmetleri girilmelidir.
- Sayfa envanteri (WordPress / public crawl) yoksa hizmet sayfası eşleşmesi ve envanter kuralları boş kalır.

## Testler

`tests/Feature/SeoTasks/SeoTaskRuleEngineTest.php` (saf kural motoru, CTR eğrisi, metin yardımcıları, operatör kararı, GSC'siz asgari 4) ve `tests/Feature/SeoTasks/SeoPlanRunTest.php` (kuyruk + koşu + diff + arayüzler + LLM birleştirme, `SeoTaskContentPlannerAgent::fake`).
