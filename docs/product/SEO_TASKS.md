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

Saklı HTML (`SeoStoredHtmlReader`, modüldeki `StoredPageReader::html()` üzerinden, HTTP yok): ana sayfa ve en çok gösterim alan sayfalardan başlayarak en fazla 150 sayfa. Çıkan: H1 sayısı ve metinleri, görsel / alt'sız görsel sayısı, JSON-LD türleri ve `sameAs`, ilk 8 sayfa için metin özeti.

Veri doğruluğu kuralları:
- HTML olmayan adresler (feed, sitemap, robots.txt, wp-json, medya, `?replytocom=` vb.) sayfa sayılmaz; plan özetinde kaç tanesinin dışarıda bırakıldığı gösterilir.
- Toplanmamış ≠ eksik: başlık yalnızca başlık alanı gerçekten gözlenip boş bulunduğunda "yok" sayılır; meta açıklaması yalnızca `<head>` gözlendiğinde; H1 yalnızca saklı HTML okunduğunda veya profil açıkça "yok" dediğinde.
- Toplu düzelt görevlerinde arama trafiği alan sayfalar listenin başına gelir; indekslenebilir sayfaların yarısından fazlasını etkileyen sorun "şablon düzeyi" olarak işaretlenir ve önce tema/SEO eklentisi ayarı önerilir.

**A2 — Site anlama** (`SeoSiteUnderstanding`, markada aktif hizmet yoksa **veya markanın hizmetleri bu siteyle örtüşmüyorsa**: GSC gösterimi ≥ 200, hizmet sorgularının payı < %3 ve hiçbir sayfa başlığı/H1 hizmet adını içermiyor):
1. Son 28 gün içindeki planın çıkarımı varsa yeniden kullanılır (`source=cache`).
2. Yoksa tek AI çağrısı (rota `seo_tasks.site_understanding`, Anthropic birincil): ana sayfa başlık/meta/H1/metin özeti, 80 sayfa (başlık, H1, kelime, GSC gösterimi), en çok gösterim alan 200 GSC sorgusu, 20 GA4 iniş sayfası. Çıktı: marka özeti, hedef kitle, bölgeler, en fazla 8 hizmet (ad, takma adlar, ana sayfa URL'si, ilgili sorgular, çekirdek mi). Doğrulama: envanterde olmayan URL'ler ve GSC'de olmayan sorgular atılır.
3. AI yoksa / hata verirse kural tabanlı: blog/iletişim/kurumsal yolları hariç, en çok gösterim alan 6 sayfa konusu (H1 ya da başlığın ilk parçası), ilk 3'ü yıldızlı.

Çıkarılan hizmetler plan içinde kalır (`inferred:<slug>`), markaya yazılmaz; görevlerde `brand_offering_id` boş, `evidence.service` dolu. Sitenin SEO sekmesinde "Markada hizmet tanımlı değil — siteden çıkarıldı" kutusunda listelenir; "Markaya ekle" gerçek hizmet + yıldız + sayfa eşlemesi (operatör kararı) oluşturur. Marka hizmeti olduktan sonra çıkarım durur.

**B — Kurallar** (`SeoTaskRuleEngine`, deterministik):
- Hizmet sayfası tespiti: puan = %40 başlık/H1'de hizmet adı + %25 URL kimliği + %35 hizmet sorgularının GSC gösterim payı; blog/soru biçimli sayfalar ×0.6, kısa URL +0.05. ≥ 0.55 ya da (≥ 0.40, adla eşleşme var ve ikinci adaydan ≥ 0.15 önde) → otomatik ata. Aksi hâlde yalnızca **yıldızlı** hizmetler sorulur ve site başına **tek** "hizmet sayfalarını eşleştir" kartında toplanır; yıldızsız hizmetler sessizce eşlemesiz kalır. Adayı olan hizmet için "yeni hizmet sayfası aç" önerilmez.
- Düzelt: bulgular (critical/high/medium; low olmaz) + envanter kuralları (5xx, başlık yok, meta yok, H1 yok, **çift H1**, yönlendirme zinciri ≥ 2, canonical çelişkisi, < 150 kelime, kritik tarama hatası, site geneli noindex, **hizmet sayfalarında alt'sız görsel**). H1 ve alt kuralları saklı HTML okunan sayfalarda HTML'e göre çalışır. Envanter kuralları sayfa listesiyle tek görevde toplanır.
- Güçlendir (yalnızca yıldızlı hizmetler): sorgu 5–20. sırada, hiçbir sayfa 5'in üstünde değil, ≥ 100 gösterim. Puan = gösterim × (CTR(3) − CTR(mevcut)). Checklist: ana sorgu title/H1/meta'da yoksa ekle, < 800 kelime ise genişlet, ikinci sayfa ≥ %25 pay alıyorsa "niyeti ayır" (otomatik 301 asla), GA4 oturum var/dönüşüm yoksa CTA. Hizmet başına en fazla 2.
- Oluştur: (1) ≥ 30 gösterimli, ilk 20'de sayfası olmayan GSC sorguları hizmet × niyet (hizmet / rehber / SSS / konum) kovalarına; (2) hiçbir sayfanın başlık/H1/slug ile karşılamadığı kütüphane sorguları; (3) sayfası olmayan yıldızlı hizmet. Kovalar beklenen tıklamaya göre sıralanır; **asgari 4** için yıldızlı hizmetlerden başlayarak rehber/SSS/konum briefleriyle tamamlanır (kaynak `fallback` olarak işaretlenir). Her görevde deterministik brief: sayfa başlığı, tür, karar (yeni sayfa / mevcut sayfaya bölüm), hedef URL, H2 taslağı, kapsanacak sorgular, hedef uzunluk, iç linkler.
- AI görünürlük: ana sayfada Organization/LocalBusiness şeması (profil + saklı HTML JSON-LD); şema var ama `sameAs` boşsa ayrı görev; robots.txt'de OAI-SearchBot / ChatGPT-User / Claude-SearchBot / ClaudeBot / Googlebot / Google-Extended / PerplexityBot için `Disallow: /` (Googlebot ise kritik); yıldızlı hizmet sayfalarında FAQPage.
- Kotalar: site başına 15 açık görev; oluştur en fazla 6 (asgari 4 korunur), güçlendir 6, düzelt 6, AI görünürlük 3.

**C — LLM** (`SeoPlanAiEnricher`, tek çağrı, isteğe bağlı):
Rota `seo_tasks.content_planner` (varsayılan: Anthropic `claude-sonnet-5`, yedek OpenAI; AI Control Plane'den değiştirilebilir). Anahtar: entegrasyon kaydı veya `.env` `ANTHROPIC_API_KEY`. Girdi: B'nin en fazla 12 adayı + 60 sayfa özeti. Çıktı (yapılandırılmış şema): aday başına Türkçe başlık, neden, checklist; `oluştur` için karar + brief. Uygulanan doğrulamalar: bilinmeyen `candidate_id` atılır, kanıtta olmayan sorgular atılır, farklı host'taki URL'ler atılır, puan ve `task_key` hiç değişmez. Sağlayıcı yoksa / şema uymazsa / hata olursa kural görevleri olduğu gibi yazılır; plan yine tamamlanır (`llm_summary.skipped_reason`). `SEO_TASKS_LLM_ENABLED=false` ile kapatılır.

**D — Yaz** (`SeoPlanWriter`): eşlemeler + görev diff'i (yukarıda). Plan satırına özet.

## Konum kuralı

- Hizmet adları, takma adları ve sorgu kütüphanesindeki anahtar kelimeler konum içermez ("Uyluk Germe", "Uyluk Germe Ankara" değil). Böylece başka şehirdeki markalarda da kullanılır.
- Marka özelinde konum, markanın **hizmet verdiği yerler** alanından gelir (bölge sayfası önerileri, portföyde konumlu varyantlar).
- Search Console'da hizmet bölgesi dışındaki bir konumla gelen sorgu ("… ankara", marka İstanbul'da) içerik önerisine girmez. Ülke düzeyi ("turkey") bölge dışı sayılmaz.
- Bölge dışı konumların toplam gösterimi eşiği (`locations.out_of_area_min_impressions`, 50) geçerse site başına tek karar kartı açılır: "Hizmet verdiği yerlere ekle" ya da "Hizmet vermiyorum" (aynı konumlar tekrar sorulmaz; yeni konum yeni kart açar).
- Hizmet bölgesi tanımlı değilse bölge dışı yargısı yapılmaz.

## Arayüz

- Ana menü → **SEO Görevleri** (`/seo-tasks`):
  1. Dört özet kartı: bu haftanın içerik önerileri / hedef (site × 4), tahmini ek tıklama (90 gün), kritik/yüksek teknik sorun, kurulum bekleyen hizmet eşleşmesi.
  2. Eşleştirme kartları (site başına bir tane, siteye götürür).
  3. Site tablosu: açık görev, içerik önerisi / hedef, kritik, eşleştirme, tahmini ek tık, son plan.
  4. Filtreler (müşteri, site, tür, durum) ve etkiye göre sıralı görev listesi. Satırda Türkçe ciddiyet, etki, efor, hedef URL, ilk görülme tarihi; açınca yapılacaklar, kanıt, içerik briefi ve "Briefi kopyala".
- Web sitesi varlık sayfası → **SEO Görevleri** sekmesi (`/assets/website/{id}?tab=seo`): aynı bileşen, siteye filtreli; başlıkta son plan zamanı ve veri kaynakları (Search Console, sayfa envanteri, saklı HTML, GA4, AI) durum rozetleriyle; eşleştirme kartında hizmet başına açılır liste + Kaydet; site anlama kutusu.
- Marka sayfası → Business sekmesi → Offerings: hizmet yanında ★ (tek tık).

## Sabitler

`config/moxdop-seo-tasks.php`: pencere, CTR eğrisi (pozisyon → CTR, doğrusal enterpolasyon), güçlendir/oluştur/düzelt eşikleri, hizmet sayfası puan eşikleri, bot listesi, kotalar, LLM sınırları, zamanlayıcı günü/saati. "Çok kasıyor / az kasıyor" durumunda kod değil sayı değişir.

Env: `SEO_TASKS_ENABLED`, `SEO_TASKS_QUEUE_CONNECTION`, `SEO_TASKS_QUEUE`, `SEO_TASKS_SCHEDULE_ENABLED`, `SEO_TASKS_LLM_ENABLED`, `ANTHROPIC_API_KEY`.

Anthropic anahtarı tercihen arayüzden girilir: Entegrasyonlar → AI sağlayıcıları → Anthropic (`/integrations/anthropic`). Veritabanındaki anahtar `.env`'deki `ANTHROPIC_API_KEY`'den önce gelir. Rota adımları AI Control Plane'den değiştirilebilir.

## Bilinen sınırlar

- Alt metni kuralı yalnızca hizmet sayfalarına (atanmış veya çıkarılmış) bakar; `alt=""` dekoratif sayılır.
- Saklı HTML'i olmayan sayfalarda H1 kuralları sayfa profilindeki `h1_present` alanına düşer; çift H1 ve alt kontrolü yapılmaz.
- `sameAs` bağlantılarının doğruluğu (gerçekten markaya ait mi) kontrol edilmez, yalnızca varlığı.
- GSC bağlı değilse `güçlendir` üretilmez; `oluştur` kütüphane ve tahmini brieflerle asgariyi doldurur ve bunu `fallback` olarak işaretler.
- Hizmet tanımsız ve sayfa envanteri de GSC de olmayan sitede çıkarım boş kalır; plan yalnızca düzelt / AI görünürlük üretir.

## Testler

`tests/Feature/SeoTasks/SeoTaskRuleEngineTest.php` (saf kural motoru, CTR eğrisi, metin yardımcıları, operatör kararı, GSC'siz asgari 4) ve `tests/Feature/SeoTasks/SeoPlanRunTest.php` (kuyruk + koşu + diff + arayüzler + LLM birleştirme, saklı HTML kuralları, AI site anlama + önbellek + "Markaya ekle", AI'sız sayfa konusu yedeği).
