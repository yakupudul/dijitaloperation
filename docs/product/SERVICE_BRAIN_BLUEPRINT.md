# Hizmet Beyni (Service Brain) — Tasarım Planı

**Durum:** UYGULANDI — Faz 1–6 kodlandı ve PHPUnit ile test edildi (ADR-072). Gerçek hesaplarla UAT yapılmadı; yasal kapı hukuk görüşüne kadar kapalı.
**Amaç:** Bir hizmet için (ör. implant) portföydeki tüm markaların verisinden "ne işe yarıyor" bilgisini çıkarmak ve bunu tek bir markanın web sayfalarına, Google Ads'ine, Meta kreatiflerine ve İşletme Profili'ne **doğru** öneri olarak indirmek.

---

## 1. Değerlendirme: fikir doğru mu?

**Evet, hizmet bazlı çalışmak doğru.** Birkaç düzeltmeyle:

- **Öğrenme birimi hizmet olacak, sektör olmayacak.** Doğru kohort şu: **hizmet × niyet × pazar tipi**.
  - Aynı hizmette bilgi sorgusu ("implant nedir") ile işlem sorgusu ("kadıköy implant") farklı sayfa ister ve farklı başarı gösterir.
  - Büyükşehir kliniği ile ilçe kliniği de doğrudan kıyaslanamaz.
- **Sektörün iki görevi kalıyor:** hizmet kataloğunu gruplamak ve **fren** olmak (uyum paketleri, yasaklı ifadeler, yasal kapı).
  - Senin tarif ettiğin gibi: sektör öğrenmez, sadece filtreler.
- **Sorgu alanı saçma değil, bu sistemin temeli.** Eksik olan iki şey var:
  1. **Kalıcı zincir yok.** Bugün sadece "sorgu → hizmet" ve "hizmet → sayfa" saklanıyor. Şu zincir kalıcı değil: hizmet → **konu kümesi** → **hedef URL** → **reklam grubu / nihai URL** → **Meta reklamı**. Hepsi çalışma anında tahmin ediliyor.
  2. **Başarı ölçülmüyor.** Mevcut Beyin (`RuleEffectiveness`) kural bazında ve sadece 2 kural ailesinin sonucunu ölçüyor: negatif kelime harcaması ve SEO sayfa tıklaması. Hizmet bazında hiçbir şey tutulmuyor.

**Temel ilke (senin önerin, aynen):** Karar ve yorum gereken yerde AI hazırlar. İnsan toplu olarak inceleyip onaylar. Sonrasını sistem deterministik yapar.

- İstatistik, kural uygulama, harici yazma ve ölçüm **asla AI'a bırakılmaz**.

---

## 2. Mimari — 7 katman

```
[Sorgular: GSC · Ads arama terimi · GBP · DataForSEO]
        │  K1 Atama (kural → vektör → AI → insan)
        ▼
[Hizmet] ──K2 Kümeleme──► [Konu kümesi = 1 sayfa] ──► hedef URL
        │                          │
        │                          ├─► Google Ads reklam grubu + nihai URL
        │                          ├─► Meta reklam/kreatif teması
        │                          └─► GBP hizmet / gönderi
        ▼
K3 Sayfa özellikleri   K4 Başarı ölçümü (gösterim→tıklama→ziyaret→dönüşüm)
        └──────────┬───────────┘
                   ▼
K5 Yöntem motoru (hipotez → doğrulanmış yöntem)
                   ▼
K6 Marka için öneri (boşluk = yöntem − mevcut durum)
                   ▼
K7 FREN: uyum paketi + yasal kapı (öneri görünmeden önce ve AI taslağından sonra)
```

### K1 — Sorgu → hizmet ataması (mevcut eşleme kelimelerini genişletir)

Bugün bu atama `service_matching_keywords` ve `SeoText::matchesPhrase` ile yapılıyor. Bu hızlı ve iyi bir temel, ama sadece kelime tutarsa çalışıyor. Önerilen 4 kademeli yapı:

1. **Kural (bugünkü):** Eşleme kelimesi, marka terimi, blok listesi. Yüksek güven, anında atanır.
2. **Vektör benzerliği (yeni):**
   - Her hizmetin bir "merkez vektörü" olur. Kaynağı: hizmet adı + açıklama + **onaylanmış örnek sorgular**.
   - Sorgunun vektörü en yakın hizmete gider.
   - **Güven ölçüsü:** 1. ve 2. en yakın hizmet arasındaki fark (margin). AI'ın "eminim" demesi güven sayılmaz; araştırmalar LLM'in kendi güven beyanının kalibre olmadığını gösteriyor.
3. **AI sınıflandırma:** Sadece marjı düşük olanlar için. Yapılandırılmış çıktı verir: hizmet, niyet, kısa gerekçe. Ucuz ve toplu (batch) çalışır.
4. **İnsan kuyruğu:** AI'ın da kararsız kaldığı sorgular için. **Toplu onay** ekranında incelenir.

- **Kendi kendini iyileştirme:**
  - Onaylanan her atama o hizmetin merkez vektörünü güçlendirir.
  - AI tekrar eden kalıplardan **yeni eşleme kelimesi önerir**; insan onaylarsa kurala eklenir.
  - Böylece kuyruk zamanla küçülür.
- Türkçe için mevcut `SeoText::fold` ve ek-toleranslı eşleşme kural katmanında kalır. Vektör modeli ekleri zaten büyük ölçüde soğurur.

### K2 — Hizmet içi konu kümelemesi (1 küme = 1 sayfa)

Senin tespitin doğru: bir sayfanın cevaplayabileceği sorgu sayısı sınırlı. Kümeleme 3 sinyalle yapılır:

| Sinyal | Ne söyler | Maliyet |
|---|---|---|
| **Vektör benzerliği** | Sorgular anlamca yakın mı | Çok ucuz |
| **Kendi GSC verimiz (sorgu × sayfa)** | Google iki sorguya **aynı URL'yi** mi gösteriyor | Bedava. Portföydeki tüm markalarda aynı hizmetin verisi var, bu bizim avantajımız |
| **SERP örtüşmesi (DataForSEO)** | İlk 10'da ortak URL sayısı | Ücretli. Sadece küme başı sorgu ve sınırda kalan çiftler için |

- **Karar eşikleri** (sektörde yaygın pratik):
  - İlk 10'da **7–10 ortak URL**: aynı sayfa.
  - **4–6 ortak URL**: aynı küme; ana sayfa + destek içerik.
  - **2–3 ortak URL**: ayrı sayfa, iç bağlantıyla bağlanır.
  - **0–1 ortak URL**: tamamen ayrı.
- **Algoritma:**
  1. Vektörlerle hiyerarşik/eşikli ön kümeleme.
  2. GSC ortak-URL ve SERP örtüşmesiyle bölme veya birleştirme.
  3. AI kümeye **ad ve niyet etiketi** önerir.
  4. İnsan onaylar. Mevcut `ManualQueryClusterService` ekranı ve geri alma altyapısı kullanılır.
- **Her kümenin tipi:**
  - **ana hizmet sayfası:** işlem + yerel niyet ("implant", "kadıköy implant")
  - **destek içerik:** bilgi niyeti ("implant ağrılı mı", "implant sonrası beslenme")
  - **SSS bloğu:** tek başına sayfa hak etmeyen kısa sorular; ana sayfanın içine girer
- **Hedef URL:** Her kümeye bir URL atanır ("küme sahibi sayfa"). Uygun sayfa yoksa "sayfa oluştur" önerisi çıkar.
- **Yamyamlaşma (cannibalization):** Aynı kümeye iki URL gösterim alıyorsa bayrak kalkar. Koşullar:
  - 2. sayfanın gösterim payı ≥ %25
  - iki sayfanın ortalama sırası ≤ 20
  - sıralar haftadan haftaya yer değiştiriyor
  - Öneri: birleştir, canonical ver veya içeriği ayır.

**Bu kümeleme üç işi birden çözer:**

- **Google Ads:** 1 küme = 1 reklam grubu = 1 nihai URL. Kalite puanının iki bileşeni (reklam alaka düzeyi ve açılış sayfası deneyimi) doğrudan bu uyuma bağlı.
  - Google'ın yeni AI Max / nihai URL genişletme özelliği eşleşmeyi **açılış sayfasının içeriğinden** yapıyor. Yani sayfanın küme odaklı olması artık hedeflemeyi de belirliyor.
- **Organik:** Her kümenin tek ve güçlü bir sahibi olur.
- **AI araçları:** AI Overviews ve ChatGPT bir soruyu alt sorulara bölerek ("fan-out") arıyor. Kümenin alt sorularını eksiksiz cevaplayan sayfa alıntılanıyor. AI Overview kaynaklarının sadece %38'i o sorguda ilk 10'da.

### K3 — Sayfa, reklam ve kreatif özellikleri (neyi karşılaştıracağız)

- **Deterministik özellikler.** Mevcut `PageContentMetrics` ve `website_page_profiles` genişletilir:
  - kelime sayısı, H1/H2 yapısı, SSS, şema tipleri, iç bağlantı sayısı
  - hız (CrUX), yerel NAP (ad-adres-telefon), fiyat/yer ifadesi
  - tazelik: son güncelleme tarihi
- **AI ile çıkarılan özellikler.** Sabit bir JSON şemasıyla, sadece sayfa içeriği değişince (hash) tekrar çalışır:
  - "cevap önce" giriş paragrafı var mı
  - soru biçimli H2'ler ve altında kendi başına anlaşılır cevap var mı
  - **küme alt sorgularının kaçı sayfada cevaplanmış** (kapsama oranı)
  - varlık kapsaması: işlem, aşamalar, süre, iyileşme, riskler, kimlere uygun
  - hekim yazar/kontrol eden ve tarih görünüyor mu (sağlıkta E-E-A-T)
  - kaynak/istatistik var mı
- **Google Ads (reklam grubu başına):**
  - kalite puanı ve bileşenleri (geçmiş tablosu var)
  - arama terimlerinin küme içi oranı
  - nihai URL'nin küme sahibi sayfa olup olmadığı
  - başlıklarda küme dili geçiyor mu
- **Meta (reklam başına):**
  - AI kreatifi **hizmete ve temaya** sınıflandırır (görsel/video, mesaj açısı)
  - mevcut hook, thruplay ve tamamlama oranları; sıklık; sonuç başı maliyet

### K4 — Başarı ölçüsü (senin tanımın: gösterim, ziyaret, tıklama, dönüşüm)

Ham sayılar markalar arasında kıyaslanamaz. İmplant için 3 milyonluk ilçe ile 80 binlik ilçe farklıdır. Bu yüzden **normalize** edilir:

| Aşama | Ölçü | Normalizasyon |
|---|---|---|
| Gösterim | **Talep payı** = küme gösterimi ÷ küme toplam talebi | Pazar büyüklüğünü düzler |
| Tıklama | **Beklenene göre CTR** = gerçek CTR ÷ o sıradaki beklenen CTR | Sıra etkisini ayırır |
| Ziyaret | Etkileşimli oturum oranı (GA4) | — |
| Dönüşüm | Dönüşüm oranı ve dönüşüm başı maliyet | Marka × hizmet düzeyinde |

- **Küçük örneklem problemi:** Az tıklamalı sayfanın oranı güvenilmez. **Bayes küçültme** (empirical Bayes) uygulanır: az veri olan sayfanın oranı hizmet ortalamasına doğru çekilir.
- **Karıştırıcılar kontrol edilir:**
  - şehir büyüklüğü
  - marka gücü (markalı sorgu payı)
  - reklam bütçesi
  - site yaşı/otoritesi
- Sonuç her küme sahibi sayfa için bir **başarı puanı**dır: kohort içindeki yüzdelik dilim. Kohort = hizmet × küme tipi × pazar tipi.

### K5 — Yöntem motoru ("başarılı olanlar ne yapıyor" → yöntem)

İki aşamalı. Bu ayrım "doğru öneri" isteğinin ta kendisi:

1. **Hipotez yöntem (gözlemsel).**
   - Aynı kohortta üst dilim sayfalar ile alt dilim sayfaların özellikleri karşılaştırılır.
   - Örnek: "İmplant ana sayfalarında başarılı olanların %80'inde hekim kontrol bilgisi ve 8+ SSS var, başarısızlarda %20."
   - **Asgari eşik:** en az 8 marka ve 30 sayfa. Altında yöntem üretilmez.
   - Öneride "gözlemsel" etiketiyle ve kanıtıyla gösterilir.
2. **Doğrulanmış yöntem (nedensel).**
   - Öneri uygulandıktan sonra sonuç, **uygulanmayan benzer sayfalarla** karşılaştırılarak ölçülür (fark-içinde-fark).
   - Mevcut 28/56 günlük ölçüm altyapısı genişletilir.
   - Klinik siteleri düşük trafikli olduğu için ölçüm **tüm markalarda aynı yöntem için havuzlanır**.
   - Tutarlı etki gösteren yöntem "doğrulanmış" olur ve öncelik kazanır. Etkisiz çıkan yöntem geri çekilir.

- Mevcut `RuleEffectiveness` bu motorun çekirdeği olur. Anahtar `rule_id`'den **`rule_id × hizmet × küme tipi`**'ne genişler.
- Ölçülen kural ailesi 2'den tüm kanallara çıkar:
  - Ads: reklam grubu kalite puanı ve dönüşüm başı maliyet
  - Meta: sonuç başı maliyet
  - GBP: işlemler
  - SEO: küme tıklaması

### K6 — Marka için öneri

- **Öneri = boşluk:** Yöntemin istediği ile markanın mevcut durumu arasındaki fark.
  - **Web:** küme sahibi sayfası yok → oluştur; kapsama %40 → şu 6 alt soruyu ekle; yamyamlaşma → birleştir; hekim bilgisi yok → ekle.
  - **Google Ads:**
    - reklam grubu birden fazla kümeye yayılmış → böl
    - nihai URL ana sayfa → küme sahibi sayfaya çevir
    - diğer kümelerin sorgularını negatif yap
    - başlıkları küme dilinden üret
  - **Meta:** bu hizmette portföyde en iyi hook oranını veren kreatif açısı X, sende yok → taslak.
  - **GBP:** hizmet listesinde küme adları eksik; bu kümede gönderi yok.
- Her öneri şunları taşır:
  - **kanıt:** kohort büyüklüğü, etki, gözlemsel/doğrulanmış
  - **uyum kontrol sonucu**
  - **beklenen etki**
- AI buradan sonra devreye girer: içerik taslağı, reklam metni, SSS cevabı. Uygulama mevcut onaylı yazma yollarıyla yapılır (ADR-064/068/070).
- **Veri gizliliği:** Başka markanın sayfa metni veya adı öneriye ve müşteri raporuna **asla** girmez; sadece desen girer. Bu ADR-066 ile uyumlu.

### K7 — Fren: uyum ve yasal kapı

- **Uyum paketleri** (Sağlık, Hukuk, Finans…) **deterministik** filtredir.
  - İki noktada çalışır: öneri oluşurken, ve AI taslağından **sonra** (bugün de öyle).
- **Eksik bulundu:** Google Ads ve Meta taslak üreticileri uyum kurallarını istemlerine **almıyor**, sadece sonradan kontrol ediliyor. Kurallar istemlere de girecek; böylece AI baştan uygun yazar.
- **Yasal kapı (önemli, doğrulanmalı):**
  - Araştırmada 12.11.2025 tarihli yeni *Sağlık Hizmetlerinde Tanıtım ve Bilgilendirme Faaliyetleri Hakkında Yönetmelik* bulundu.
  - İkincil kaynaklara göre Türkiye'deki kişilere yönelik **ücretli/sponsorlu sağlık tanıtımı yasak**.
  - İstisnalar: açılışın ilk ayı; sağlık turizmi yetki belgesi olan kuruluşların yabancı dilde, yurt dışına yönelik reklamı.
  - Arama motorunda kullanılan anahtar kelimeler de yönetmeliğe tabi.
  - Yasak ifadeler: en iyi / tek / garantili, fiyat ve kampanya, hasta yorumu. Önce/sonra görseli koşullu serbest, ama asla sponsorlu olamaz.
  - Birincil metne (Resmî Gazete) erişemedim. **Hukuk danışmanı onayı olmadan kural olarak kodlanmamalı.**
  - Onaylanırsa: sağlık markası için ücretli reklam önerileri bir **uygunluk kapısından** geçer: açılış tarihi, sağlık turizmi belgesi, hedef ülke, dil. Beyin yasa dışı bir pratiği "başarılı" diye öğrenip önermez.

---

## 3. "AI ile yap" — her sayfada tek kalıp

**Kalıp:**

1. "AI ile hazırla" düğmesine basılır.
2. Kuyrukta bir iş (async) başlar.
3. AI **öneri kayıtları** üretir. Doğrudan hiçbir şeyi değiştirmez.
4. **Toplu inceleme ekranı** açılır: tümünü seç, filtrele, onayla, reddet, düzenle.
5. Onaylananı sistem uygular. Geri alma mümkündür.

- Bugün bu kalıp sadece AI sorgu adaylarında ve site düzeltmelerinde var. Tek bir genel `ai_proposals` altyapısına çevrilir: tür, hedef, önerilen değer, gerekçe, güven, durum.

**İlk uygulanacak yerler** (yorucu işler):

| Sayfa | AI ne hazırlar |
|---|---|
| Veri kaynakları → hesap eşleme (senin örneğin) | Her GSC/Ads/GBP hesabı için sektör + hizmet eşlemesi. Kaynak: hesabın sorguları, site ve marka |
| Sorgu kütüphanesi | Atanmamış sorgular için hizmet ve niyet ataması; yeni eşleme kelimesi önerileri |
| Hizmet → kümeler | Küme önerisi, küme adları, hedef URL |
| Hizmet kataloğu | Eksik hizmet adları ve eş anlamlılar |
| Ads danışmanı | Kümeye göre reklam grubu yeniden yapısı; RSA başlıkları (uyum filtresinden geçmiş) |
| Meta | Kreatiflerin hizmet ve tema sınıflandırması |
| Danışman / SEO görevleri | **Toplu onay:** bugün tek tek yapılıyor |

---

## 4. Teknik kararlar

- **Vektör (embedding):**
  - `laravel/ai` üzerinden. Vektörler JSON olarak saklanır ve benzerlik PHP'de hesaplanır: portföy ölçeği on binlerce sorgu, yeterli.
  - İleride PostgreSQL `pgvector` kurulursa taşınır.
  - Maliyet çok düşük: milyon token başına birkaç sent.
- **AI yalnızca şuralarda:** sınıflandırma, etiketleme, sayfa özelliği çıkarımı, taslak.
  - Her biri bütçe (`AiBudget`) ve kullanım kaydından geçer.
  - Toplu çalışır ve içerik değişmedikçe tekrar çalışmaz.
- **Sistem (deterministik):** kümeleme matematiği, ölçüm, istatistik, uyum filtresi, yazma.
- **Kalıcı zincir için yeni tablolar:**
  - `service_clusters`: hizmet, tip, niyet, hedef URL, durum
  - `service_cluster_queries`
  - `service_cluster_ad_groups`
  - `service_meta_ads`
  - `service_page_features`
  - `service_success_snapshots`: aylık, kohort yüzdelikli
  - `service_methods`: hipotez/doğrulanmış, kanıt
- **Kullanılmayan eski tablolar:** `sector_learning_*` kaldırılır.
- **Eski AI kümeleme hattı** (`search_demand_clusters*`) yeni yapı gelince emekliye ayrılır.

---

## 5. Faz planı

| Faz | İçerik | Sonuç |
|---|---|---|
| **1** | Genel "AI ile hazırla → toplu onay" altyapısı. **Hesap eşlemesini AI önerir.** Sorgu → hizmet 4 kademeli atama. Eşleme kelimesi önerisi. Danışman/SEO toplu onay | Yorucu eşleme işleri AI'a geçer |
| **2** | Vektör + GSC ortak-URL + SERP ile küme önerisi. Küme tipi ve niyet. Hedef URL. Yamyamlaşma | Her hizmet URL boyutunda kümelere ayrılır |
| **3** | Zincirin kalıcı hâli: küme ↔ Ads reklam grubu/nihai URL; Meta kreatif ↔ hizmet. Ads yeniden yapı önerileri | Kalite puanı odaklı Ads stratejisi |
| **4** | Sayfa/reklam/kreatif özellik çıkarımı. Normalize başarı puanı. Kohortlar | "Kim başarılı, ne yapıyor" görünür |
| **5** | Yöntem motoru (hipotez → doğrulanmış). Tüm kanallarda sonuç ölçümü. Markaya boşluk önerileri | Standart değil, kanıtlı öneriler |
| **6** | Uyum kurallarının taslak istemlerine girmesi. Yasal uygunluk kapısı (hukuk onayından sonra) | Fren tamam |

**Dürüst uyarı:** Fazların sırası önemli; hipotez yöntemi için en az 8 marka ve 30 sayfalık kohort gerekir. Portföy küçükse Beyin önce **tek marka içinde** (kümeler, kapsama, yamyamlaşma, reklam grubu ↔ sayfa uyumu) değer üretir. Portföyler arası öğrenme veri biriktikçe açılır. Bu sorun değil: 1–3. fazlar kohort olmadan da tam değer verir.

---

## Kaynaklar (seçme)

- SERP örtüşmesiyle kümeleme: https://nightwatch.io/blog/keyword-clustering/
- Google kalite puanı: https://support.google.com/google-ads/answer/6167118
- Google AI Max ve nihai URL genişletme: https://support.google.com/google-ads/answer/16230205
- Google AI özellikleri ve site sahipleri: https://developers.google.com/search/docs/appearance/ai-features
- AI Overview alıntıları ile ilk 10 örtüşmesi: https://www.searchenginejournal.com/google-ai-overview-citations-from-top-ranking-pages-drop-sharply/568637/
- Şema işaretlemesinin AI alıntısına etkisi (neredeyse sıfır): https://ahrefs.com/blog/schema-ai-citations/
- GEO makalesi (istatistik, alıntı, kaynak etkisi): https://arxiv.org/pdf/2311.09735
- Bayes küçültme ile seyrek CTR tahmini: https://arxiv.org/abs/1809.02213v1
- CausalImpact ile müdahale etkisi ölçümü: https://google.github.io/CausalImpact/CausalImpact.html
- Sağlık Tanıtım Yönetmeliği, 12.11.2025 (doğrulanmalı): https://www.resmigazete.gov.tr/eskiler/2025/11/20251112-2.htm
