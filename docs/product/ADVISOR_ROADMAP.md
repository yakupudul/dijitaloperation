# Danışman Sistemi — Yol Haritası

> **Durum:** Onaylandı (2026-09-23, "Hepsi uygun"). Fazlar 1→6 sırayla uygulanır; Faz 7 (harici yazma) şimdilik kapsam dışı. Model varsayılanları: analiz Sonnet 5, sınıflandırma Haiku 4.5, herkese açık veri ücretsiz model, yedek Gemini. Aylık AI bütçesi 25 USD.
>
> **Faz 1 durumu:** 1 ve 2 kodlandı (PHPUnit). 3 (SEO Görevleri canlı doğrulama) operatör çalıştırmasını bekliyor.
>
> **Faz 2 durumu:** Kodlandı (PHPUnit): indeksleme (URL denetimi yönlendirme + kurallar), budama kararı, içerik çürümesi, iç linkleme, hız (lab LCP), GEO/AEO (hizmet şeması, cevap paragrafı, yazar/uzman, İşletme Profili tutarlılığı, GPTBot). Ayrıntı: `SEO_TASKS.md`. Açık: hizmet sayfalarının PageSpeed ile ölçülmesi.
> **Faz 3 durumu:** Kodlandı (PHPUnit): `/ads-advisor` ve Google Ads hesabında "Danışman" sekmesi. Ayrıntı: `ADS_ADVISOR.md`. Anahtar kelime kalite puanı artık toplanıyor (bir sonraki toplamadan itibaren).
> **Faz 4 durumu:** Kodlandı (PHPUnit): aynı `/ads-advisor` sayfası (kanal seçici) ve Meta hesabında "Danışman" sekmesi. Ayrıntı: `ADS_ADVISOR.md` → Meta Ads.
> **Faz 5 durumu:** Kodlandı (PHPUnit): menüde "Danışman" (Google Ads, Meta Ads, İşletme Profili) ve profil sayfasında "Danışman" sekmesi. Ayrıntı: `ADS_ADVISOR.md` → İşletme Profili.
> Kapsam: web sitesi (teknik, SEO, GEO/AEO), Google İşletme Profili (yorum cevaplama hariç), Google Ads, Meta Ads.

## 1. Amaç

Tek kişinin yönettiği ajans için, her markanın dijital varlıklarında **gerçekten yapılması gereken** işleri gösteren bir danışma sistemi. Sürekli iş üreten bir görev fabrikası değil.

Üç katman, bu sırayla:

1. **Algoritma:** Kuralla çözülebilen her şey kuralla çözülür. Eşleştirme, eşikler, karşılaştırma, önceliklendirme. Ücretsiz, tekrarlanabilir, açıklanabilir.
2. **AI, senin kontrolünde:** Yorum ve taslak gerektiren işler. Siteyi anlamak, brief yazmak, reklam metni taslağı, sayfa önerisi. Her zaman "hazırla → sen onayla" biçiminde çalışır.
3. **Sen:** Karar, onay ve AI'ın yetersiz kaldığı yer.

## 2. Tasarım ilkeleri

- **Az ama gerçek.** Her öneri dört şeyi taşımak zorunda: kanıt (sayı + kaynak), tahmini etki, efor ve "neden şimdi". Bunlardan biri yoksa öneri çıkmaz.
- **Kota ve sessizlik.** Kanal başına aynı anda en fazla 3–5 açık öneri. "Bu hafta bu marka için önemli bir iş yok" geçerli ve iyi bir sonuçtur.
- **Kendiliğinden kapanma.** Sorun ortadan kalkınca öneri kendisi kapanır. Senin işaretlemen gerekmez.
- **Sonuç ölçümü.** "Yapıldı" denen öneri 28 gün sonra ölçülür: tıklama, sıra, CPA, dönüşüm. Etkisi olmayan öneri türleri zamanla daha az gösterilir.
- **Platformun çözdüğünü yeniden çözme.** Search Console indeksleme sorunlarını, Google Ads kendi önerilerini ve Meta teslimat uyarılarını zaten veriyor. Sistem bunları okuyup süzer ve önceliklendirir; aynı analizi baştan yazmaz.
- **Önce toplanan veri, sonra yeni kaynak.** Aşağıdaki envanter gösteriyor ki verinin çoğu zaten geliyor ve kullanılmıyor.
- **Harici yazma yok (ADR-018).** Sistem Google Ads, Meta, İşletme Profili veya WordPress'te değişiklik yapmaz. Hazırlar; sen uygularsın. Bunun değişmesi ayrı bir karardır (bkz. Faz 7).

## 3. Mevcut durum

| Kanal | Toplanan veri | Kural sayısı | En büyük boşluk |
|---|---|---|---|
| Web / SEO | Tarama, HTML, başlıklar, şema, iç link grafiği, PageSpeed, WordPress, GSC (sorgu×sayfa, URL inceleme, sitemap), GA4 | Yaklaşık 80 (59 standart, 11 head kuralı, SEO Görevleri) | İç link grafiği, sitemap ve URL inceleme verisini hiçbir kural kullanmıyor. İçerik çürümesi ve budama kararı yok. |
| Google Ads | Kampanya, reklam grubu, anahtar kelime, arama terimi, açılış sayfası, dönüşüm, bütçe, varlık kapsamı, negatifler, cihaz/saat/konum, PMax, değişiklik geçmişi, **Google'ın kendi önerileri** | 8 | Kalite puanı, negatifler, değişiklik geçmişi, Google önerileri, cihaz/konum verisi hiç kullanılmıyor. |
| Meta Ads | Kampanya/ad set/reklam, kreatif, kırılımlar, saatlik, hedefleme, dönüşüm kaynağı (piksel), değişiklik geçmişi | 3 | Kreatif yorgunluğu, frekans, piksel sağlığı, hedefleme kuralı yok. |
| İşletme Profili | Konum, performans, aylık arama kelimeleri, yorumlar, gönderiler, fotoğraflar, özellikler, hizmetler | 0 (yalnızca site ile telefon/adres/URL tutarlılığı) | Zengin veri, hiç kural yok. |
| AI | 17 AI rotası, rota başına sağlayıcı/model seçimi (Ayarlar → AI) | — | Maliyet takibi ve bütçe sınırı yok. Kullanım yalnızca bir rotada kaydediliyor. |
| Kurulum | Kurulum sihirbazı, Public Discovery (siteden hizmet adayı, insan onaylı) | — | Hesapları alan adıyla otomatik eşleştiren bir yapı yok; her bağlantı elle. |

## 4. AI yönetimi ve model seçimi

**Sağlayıcılar.** Mevcut: OpenAI, Anthropic, Gemini. Eklenecek:
- **OpenRouter:** Tek anahtarla onlarca model, ücretsiz modeller dahil.
- **Groq:** Hızlı, ücretsiz katmanı olan açık modeller.

Anahtarlar mevcut Entegrasyonlar → AI sağlayıcıları ekranından girilir.

**Rota başına model seçimi zaten var** (Ayarlar → AI Kontrol Paneli). Eklenecekler:
1. Her AI çağrısının token kullanımı ve **dolar maliyeti** (rota, marka ve ay bazında).
2. Sağlayıcı başına **aylık bütçe sınırı.** Sınıra gelince AI adımı atlanır ve plan kural sonuçlarıyla devam eder.
3. Rota başına **"müşteri verisi içerir"** işareti. İşaretli rotalarda ücretsiz katman sağlayıcıları seçilemez, çünkü ücretsiz katmanlarda gönderilen veri sağlayıcı tarafından kullanılabiliyor.

**Önerilen varsayılanlar** (hepsi ekrandan değiştirilebilir):

| İş türü | Birincil | Yedek | Neden |
|---|---|---|---|
| Site anlama, içerik briefi, reklam analizi, danışman özeti | Claude Sonnet 5 | Gemini Flash (ücretli) | Muhakeme ve Türkçe kalitesi önemli; çağrı başına birkaç sent |
| Eşleştirme, sınıflandırma, toplu etiketleme | Claude Haiku 4.5 | Gemini Flash | Ucuz ve hızlı; iş basit |
| Herkese açık veri (anahtar kelime niyeti, sorgu kümeleme) | Ücretsiz model (OpenRouter/Groq) | Haiku 4.5 | Müşteri verisi yok; ücretsiz katman yeter |

Opus veya Fable gerekmiyor.

**Beklenen AI maliyeti:** 20 site için haftalık plan, site başına 2–3 çağrı. Ayda 5–15 dolar civarı (tahmin; Faz 1'deki maliyet takibiyle ölçülecek).

## 5. Fazlar

Her faz tek başına işe yarar ve canlıda doğrulanır; sonraki faz ona göre ayarlanır. Boyut: S = birkaç gün, M = bir hafta, L = iki hafta.

### Faz 1 — Temel: maliyet kontrolü ve "Otomatik kur" (M)

1. AI maliyet takibi, bütçe sınırı, "müşteri verisi" işareti. OpenRouter ve Groq sağlayıcıları.
2. **Marka "Otomatik kur" asistanı** (tek tıkla onay modeli):
   - Algoritma: web sitesi varlığı; GSC ve İşletme Profili alan adıyla birebir eşleşme; GA4 veri akışı adresiyle eşleşme (ücretsiz ek Google sorgusu); Ads/Meta hesap adı benzerliği.
   - AI: hizmetleri çıkarma, katalogla eşleştirme, katalogda yoksa sektörüyle yeni hizmet önerisi.
   - Tek onay ekranı: her eşleşmenin gerekçesi ve güven puanı, "Hepsini onayla" veya tek tek düzelt.
   - Site henüz taranmadıysa hizmet adımı taramadan sonra kendiliğinden tamamlanır.
   - **Uygulama (kodlandı):** `/brands/{brand}/setup` ("Otomatik kur"). Öneri kuyruktaki işte hazırlanır (`BuildBrandSetupProposalJob`), `brand_setup_proposals` tablosunda saklanır. Onayı yalnızca admin verir; mevcut bağlama servisleri (ADR-018, harici yazma yok) kullanılır. Başka varlığa bağlı kaynaklar değiştirilmez. Hizmet adımı veri yoksa "site taranınca" durumunda kalır; onayla birlikte Public Discovery ve GSC bağlandıysa ilk SEO planı kuyruğa alınır.
3. SEO Görevleri'nin canlı veride doğrulanması ve eşiklerin ayarlanması.

### Faz 2 — Web: teknik, SEO, GEO/AEO derinleştirme (L)

Hepsi mevcut veriden, yeni ücretli kaynak yok:
- **İndeksleme:** Search Console URL incelemesi rastgele değil, trafik alan ve hizmet sayfalarına yönlendirilir. Dizinde olmayan önemli sayfa, sitemap hatası ve canonical'ın Google tarafından reddedilmesi öneri olur.
- **Sayfa budama kararı:** Son 90 günde gösterimi olmayan, zayıf ve başka bir sayfayla çakışan sayfalar için tek karar: koru, güncelle, birleştir, noindex ya da yönlendir. "Hangi sayfalar gereksiz" sorusunun cevabı bu.
- **İçerik çürümesi:** Tıklaması son 28 günde önceki döneme göre belirgin düşen sayfalar için "yenile" önerisi.
- **İç linkleme:** Link grafiğinden yetim hizmet sayfaları ve hizmet sayfasına link vermeyen ilgili yazılar.
- **Hız:** Yalnızca hizmet ve açılış sayfalarında Core Web Vitals. PageSpeed API ücretsiz.
- **GEO/AEO:**
  - Sayfa türüne uygun şema (Organization/LocalBusiness, Service, FAQ, hekim/uzman sayfaları için Person).
  - Site ve İşletme Profili arasında varlık tutarlılığı.
  - Hizmet sayfalarının başında 40–60 kelimelik net cevap bloğu.
  - Uzman ve yazar sayfaları (E-E-A-T).
  - AI tarayıcılarının erişimi.
  - Bilerek yapmıyoruz: llms.txt (kanıtlanmış bir etkisi yok) ve ücretli AI görünürlük takibi.

### Faz 3 — Google Ads danışmanı (L)

Toplanıp kullanılmayan veriden:
- **Negatif anahtar kelime listesi:** Harcayan ama dönüşüm getirmeyen arama terimleri. Mevcut negatiflerle çakışmayan, kopyala-yapıştır hazır liste.
- **Google'ın kendi önerileri süzülerek:** Yalnızca gerçekten değerli türler ("bütçeyi artır" dürtmeleri elenir) ve senin veri kanıtınla birlikte.
- **Bütçe kısıtlı ama kârlı kampanyalar** ve tersi, bütçe israfı.
- **Dönüşüm ölçümü sağlığı:** Ads dönüşümleri ile GA4 temel etkinlikleri arasındaki tutarsızlık; birincil/ikincil dönüşüm hataları.
- **Açılış sayfası:** Reklam grubu ile sayfa uyumu ve hız. Web verisiyle birleşir.
- **Eksik varlıklar:** Site bağlantıları, açıklama metinleri, arama snippet'leri.
- **Düşük kalite puanlı ama harcayan anahtar kelimeler.**
- **Değişiklik geçmişi ilişkilendirmesi:** "CPA, 12 Eylül'deki teklif değişikliğinden sonra %40 arttı."
- AI: reklam metni ve başlık varyantı taslakları, kopyala-yapıştır.

### Faz 4 — Meta Ads danışmanı (M)

- **Kreatif yorgunluğu:** Frekans yükselirken CTR düşüyorsa yeni kreatif gerekir.
- Öğrenmede takılan ad set'ler, bütçe/hedefleme darlığı.
- **Piksel ve dönüşüm kaynağı sağlığı.**
- Yerleşim ve saat bazında maliyet sapmaları.
- Değişiklik geçmişi ilişkilendirmesi.
- AI: kreatif brief'i ve metin varyantları.

### Faz 5 — İşletme Profili danışmanı (S)

Yorum cevaplama hariç:
- Profil eksikleri: kategoriler, hizmetler, özellikler, çalışma saatleri, açılış sayfası linkine UTM.
- **Aylık arama kelimeleri → web sitesi ve profil hizmetleri:** "İnsanlar sizi 'implant fiyatları' ile buluyor, profilde bu hizmet yok."
- Site ve profil hizmet listesi tutarlılığı.
- Fotoğraf ve gönderi tazeliği, yalnızca performans verisi değer gösteriyorsa. Sırf gönderi atmak için öneri yok.

### Faz 6 — Danışman ekranı ve kanal arası öneriler (M)

- **Marka başına tek ekran.** Her kanalda bir durum satırı ve en fazla 3–5 iş. Mevcut Bulgular / Öneriler / Görevler / SEO Görevleri bunun arkasında kalır; günlük akış tek yerde toplanır.
- **Haftalık özet:** "Bu hafta tüm müşterilerde en önemli 5 iş" (ana sayfa kutusu, isteğe bağlı e-posta).
- **Kanal arası öneriler:**
  - Ads'te dönüşüm getiren ama organik sayfası olmayan arama terimi → içerik önerisi.
  - Organikte zaten 1. olunan marka aramasına ödenen reklam → test önerisi.
  - İşletme Profili arama kelimeleri → site içeriği.
- **Aylık müşteri raporuna** "yapılanlar ve ölçülen etkisi" bölümü (rapor gönderimi altyapısı zaten var).

### Faz 7 — Karar gerektiren: harici yazma (ADR-018 değişikliği)

Tek kişilik bir ajansta en çok zamanı kurtaracak, riski düşük iki aday:
1. **WordPress'e taslak gönderme:** İçerik briefini veya meta düzeltmesini "taslak" olarak açmak. Asla yayınlamaz.
2. **Google Ads'e negatif anahtar kelime ekleme:** Onayladığın listeyi tek tıkla uygulamak.

Bu, proje kurallarında bilinçli bir değişiklik gerektiriyor. Ayrı bir karar ve ayrı bir ADR olarak ele alınır.

## 6. Para harcama politikası

- **Ücretsiz ve yeterli:** Search Console, GA4, Google Ads, Meta, İşletme Profili API'leri, PageSpeed API.
- **Kullandıkça öde, zaten var:** DataForSEO. Yalnızca rakip ve SERP analizinde, açıkça istendiğinde.
- **AI:** Ayda tahminen 5–15 dolar; bütçe sınırıyla korunur.
- **Yeni abonelik önerilmiyor.** Ahrefs/Semrush gibi yüksek aylık araçlar bu kapsam için gerekli değil; backlink analizi yol haritasında yok.

## 7. Bilerek yapmayacaklarımız

- Günlük sıralama takibi ve "SEO puanı" gibi vanity metrikler.
- Yorum cevaplama, sosyal medya paylaşımı, otomatik yayın.
- Kanıtsız "en iyi uygulama" listeleri (ör. "her sayfaya 2000 kelime yaz").
- Her hafta sırf liste dolsun diye görev üretmek.

## 8. Senin kararın gereken noktalar

1. Faz sırası uygun mu? (Öneri: 1 → 2 → 3 → 4 → 5 → 6; Faz 7 ayrı karar.)
2. Model varsayılanları uygun mu? (Sonnet 5 / Haiku 4.5 / ücretsiz model ayrımı.)
3. AI için aylık bütçe sınırı kaç dolar olsun? (Öneri: 25 dolar.)
4. Faz 7 (WordPress taslak ve Ads negatif ekleme) şimdilik kapsam dışı mı kalsın?
