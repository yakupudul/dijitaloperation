# PRODUCT_CAPABILITY_LEDGER

## 2026-11-24 — Makale taslağı uyum düzeltmesi, Google URL denetimi, site haritası Search Console'dan

- **Makale yazıcı ("AI ile taslak yaz" / "Taslak hazırla"):** Girdi adım adım kurulur: plan (başlık, bölümler, sorular, açı, hedef sorgular), SEO analizi reçetesi, küme (sorgular, AI soruları, bölgeler, rakip iskeletleri), marka dosyası (profil, notlar, standartlar, ilgili sayfalar), iç bağlantı için site sayfaları, yasaklı ifadeler.
  - Yasaklı ifade içeren sorular ve bölümler AI'a gitmeden temizlenir («En iyi … seçerken» → «… seçerken»).
  - Yazar girdisi sadeleşti (prompt site-write-article-v7): yasaklı ifade temizliği tüm kopyalanan girdilere uygulanır (hedef sorgular, açı, reçete, küme sorguları, alt konular, ana sorgu); plandaki sorular küme AI sorularında tekrar gönderilmez; açıyla aynı gerekçe ve tekrarlayan bölüm başlıkları atılır; gizli sorgular gitmez, sorgular gösterime göre sıralanır; "AI ile üret" hedef URL'si temizlenmiş başlıktan; SEO analizi reçetesinin onaylı SEO başlığı ve açıklaması makalenin meta alanlarına aynen yazılır.
  - Metin sektör kuralına takılırsa AI ihlallerle (`fix`) bir kez yeniden yazar (prompt site-write-article-v7). Yine takılırsa taslak gönderilemez ama okunabilir; sorunlu ifadeler işaretli.
- **İçerik planı:** Satırda durum etiketi (Taslak hazır · Uyum kuralına takıldı · WordPress'e gönderildi).
  - İçerik fikirlerindeki "Taslağı oku" bağlantısı taslağı doğrudan açar (`taslak`).
  - Durum filtresi kendi URL anahtarını kullanır (`plan_durum`); İçerik fikirleri'nin `durum` anahtarı listeyi boşaltmaz.
- **Google URL denetimi (`moxdop:gsc:inspect-urls`, her gün 06:05; Teknik SEO › «Şimdi denetle»):** Her site için günde 25 sayfa Search Console URL Inspection API'ye gönderilir.
  - Sıra: hizmete bağlı hizmet sayfaları → diğer hizmet sayfaları → Google'da en çok görünenler → envanter. Aynı sayfa 14 gün sonra yeniden denetlenir.
  - Teknik SEO › Google'ın bildirdikleri ve dizin çubuğu bu veriyle dolar. Önceden hedef üreten parça bağlı değildi; veri hiç gelmiyordu.
- **Site haritası:** Ayarlar'da adres boşsa Search Console'a gönderilmiş site haritaları kullanılır ve hata / uyarı / son okuma ile listelenir. www / www'siz aynı alan sayılır.
- **Test:** ForbiddenTermsTest (temizleme, yeniden yazma, takılan taslak), SitePagesTest (URL denetimi, site haritası). Tam paket yeşil. UAT: yok.

## 2026-11-24 — İçerik fikirleri listesi sadeleşti

- Durum filtresi seçim kutusu yerine sayılı düğmeler (Tümü varsayılan). Sayfa başına 100 fikir.
- Durum etiketine tıklayınca satırın altında ayrıntı açılır: neden, içerik önerisi, çakışan sayfalar, eksikler, AI soruları.
- "Yeni fikir üret" satırları kalktı; her kümenin satırında "+ Fikir", sayı üstte seçilir.
- "AI ile üret" → "AI ile taslak yaz". Makale İçerik planı'na yazılır; WordPress'e yalnız «WordPress taslağı gönder» ile **taslak** olarak gider (ADR-064). "Butonlar ne yapar?" açıklaması eklendi.
- **Test:** Site testleri (SiteScreenFixesTest sayfa boyu 100).

## 2026-11-24 — Web sitesi ekranı v3: tek sıra sekme, ortak tarih seçici, Kümeler panosu, Analiz, Teknik SEO

- **Kabuk:** Tek satır başlık; tek sıra sekme: **Kümeler** (varsayılan) · **Analiz** · **Sayfalar** · **Sorgular** · **Teknik SEO** · **Yapılacaklar** · **Ayarlar**.
  - Seyrek kullanılanlar "Diğer" menüsünde: İçerik fikirleri (ayrıntılı liste), İçerik planı, Hedef sorgular, Dönüşümler, Eşleştirme, Rakipler, Backlinkler.
  - Eski sekme / alt sekme bağlantıları yeni sekmeye gider (`LEGACY_TABS`, `LEGACY_SUBS`).
- **Tarih seçici (Google Ads / Meta tarzı):** hazır aralıklar (7 / 14 / 28 gün, 3 / 6 / 12 ay, geçen ay), iki aylık takvim, özel aralık, önceki dönem ya da geçen yıl ile karşılaştırma.
  - `SiteRange` istek için bağlanır; Search Console / GA4 okuyan tüm sekmeler (Kümeler, Analiz, Sayfalar, Sorgular, Hedef sorgular, Dönüşümler) aynı dönemi gösterir.
  - URL: `donem`, `bas`, `bit`, `kars`.
- **Kümeler panosu (`ClustersBoardTab`):** kümeler hizmete göre gruplanır; önce ana hizmetler, en sonda "Hizmete bağlı olmayan kümeler".
  - Her hizmette önce ana küme (hizmet sayfası türünde, talebi en yüksek), sonra alt kümeler. 3 kart görünür, gerisi "Tüm alt kümeler (n daha)".
  - Kart: konu, Eşlendi / Eşlenmedi, sektör talebi, bizim gösterim ve sıra (seçili dönem).
  - Kart sekmeleri: İçerik fikirleri (ilki "Olmazsa olmaz"; tür, "Sitede: sayfa" + Geliştir ya da "Sitede yok" + Oluştur) ve Sorgular.
  - "+ Yeni fikir üret", Eşleştir, onay bekleyen kümeler. Filtreler: hizmet, durum, tür, ara.
  - Eylemler İçerik fikirleri ile aynıdır; AI yalnız tıklayınca, kuyrukta çalışır.
- **Analiz (`AnalyticsTab`):** 6 ölçü kartı (GSC: Tıklama, Gösterim, TO, Ortalama sıra; GA4: Oturum, Dönüşüm), değişim ve küçük çizgi grafik. Karta tıklayınca grafik metriği değişir.
  - Tek zaman ekseninde iki grafik (üstte GSC, altta GA4), karşılaştırma dönemi kesikli çizgi, üzerine gelince ipucu.
  - Huni: Gösterim → Tıklama → Organik oturum (GA4 google / organic) → Dönüşüm.
  - 4 bulgu: görünen ama tıklanmayan hizmet sayfaları, ilk 10'da olup tıklanmayan sorgular, ana sayfanın oturum payı, dönüşüm oranı.
  - Sayfa karnesi: GSC + GA4, kurallı teşhis, CSV indirme.
  - Tahmin yok: kaynak yoksa "—".
- **Sayfalar:** tür kartları (Hizmet, Hizmet kategorisi, Soru-cevap / blog, Kurumsal, Lokasyon, Anahtar kelime sayfası, Diğer).
  - "Tümü" her türün ilk sayfalarını ayrı bölümde gösterir. Bir tür seçilince tam tablo, filtreler ve sayfa detay çekmecesi gelir.
- **Teknik SEO (`TechnicalSeoTab` + `TechnicalSeoReader`):** kutucuklar Kritik / Uyarı / Bilgi / Google doğruluyor (son 28 günde uygulanan düzeltme).
  - Google dizin çubuğu, kaynak ve önem filtreleri.
  - İki liste: "Google'ın bildirdikleri" (URL denetimi kapsam durumu, canonical uyuşmazlığı, site haritası hatası) ve "Sitenin HTML'inde bulduklarımız" (son tarama sorunları, aynı başlıklı sayfalar).
  - Her satırda neden, tek eylem ve kimin düzelteceği (WordPress / geliştirici / Google). Çekmecede ne oluyor, nasıl düzeltilir ve etkilenen sayfalar.
  - Site sağlığı aynı sekmenin altında.
- **Test:** ClustersBoardTabTest; SitePagesTest (tarih aralığı, Analiz, sayfa türleri, Teknik SEO, sekmeler / eski bağlantılar); SiteSuggestionsTest.
- **Eksik:** UAT yok; görsel kabul operatörde.

## 2026-11-23 — OpenAI ücretsiz paylaşım kotası + kota denetimi

- **Kota:** OpenAI › Veri kontrolleri › «Giriş ve çıkışları OpenAI ile paylaşın» açıkken paylaşılan trafiğin bir kısmı ücretsizdir (UTC günü): küçük modellerde (gpt-5-mini vb.) 2,5 M, büyüklerde (gpt-5 vb.) 250 k token.
  - Ayarlar › AI işlemleri › AI bütçesi'nde "OpenAI ücretsiz paylaşım kotası açık" işaretlenince bu tokenlerin %90'ı harcama sayılmaz; aşan kısım liste fiyatından sayılır.
  - Her çağrıda liste fiyatı ve kotadan karşılanan token ayrıca saklanır. Sayfada bugünkü kota kullanımı ve kazanılan tutar görünür.
- **Önce ücretsiz kota, sonra 1 $:** Bir OpenAI modelinin bugün ücretsiz tokeni kaldıysa çağrı, günlük ücretli tavan dolmuş olsa da çalışır; kota bitince günlük tavan geçerlidir. Kotaya yalnız paylaşım açıkken yapılan çağrılar sayılır.
- **Kota denetimi (saatte bir, `moxdop:ai:openai-audit`, «Şimdi denetle"):** OpenAI Admin anahtarıyla OpenAI'ın gerçek faturasını (Costs API) bizim tahminimizle karşılaştırır.
  - Dün OpenAI tahminden belirgin fazla faturaladıysa (×1,25 + 0,05 $), kota hesabı kendiliğinden kapanır ve sayfada uyarı çıkar.
  - Bugünkü gerçek fatura günlük tavan için alt sınırdır.
  - Anahtar şifreli saklanır; ekranda gösterilmez.
- **Sınır:** Costs API kuruluşun tüm OpenAI harcamasını verir. Aynı hesapta başka uygulama varsa denetim fazla görüp kotayı kapatabilir (güvenli taraf).
- **Test:** OpenAiFreeQuotaTest (kota hesabı, denetim uyumsuzluğu / uyumu, anahtar yok, ayar ekranı). UAT: yok.

## 2026-11-22 — Günlük 1 $ tavan (tüm AI) ve otomatik AI yalnız Sorgular'da

- **Sorun:** Bir günde 27 $ harcandı. Önceki tavan yalnız "kimse tıklamadan" çalışan işleri sayıyordu. Bir operatör tıklamasından zincirlenen işler (kümeleme → site akışı → Eşleştir) "tıklanmış" sayıldığı için tavana takılmıyordu.
- **Günlük tavan artık tüm AI'ı kapsar (varsayılan 1 $, İstanbul günü):** tıklananlar dahil. Tavan dolunca ücretli hiçbir AI çağrısı başlamaz; yarın yeniden çalışır.
  - Ayarlar › AI işlemleri › "Günlük AI tavanı, tüm işler" ile değişir (0 = tavan yok).
- **Kontrol AI çağrısının başladığı tek yerde de yapılır:** her ajan çağrısı tavanı ve izni yeniden kontrol eder; aşılmışsa çağrı hiç başlamaz, para harcanmaz.
- **Otomatik çalışan alan yalnız Sorgular:** sorgu pilotu ve kümeleme.
  - Site akışı (gece, kümeleme ve kurulum sonrası), haftalık site yenileme, kanal analistleri, bakım ajanı ve Şef kendiliğinden çalışmaz; zamanlanmış işleri atlanır.
  - Site akışı İçerik fikirleri › "Akışı ilerlet" veya "Eşleştir" ile çalışır.
  - `.env`: `AI_AUTOMATIC_AREAS=queries` (virgüllü liste; `*` = hepsi).
- **Test:** AiCostControlTest (tüm AI'ı kapsayan tavan, çağrı anında engel, yalnız Sorgular otomatik), SiteFlowTest (akış tıklama bekler). UAT: yok.

## 2026-11-21 — Eşleştir yığılması: parçalı çalışma, zaman aşımında satır kapanır, hatada döngü yok

- **Sorun:** AI işleri'nde aynı site için onlarca "Site · Eşleştir" satırı saatlerce "Çalışıyor" kaldı, maliyet "—".
  - Büyük sitelerde Eşleştir 14 dakikalık iş süresini aşıyordu. İşçi işi öldürünce satır kapanmıyordu (3 saatlik süpürmeye kadar açık kalıyordu).
  - İş "hata" durumuna düşünce bir sonraki tetik (kümeleme onayı, gece akışı) Eşleştir'i baştan başlatıyordu. Aynı AI çağrılarına tekrar tekrar para ödeniyordu.
- **Parçalı çalışma:** Eşleştir 7 dakikadan sonra yeni AI çağrısı başlatmaz ve kaldığı yeri hatırlar: eşleşen hizmet grupları, okunan sayfalar, fikir grupları.
  - Sonraki parça kendiliğinden kuyruğa girer ve aynı adımı tekrar ödemez. Bir geçişte en çok 30 parça çalışır.
  - Bir sitede aynı anda yalnız bir Eşleştir çalışır. "Eşleştir" düğmesi çalışan varken yenisini başlatmaz.
- **Zaman aşımı:** İşçinin öldürdüğü iş anında "Hata · Zaman aşımı" olur. Satır, o ana kadarki çağrıların maliyetiyle kapanır.
- **Hata sonrası bekleme:** Eşleştir hata verince (AI hatası, sağlayıcı yok / günlük tavan, zaman aşımı) akış 6 saat kendiliğinden yeniden başlatmaz. Operatör "Eşleştir" ile hemen başlatabilir; sonra açık geçiş kaldığı yerden sürer.
- **AI soruları:** AI'ın soru vermediği küme her çalışmada yeniden sorulmaz.
- **Temizlik:** `moxdop:ai:close-stuck --minutes=30` eski takılı satırları kapatır.
- **Test:** SiteFlowTest (parçalar, hiçbir adım iki kez ödenmez, zaman aşımı sonrası bekleme), AiJobsTest (zaman aşımında satır kapanır, komut). UAT: yok.

## 2026-11-20 — AI harcaması: otomatik işlere günlük tavan, ucuz model, harcama dökümü

- **Sorun:** 14 saatte 12 $. Otomatik işlerin çoğu en pahalı modelde (Sonnet 5) çalışıyordu ve bir üst sınırları yoktu.
- **Otomatik işler günlük tavanı (varsayılan 1 $):** kimse tıklamadan çalışan AI son 24 saatte tavana ulaşınca durur. Ücretsiz modeller ve operatörün tıkladığı işler çalışmaya devam eder.
  - Otomatik işler: gece site akışı, sorgu pilotu, kümeleme, analistler, bakım ajanı, Şef.
  - Ayarlar › AI işlemleri › AI bütçesi'nden değişir (0 = tavan yok); `.env`'de `AI_DAILY_AUTO_BUDGET_USD` ile de ayarlanabilir.
- **Asıl neden (yalnız OpenAI bağlı):** tüm işler `gpt-5-mini`'de çalışıyordu. Bu model ayar verilmeyince her çağrıda orta düzeyde gizli "düşünme" yapar; düşünme tokenleri çıktı fiyatından ($2/M) faturalanır.
  - Artık tüm ajanlar GPT-5 / o-serisi modellerde düşük düşünme seviyesiyle çağrılır (`OPENAI_REASONING_EFFORT=low`; boş bırakılırsa modelin varsayılanı).
  - Düşünmeyen modellere (gpt-4.1, gpt-4o) bu ayar gönderilmez.
- **Anthropic bağlanırsa:** küme ↔ sayfa eşleştirme, küme eksikleri, AI küme yargısı ve küme gözden geçirme Haiku 4.5'te çalışır. Kümeleme Sonnet'te kalır. Yalnız OpenAI bağlıyken bunun etkisi yoktur.
- **Harcama nereye gitti:** AI işlemleri sayfasında 24 saat / 7 gün için işlem bazında çağrı, maliyet, pay, otomatik kısmı ve model. Komut: `moxdop:ai:costs --hours=24`.
- **Test:** AiCostControlTest (günlük tavan, döküm, komut), AiLiveOperationsTest. UAT: yok.

## 2026-11-19 (d) — AI işleri biter: aynı iş için tekrar para ödenmez, kararlar kurala dönüşür

- **Sonsuz döngü / tekrar ödeme kapatıldı:**
  - **Hizmet ↔ sayfa:** AI'ın bir sayfa için verdiği karar (hizmet ya da "hizmet yok") saklanır; haftalık yenileme aynı sayfayı yeniden sormaz. Markanın hizmet listesi değişince bu sayfalar bir kez daha sorulur.
  - **Küme ↔ sayfa:** kural geçişi AI'ın eşleştirmesini artık ezmiyor (önceden ezip haftalık yeniden AI'a yaptırabiliyordu). AI kararı bir sonraki Eşleştir'e kadar kural gibi durur.
  - **Site akışı:** sayfa ya da kategori değişikliğinde Eşleştir hemen çalışır. Yalnız sayfa metni değişirse (her taramada değişen tarih vb.) en çok haftada bir çalışır.
  - **Kanal analistleri:** haftalık çalışma, veri geçen analizden beri değişmediyse AI çağırmaz. "Yeniden analiz et" her zaman çalışır.
  - **Sorgu otomatik pilotu:** AI üst üste 3 kez hata verirse 2 saat bekler; aynı sorgular her 15 dakikada yeniden gönderilmez.
- **Öğrenilen kurallar (sonraki işler AI'sız):**
  - **Klasör kuralı:** sitede en az 5 sayfası sınıflanmış ve %90'ı aynı kategoride olan klasörün yeni sayfaları o kategoriyi AI'sız alır ("hizmet" hariç).
  - **Konu kuralı:** bir kümenin zaten tuttuğu konudan yeni bir sorgu gelirse o kümeye AI'sız girer. Kümeleme sonucu "N sorgu kuralla yerleşti (AI'sız)" yazar.
  - Önceden var olanlar: sorgu pilotu yeni eşleme kelimeleri ve filtre terimleri öğrenir; sınıflandırma kararları ve küme yerleşimleri bir kez verilir.
- **Test:** AiNoLoopTest, SiteFlowTest. Analist "veri değişmedi" atlaması için ayrı test yok. UAT: yok.

## 2026-11-19 (c) — AI işlemleri sayfası: AI şu an nerede ne yapıyor

- **Ayarlar › AI işlemleri** yeniden düzenlendi. Çalışan iş varken her 5 sn, yokken her 30 sn kendiliğinden yenilenir.
  - **Özet:** şu an çalışan, sırada bekleyen, bugün biten (hata sayısıyla), maliyet (bugün / bu ay, kalan bakiye).
  - **Canlı:** çalışan her iş bir kart:
    - ne yapıyor;
    - nerede (marka › varlık; sayfasına "aç" bağlantısı);
    - hangi adımda (işin içindeki çalışan AI çağrısı, biten çağrı sayısı, model);
    - kim başlattı (kişi ya da "Otomatik");
    - geçen süre, şimdiye kadarki maliyet;
    - "Ayrıntı" ve "Durdur".
  - **Sırada:** bekleyen işler ("Kaldır").
  - **Son 24 saat:** bitenler; durum, marka, süre, maliyet, hata nedeni.
  - **Otomatik takvim:** kimse tıklamadan AI çalıştıran işler ve sonraki çalışma saati, gerçek zamanlamadan okunur.
    - Sorgu otomatik pilotu, site akışı, site haftalık yenileme, kanal analistleri, bakım ajanı, Şef.
  - Bütçe ve "Promptlar ve modeller" listesi açılır bölümlere alındı. Prompt listesi Türkçe adlarla ve en çok çalışan önce gösterilir.
- **Test:** AiLiveOperationsTest. UAT: yok.

## 2026-11-19 (b) — Şef denetimi: AI'ın marka için yaptıklarında hata arar

- **Ne yapar:** AI'ın marka için verdiği kararları markanın kendi verisiyle kurallarla karşılaştırır (AI çağrısı yok). Yalnız hata (çelişki) arar, iyileştirme işi üretmez. Kontroller:
  - AI'ın hizmet sayfası saydığı toplu/arşiv sayfaları (/kws/, makale klasörleri);
  - AI'ın sayfayı, adı başka bir hizmetin adını taşırken bambaşka bir hizmete bağlaması;
  - AI'ın kümeyi markanın başka bir hizmetine ait sayfayla eşleştirmesi;
  - artık olmayan sayfa ya da kümeye ait açık işler.
- **Düzelt:** yalnız yanlış AI kararını geri alır; yeni AI işi başlatmaz:
  - sınıflandırma kurallarla yeniden yapılır;
  - sayfa adındaki hizmete sabitlenir;
  - küme eşleşmesi kaldırılır;
  - iş kapatılır.
  - Eşleştirme artık başka hizmetin sayfasını aday almaz; aynı hata yeniden oluşmaz.
- **Döngü yok:** "Doğru, bırak" listelenen kayıtları bir daha hata saymaz. Aynı türden yeni bir hata çıkarsa bulgu yalnız yeni kayıtla açılır.
- **Ne zaman:** her pazartesi Şef planından önce çalışır. Açık hatalar Şef'in planına girer (brand-chief-v2).
  - Marka dosyası sekmesinde "Şef denetimi" bölümü ve "Şimdi denetle" var. Komut: `moxdop:brands:audit {brand?}`.
- **Test:** BrandAuditTest. UAT: yok.

## 2026-11-19 — Site akışı: WordPress bağlıysa kendi kendine akar

- **Akış (SiteFlow):** markanın sitesinde WordPress eklentisi eşleşmişse zincir kendiliğinden ilerler:
  1. sayfalar;
  2. sayfa sınıflandırma ve hizmet ↔ sayfa (kurallar önce, kalanı AI);
  3. küme ↔ sayfa ("Eşleştir": sayfa, kapsam, eksikler, aynı ihtiyacı karşılayan diğer sayfalar).
  - Her gece (moxdop:brands:dossier), site hazırlığı bitince ve kümeleme yeni küme onaylayınca bir sonraki adım başlar. Aynı anda tek adım çalışır.
  - Küme ↔ sayfa yalnız girdileri değişince yeniden çalışır: onaylı kümeler, sayfa içerikleri / kategorileri, ya da hiç okunmamış satır. Her gece AI çalışmaz.
  - Eklenti bağlı değilse AI adımı çalışmaz; Eksikler'de "WordPress eklentisi bağlı değil" görünür.
  - Web sitesi › Sorgular › İçerik fikirleri'nin üstünde "Site akışı" kartı adımları ve sayıları gösterir; "Akışı şimdi ilerlet" düğmesi var.
- **Bir küme birden fazla sayfayla eşleşirse:** eşleştirme AI'ı (site-cluster-match-v3) aynı ihtiyacı işleyen diğer sayfaları da söyler; Search Console'da kümenin gösterimlerinin en az %25'ini alan sayfalar da eklenir.
  - Satırda "Bu kümeyle eşleşen diğer sayfalar" listelenir; her biri İş listesine öneri olarak düşer (site.cluster_overlap).
  - Az trafik alan ve başka kümenin hedefi olmayan sayfa için öneri "301 ile birleştir" (onaylı WordPress yönlendirmesi, ADR-070, geri alınabilir).
  - Trafik alan ya da başka kümenin hedefi olan sayfa için öneri "Ayrıştır". "Ayrı kalsın" ile kapatılır.
  - Çakışma kalkınca açık öneri kendiliğinden kapanır.
- **Eşleşmeyen küme:** satırda kümenin kendisinden "İçerik önerisi" görünür (sayfa tipi, ad, kullanıcı ihtiyacı, bölümler, hedef sorgu; uydurma yok).
  - "AI ile üret" Eşleştir sayfa bulamayınca hemen açılır; "Yeni fikir üret" her kümede var.
- **Hizmet bölgeleri:** markanın bölgesi yoksa sitenin hizmet / lokasyon / kurumsal sayfa başlıklarındaki il-ilçe adları bulunur (en az 2 sayfada geçen, AI'sız, şablon bölümler hariç).
  - Eksikler'de "Sitede N hizmet bölgesi bulundu"; onaylanınca eklenir.
- **Test:** SiteFlowTest, ClusterAuditTest, SiteUpkeepTest güncellendi. UAT: yok.

## 2026-11-18 (d) — Kümeleme: otomatik, sınırsız, uydurmasız

- **Otomatik:** sorgu otomatik pilotu kümelemeyi günde bir kez başlatır. AI'ın sorgu sınıflandırması bitmemiş olsa da başlar; sürekli sorgu akışı kümelemeyi artık bekletmez.
- **Otomatik onay:** biten kümeleme çalışması sağlam kümelerini kendisi onaylar (kilitlemez; operatör düzenleyebilir). Sağlam küme:
  - net bir ihtiyacı var, "diğer / çeşitli / genel" değil;
  - sayfa tipi "diğer" değil;
  - en az bir gerçek (toplanmış) sorgusu var.
  - Onaylanan kümeler hizmeti alan aktif markaların hedeflerine ve sitelerindeki küme satırlarına hemen iner (kurallarla, AI yok). Geçmeyenler İçerik fikirleri'nde "Onay bekleyen kümeler"de kalır.
- **Uydurma yok:** AI'ın "eklediği" sorgular artık kaydedilmez. Kümeler yalnız toplanmış aramalardan oluşur (talimat queries-cluster-v6).
  - "Diğer sorular" gibi her şeyi toplayan kümeler reddedilir; konuları bir sonraki parçada yeniden sorulur.
  - Gözden geçirme (queries-cluster-review-v2) farklı sayfa tiplerini (hizmet sayfası ile rehber) birleştiremez, onaylı ya da kilitli kümeyi silemez, adı "genel" gibi bir adla değiştiremez.
  - Tam yeniden kümeleme onaylı kümeleri silmez.
- **Sınır yok:** AI yanıtındaki küme, birleştirme ve güncelleme sayılarında üst sınır kaldırıldı; geçerli her satır kaydedilir.
- **Test:** QueriesScreenTest (catch-all / sayfa tipi / onaylı küme), QueryAutopilotTest (triage sürerken kümeleme). UAT: yok.

## 2026-11-18 (c) — Şablon sayfalar hizmet sayılmaz, gece bakımı AI sınırı, onay bekleyen kümeler

- **Sorun (Bornova Hurda):** 5038 sayfadan 620'si "Ana hizmet sayfaları" görünüyordu. Bunlar "/hurda/…" makaleleri ve "/kws/izmir-…-mahallesi-hurdaci" anahtar kelime sayfalarıydı. Nedenleri:
  - AI sınıflandırması bunları "hizmet" saydı.
  - Lokasyon sayfaları hizmete bağlanınca "ana" sayıldı.
- **Kural:**
  - Hizmet bölümü dışında 40+ sayfalık bir klasör şablon bölümdür: yer adı geçiyorsa lokasyon, değilse blog. Hiçbir zaman hizmet değildir.
  - /kws/, /tag/, /etiket/, /kategori/ … her zaman "diğer"dir.
  - Bu sayfalar hizmete bağlanmaz. Eski otomatik bağları silinir (kilitli bağlar kalır).
  - "Ana hizmet sayfası" yalnız kategorisi "hizmet" olan sayfadır.
  - Lokasyon sayfaları hizmete yalnız ad kuralıyla bağlanır (AI yok).
  - Otomatik kur ve "Eksikler" şablon sayfalardan hizmet önermez.
  - AI sınıflandırma talimatı site-page-categories-v2: "hizmet"te katı.
- **Gece bakımı:** kurallarla karar verilemeyen sayfa sayısı 200'ü aşarsa AI'a gönderilmez. Eksikler'de "N sayfa sınıflanmadı" görünür; onaylanınca AI çalışır.
- **Temizlik komutu:** `moxdop:site:recategorize {site?}` kuralları yeniden uygular ve bayat hizmet bağlarını siler. AI kullanmaz.
- **AI göstergesi:** "site.setup" artık "Site hazırlığı (sayfa sınıflandırma, hizmet ↔ sayfa)" olarak görünür. Diğer site işleri de Türkçe adıyla görünür.
- **Onay bekleyen kümeler:** markanın hizmetlerinin onaylanmamış kümeleri Web sitesi › Sorgular › İçerik fikirleri'nde listelenir.
  - "Onayla" ve "Tümünü onayla" var. Onaylanan küme satırı hemen gelir (kurallarla, AI yok).
  - Kütüphanede hazır olma uyarısı da buraya yönlendirir.
- **Test:** PageTemplateSectionsTest. UAT: yok.

## 2026-11-18 (b) — "Doğru çalışamıyorsa söyle ya da dur", hizmet sınırı yok, Hata merkezi düzeltmeleri

- **İlke (operatör kararı):** bir AI / sistem işi bir eksik ya da bozukluk yüzünden doğru çalışamayacaksa önce uyarır ve durur; operatör "Yine de getir" derse çalışır.
  - Otomatik kur: site varlığı yoksa ya da sayfaları hiç toplanmadıysa tarama başlamaz, uyarı + "Yine de getir" / "Vazgeç".
  - Bakım ajanı: site ya da hizmet yoksa haftalık inceleme çalışmaz (AI çağrısı yok), nedeni sekmede görünür; "Şimdi incele" yine çalıştırır.
- **Otomatik kur hizmetleri:** sayı sınırı yok (prompt brand-setup-v6); tüm hizmet sayfaları AI'a gider. Uydurma yok: sitede, WordPress sayfalarında, taramada ya da Search Console sorgularında karşılığı olmayan hizmet işaretsiz ve "Kanıt yok" notuyla gelir.
- **Hata merkezi:**
  - Canlı doğrulamada kapalı / yetkisiz çıkan hesap (Meta DISABLED/CLOSED/UNSETTLED, Google Ads PERMISSION_DENIED) "Senin işin" kovasına düşer, açıklamaya canlı doğrulama sonucu eklenir.
  - Google Ads "CUSTOMER_NOT_ENABLED / authorization failed" artık yetki hatası (eskiden "beklenmeyen hata, sistem yeniden deniyor").
  - Çok hesaplı "verisi güncel değil" uyarısı tek bir hesabın yazılım hatası yüzünden "Yazılım hatası" sayılmaz; markaya bağlı olmayan hesaplar bu uyarıya girmez.
  - Bir günden uzun süredir sinyal vermeyen eski işçi adları işçi listesinde gösterilmez.
- **Uygulama hataları:** AI göstergesindeki "$" özellik hatası, otomatik pilot işinin eski kuyruk kaydı hatası, site projeksiyonu kilit beklemesi (LockTimeout / MaxAttempts), sorgu toplama kilit beklemesi, WordPress JSON olmayan yanıt hatası düzeltildi.
- **Test:** BrandCareTest, BrandSetupAssistantTest, ErrorCenterTest güncellendi. UAT: yok.

## 2026-11-18 — Eksikler, site bakımı, Otomatik kur kapsamı, çakışma kuralı

- **Eksikler** (`App\Services\Brand\BrandGaps`, karar `brand.gap`): AI işini tıkayan şeyler kuralla (AI'sız) bulunur: site yok, sektör yok, bölge yok, hizmet yok, markanın hizmetlerinde olmayan hizmet sayfaları, hiçbir sayfayla eşleşmemiş hizmetler. Her gece ve Marka dosyası sekmesi açılınca iş listesine yazılır; giderilen eksik kendiliğinden kapanır. Düzeltmesi olanlar yalnız "Onayla ve yap" ile çalışır (hizmetleri ekle + sayfalarla eşle; siteyi hazırla). Hepsi MoxDOP içi; dışarıya yazma yok.
- **Site bakımı:** gece komutu, kategorilenmemiş yeni sayfa varsa (sayı değiştiyse) ya da hizmet sayfaları hiçbir hizmetle eşleşmemişse (haftada en çok bir kez) sitenin kurulum işini yeniden başlatır. Neden: Otomatik kur'dan sonra toplanan sayfalar hiç kategorilenmiyordu; marka dosyası "0 hizmet sayfası / sayfası eşleşmedi" gösteriyordu.
- **Marka dosyası** hizmet sayfası sayımı web sitesi ekranıyla aynı kural (kategori "hizmet" ya da hizmet bölümü altında).
- **Otomatik kur:** hizmet sınırı 20 → 60; AI'a önce hizmet bölümündeki sayfalar gider (200 sayfa); AI'ın atladığı her hizmet sayfası işaretli öneri olarak eklenir (prompt brand-setup-v5).
- **Sorgu taraması:** iki hizmetin kelimesi eşleşen sorgu zaten bu hizmetlerden birindeyse ataması korunur (eskiden kaldırılması öneriliyordu). Kalan satırlarda "Yeni" sütunu "Hizmetsiz" yazar ve nedeni açıklar. Kelime etki önizlemesi aynı kuralı kullanır.
- **Test:** `tests/Feature/Brand/SiteUpkeepTest.php`, `KeywordInsightsTest` güncellendi (SQLite + PostgreSQL). UAT: yok.

## 2026-11-17 (d) — Marka bakım ajanı + Şef

- **Marka bakım ajanı** (`brand.care`, `App\Services\Brand\BrandCare`): her pazar 21:13, yalnız aktif markalar (`moxdop:brands:care`, arka plan kuyruğu). Marka dosyasını, son incelemeden beri değişen bölümleri ve kendi önceki işlerini okur; ham veriyi okumaz. Dosya değişmediyse (ve son tam bakış 28 günden yeni ise) AI çağrısı yok.
- **Çıktı:** kısa not + en çok 5 iş (tek iş listesi `suggestions`, karar `brand.care`, hedef marka) + en çok 3 soru. Açık işleri tekrar etmez; operatörün kapattığı/yaptığı işi geri açmaz; artık önermediği açık işi kapatır. Dışarıya yazmaz.
- **Şef** (`brand.chief`, `App\Services\Brand\BrandChief`): her pazartesi 07:41 bakım notlarından tek haftalık plan (en çok 10 satır, marka başına en çok 3), `chief_plans` tablosu, tek bildirim. Bugün ekranında "Bu haftanın planı" + "Planı yenile".
- **Ekran:** Marka › Marka dosyası sekmesinde "Bakım ajanı" bölümü (not, işler, sorular, "Şimdi incele").
- **Test:** `tests/Feature/Brand/BrandCareTest.php` (SQLite + PostgreSQL; AI sahte). UAT: yok; gerçek sağlayıcıyla ilk çalışma pazar gecesi.

## 2026-11-17 (c) — Marka dosyası

- **Ne:** Her marka için tek kısa markdown dosya (`App\Services\Brand\BrandDossier`): Kimlik, Bağlı varlıklar, Hizmetler, Talep, Web sitesi durumu, Kararlar ve sonuçları, Açık işler, Operatörün notları. AI kullanmadan verilerden derlenir; `brand_memory` (kind `dossier`) satırında saklanır.
- **Delta okuma:** Her bölümün hash'i tutulur; `changedSince()` bir ajanın son okumasından beri değişen bölümleri verir. Değişiklik yoksa ajan hiçbir şey okumaz.
- **Ne zaman yenilenir:** Her gece 04:37 (yalnız aktif markalar, `moxdop:brands:dossier`), Otomatik kur kurulum işi bitince, sekmedeki "Yenile" ve not kaydında.
- **Ekran:** Marka › "Marka dosyası" sekmesi: dosya + Hedefler / Kısıtlar (Ayarlar'daki notlarla aynı kayıt).
- **Test:** `tests/Feature/Brand/BrandDossierTest.php` (SQLite + PostgreSQL). UAT: yok.

## 2026-11-17 (b) — Hata merkezi

- **Üç kova (`ErrorTriage`, kural, AI yok):** açık sistem uyarıları "Senin işin" (yeniden bağlan, yetki ver, eşleştirme düzelt, işçi durdu), "Yazılım hatası — geliştiriciye ilet" (sözleşme / istek hatası) ve "Sistem hallediyor" (sağlayıcı geçici hatası, kota, hız sınırı, takılan çekim, kuyruk birikmesi) olarak ayrılır; aynı nedenden gelenler tek grup. "Sistem hallediyor" 48 saatte düzelmezse "Senin işin"e yükselir ("2 günde düzelmedi").
- **Zil:** kendiliğinden düzelen uyarılar bildirim açmaz; her sabah 08:52'de (05:52 UTC, `moxdop:ops:error-digest`) yalnız senin işin / yazılım hatası varsa tek bildirim gelir. "Sistem kendiliğinden yeniden denemez" çelişkisi düzeltildi (günlük yeniden deneme anlatılır).
- **Ekran:** Ayarlar › Sistem Sağlığı artık "Hata merkezi": üstte kovalar (eylem butonları ve bağlantılarla), teknik ayrıntılar katlanmış. Bugün ekranındaki satır yalnız senin işin + yazılım hatasını sayar.
- **State:** CODED + PHPUnit (`ErrorCenterTest`, `OperatorAlertClarityTest`, `IntegrationE2E3Test`). Üretim UAT yok.

## 2026-11-17 — Markaya bağlı olmayan hesaplar gizli

- **Karar (operatör):** markaya bağlanmamış hesap sistemde bir amaç taşımaz; entegrasyon hesap listelerinde (otomatik güncelleme paneli, Search Console / GA4 / Google Ads / İşletme Profili bağlayıcıları), Sistem Sağlığı ve Entegrasyonlar sorun sayaçlarında ve deploy özet tablosunda görünmez; yalnız Marka adayları'nda listelenir. Uyarılar zaten yalnız bağlı hesaplar için açılıyordu (`isOperationallyBound`). Tek kaynak: `ResourceAutomation::scopeBrandBound`.
- **State:** CODED + PHPUnit (`SystemHealthAndCostsTest` bağlanmamış hesap görünmez). Üretim UAT yok.

## 2026-11-16 — Otomatik kur düzeltmeleri ve canlı geri bildirim

- **Otomatik kur:** hizmet bölgeleri önerilir (eşleşen İşletme Profili adresi → şube, işaretli; markada bölge yoksa Search Console sorgularında en çok geçen iller → işaretsiz) ve onayla eklenir; AI'a giden sayfalar sayfa envanterinden (`pages`, WordPress + sitemap, başlıklarla) gelir; onaydan hemen sonra web sitesi hazırlanır (`SiteOperations::SETUP`: kural kategorileri, hizmet ↔ sayfa, küme satırları, hedef sorgular) ve sonuç ekranı eksik küme adımını gösterir; markada başka sektör seçiliyse yalnız bildirilir (değiştirilmez); "sorgu kütüphanesine eklenir" yazan ama bir şey eklemeyen anahtar kelime metni düzeltildi (sorgular hesap bağlanınca Bekleyenler'e gelir); hazırlık adım adım (sırada / hesaplar / hizmetler / bölgeler) ve geçen süreyle görünür, sayfaya dönmek yeni tarama başlatmaz, hazır öneri varken "Yeniden tara" onay ister.
- **Canlı geri bildirim (tüm ekranlar, buton başına kod yok):** tıklanan buton istek sürerken döner ve bitince kısa ✓ verir; üstte ince ilerleme çubuğu; bileşenin `message` satırı sağ altta bildirim olarak da çıkar (hata kırmızı); operatörün başlattığı AI işi bitince / hata verince bildirim; AI göstergesi tıklamadan hemen sonra yenilenir (arka plan işleri sessiz).
- **State:** CODED + PHPUnit (`BrandSetupAssistantTest` bölge/sektör/hazırlık/yeniden tetiklenmeme, `SitePagesTest::test_after_otomatik_kur…`, `AiLiveOperationsTest::test_the_operators_own_ai_work…`). Tarayıcı JS/CSS davranışı elle gözden geçirilecek. Üretim UAT yok.

## 2026-11-15 — Web sitesi ekranı: aynı veri her yerde aynı

- **Sayfa tıklamaları:** Sayfalar ve sayfa detayı Search Console sayfa toplamlarını (`gsc_page_daily`, anonim sorgular dahil) kullanır; Özet'teki site toplamıyla uyumlu. Sorgu × sayfa verisi yalnız ortalama sıra için (ve sayfa toplamı yoksa yedek olarak).
- **Ana hizmet sayfaları:** hizmet bölümündeki sayfalar (/tedavilerimiz/, /hizmetlerimiz/ …) adında şehir geçse de "hizmet" (önce "lokasyon" sayılıyordu); kategorisi henüz yazılmamış sayfa URL'sinden değerlendirilir; haftalık yenilemede kural kategorileri pasif markada da yazılır (AI geçişi yalnız etkin markada). Başlığı olmayan sayfa slug'dan okunur başlıkla görünür.
- **Boş görünümler tek kaynaktan:** İçerik fikirleri, Rakipler, Öneriler, Kümeler ve Özet'in boş hâli `SiteScope::clusterReadiness` ile eksik adımı ve bağlantısını gösterir (hizmet yok / katalog bağı yok / küme yok / küme onaysız → "Kümeleri onayla" / eşleştirilmedi). Eski "Kümeler & Sayfalar" yönlendirmeleri kaldırıldı.
- **Özet (Search Console / GA4 genel bakış düzeni):** 6 kart (tıklama, gösterim, tıklama oranı, ortalama sıra — düşmesi iyi —, oturum, anahtar etkinlik; önceki döneme göre), günlük çizgiler, en çok tıklanan sorgular ve sayfalar (değişimle), "Neler değişti" (en çok tıklama kazanan / kaybeden sayfalar), ana hizmet sayfaları, açık işler.
- **AI hızı:** otomatik pilot ayrı `background` kuyruğunda (Horizon `supervisor-background`, 1 işçi): operatörün tıkladığı AI işleri (heavy) onun arkasında beklemez. `queries.triage` v3 yalnız atanan / filtrelenen sorgular için satır döner (yazılmayan = hiçbiri), çıktı ve süre kısalır.
- **State:** CODED + PHPUnit (`SitePagesTest::test_service_section_pages_are_main_page_totals_match_the_site_and_empty_views_say_the_next_step`, `test_overview_shows_traffic_trend_service_pages_and_open_work`). Üretim UAT yok. **Operator after deploy:** Horizon yeniden başlar (deploy.sh), yeni `background` kuyruğu otomatik açılır.

## 2026-11-14 — Sorgu otomatik pilotu: atama ve kümelemeye kadar onaysız

- **Karar (operatör):** Sorgular hizmete atanma ve kümelenmeye kadar onay beklemeden ilerler; yeni kümelerin markalara inmesi onayda kalır. Çekim sıklığına dokunulmadı (hesap başına 1 / 3 gün, ilk bağlantıda 13 ay, sonra yalnız yeni günler + kısa geç-veri penceresi).
- **`QueryAutopilot` (her 15 dakika `moxdop:queries:autopilot`, heavy kuyruk, kilitli):** (1) Hizmeti atanmamış, eşleme kelimesiyle yerleşmemiş her sorgu AI'a **bir kez** gider (`queries.triage`, 200'lük parti, sektör sektör; `ai_checked_at`): sektörün bir hizmeti (atanır, `ai`, kilitli), filtre kelimesi (kişi adı / marka ve ürün markası / yer adı / alakasız / yasaklı ifade → filtre sepetine `ai` kaynaklı, sorgunun sektörüne; kütüphane markadan bağımsızdır, markanın bölgesi hedef sorgusuna sonra eklenir) ya da hiçbiri; yeni eşleme kelimeleri eklenir (prompt `queries-triage-v2`). Filtre kelimesi sorgudan alınmalı, kısa olmalı; hizmet adı, eşleme kelimesi, soru kelimesi ya da genel kelime olamaz. (2) Kuyruk bitince Bekleyenler otomatik içe alınır (eşleme kelimesiyle atanır), yerleşmeyenler sonraki turda AI'a gider. (3) İkisi de boşsa en çok günde bir yeni sorgular "Hepsini kümele" kuyruğuyla kümelenir (mevcut kümelere yerleştirme ya da kümesiz hizmette tam kümeleme); kümelemenin baktığı sorgu (`cluster_checked_at`: yerleşti ya da başka hizmet / ilgisiz diye atlandı) bir daha AI'a gitmez (tam yeniden kümelemede sıfırlanır; kural ile hizmeti değişen sorguda sıfırlanır).
- **Saatlik temizlik (`--clean`, her saat :05):** tam tarama, sonra Silinecekler'deki açık satırlar uygulanır (filtre silmeleri, eşleme kelimesi hizmet değişiklikleri); çakışma satırları ve "Tut" denenler operatörde kalır. Filtre silmeleri kelime eklenir eklenmez değil, saatte bir toplu; **son temizlikten beri 50 yeni filtre kelimesi** birikince temizlik hemen kuyruğa girir (AI'a daha az sorgu gider), bir sonraki zorunlu temizlik 50 kelime daha bekler, saatlik temizlik sürer (`QueryAutopilot::cleanIfDue`, temizlik de kilitli).
- **Boş küme silme:** sorgu silinince ya da hizmeti değişip kümeden çıkınca **gerçek sorgusu kalmayan kilitsiz küme** (yalnız AI önerisi sorgusu kalan dahil) önerilen sorgularıyla birlikte silinir (`QueryPipeline::dropEmptyClusters`; filtre silmesi, Silinecekler onayı, hizmet değişikliği, "Hizmeti kaldır" ve saatlik temizlik). Kilitli (elle düzenlenmiş) küme boş kalsa da durur.
- **Küme penceresi:** "Seçilenleri sil" — kümeden çıkarılan sorgu Sorgular'dan da silinir (hatırlanır, Bekleyenler'den geri gelmez).
- **Ekran:** Sorgular başında "Otomatik pilot" satırı (AI'ın bakacağı sorgu sayısı, toplam atanan / filtre / eşleme kelimesi / içe alınan / temizlikte silinen, son tur) ve Durdur / Başlat. İlk içe aktarım ("AI ile planla") onaylanmadan çalışmaz; `MOXDOP_QUERIES_AUTOPILOT=false` ile kapanır.
- **State:** CODED + PHPUnit (`QueryAutopilotTest`: tek çağrı / bir kez sorma, güvenli filtre öğrenme, eşleme kelimesi, Bekleyenler, günlük kümeleme, durdurma, saatlik temizlik ve "Tut", kümeleme bir kez, boş kilitsiz küme silme, 50 filtre kelimesi tetiği; `ClusterBrandLayerTest` küme silme). SQLite + PostgreSQL. Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force`; zamanlayıcı ve heavy kuyruk çalışıyor olmalı.

## 2026-11-13 (d) — WordPress Connector dağıtımı: otomatik yok, onaylı gönderim

- **Karar (operatör):** yeni eklenti sürümü sitelere otomatik dağıtılmaz; Admin Entegrasyonlar › WordPress siteleri'nde görür, onaylar ve "Web sitesine gönder" (site başına) ya da "Tüm sitelere gönder" ile gönderir (ADR-071 akışı; değişen yalnız buton adları ve açıklama). 1.4.1 altındaki sitelere bir kez elle yükleme gerekir.

## 2026-11-13 (c) — İçerik fikirleri kurgusu Faz 5: yasaklı ifadeler

- **Tek kaynak `compliance_rules`:** sektör paketi kuralları + sektöre eklenen ifadeler (`pack_id = sector:{kod}`, her ifade bir kural, şiddet engelle = high / uyar = low, kaynak elle / AI) + markaya özel ifadeler (`pack_id = brand:{id}`, Marka › Ayarlar "Markaya özel yasaklı ifadeler"). `SectorPackRegistry::rulesForBrand` artık bu üçünü döner; böylece günlük uyum taraması, `BriefCompliance` ve WordPress kapısı (`ContentComplianceGate`) da aynı ifadeleri uygular. `rulesForSector` markasız (Sorgular) işler için.
- **Ekran:** Sorgular › "Yasaklı ifadeler" (sektör seçilir): paket kuralları (kapat / aç) ve sektörün ifadeleri (ekle, kapat, sil); "AI ile öner" (`compliance.forbidden_terms`, prompt `compliance-forbidden-terms-v1`, kuyrukta) adayları tek tek Onayla / Geç (onaylanan kaynak AI).
- **Uygulama noktaları (`ForbiddenTerms`):** (1) `content.ideas`, `site.content_recipe`, `site.apply_change` (v5), `site.write_article` (v5) paketlerinde `forbidden`; (2) kayıttan önce: engelle → kaydedilmez (mevcut kapı), uyar → Öneriler / İçerik'te sarı uyarı satırı; reçetede yasaklı ifadeli adım atılır; (3) WordPress kapısı aynı kurallar; (4) yasaklı ifadeli fikir havuza girmez ("Yasaklı ifade içeriyor").
- **State:** CODED + PHPUnit (`ForbiddenTermsTest`: ekle / tekrar reddi / AI öner + tek tek onay / kapat / sil; marka kuralı yalnız o markada; fikir paketi ve reddi; makale engelle / uyar; WordPress kapısı). SQLite + PostgreSQL. Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` (Faz 3 sütunları) → Sorgular › Yasaklı ifadeler'de sektörü seçip ifadeleri gözden geçir.

## 2026-11-13 (b) — İçerik fikirleri kurgusu Faz 4: çıta iskeletleri, kopya kontrolü, teknik durum

- **Çıta (`ClusterBenchmarks`):** aynı kümede başka markanın sayfası, gece puanı ≥ 60 ve puanlanmış, en çok 3. AI'a yalnız iskelet: puan, sıra, kapsam, tıklama oranı, H2–H4 başlıklar, kelime sayısı, soru başlığı sayısı, işlenen alt konular — metin gitmez. `content.ideas` (v2), `site.content_recipe`, `site.apply_change`, `site.write_article` paketlerinde; promptlarda "çıta al, kopyalama" talimatı.
- **Kopya kontrolü (`CopyCheck`):** diğer markaların aynı küme sayfalarıyla; 5 kelimelik dizilerin %15'inden fazlası tek sayfada ya da 12+ kelimelik aynı cümle → kaydedilmez (AI ile geliştir'de yalnız eklenen metin ölçülür); mesaj sayfayı ve aynı cümleleri gösterir.
- **Teknik durum:** `PageTechnical` (son HTML alımı 4xx/5xx, noindex, canonical) SEO analizi ve AI ile geliştir paketlerinde; reçetede teknik sorun ilk adım.
- **Düzeltme:** sayfa başlıkları `{level, text}` olarak saklanıyor; küme eşleme / eksik kodu bunları düz metin sanıyordu (canlıda "Array to string conversion"). `Page::headingTexts()` / `outline()` ile okunuyor.
- **State:** CODED + PHPUnit (`ContentBenchmarksTest`: başka marka, ≥ 60, en çok 3, metin yok, reçete paketi; %15 ve 12 kelime eşikleri, sayfanın kendi metni hariç; kopya makale kaydedilmez). SQLite + PostgreSQL. Üretim UAT yok.

## 2026-11-13 — İçerik fikirleri kurgusu Faz 3: Web sitesi › İçerik fikirleri sekmesi, eşleme kuralları, SEO analizi

- **Sekme (`ContentIdeasTab`, Web sitesi › Sorgular › İçerik fikirleri, ilk alt sekme):** her onaylı küme bir ana fikir satırı (dil başına), havuzdaki ek fikirler altında girintili. Sütunlar: fikir, tür, talep (kümenin sorgu gösterimi), eşleşen URL, puan (gece, ↑↓), durum + gerekçe, işlem. Sıra: Teknik sorun → Sayfa yok → Geliştirilmeli → Karşılıyor → İncelenmedi → Hariç, sonra talep. Filtre: hizmet, tür, durum. Eski "Kümeler & Sayfalar"ın küme tablosu buraya taşındı (Düzenle: hedef URL kilidi, ek URL, hedef sorgu, hariç; aynı `ClusterEditor`); alt sekme "Sayfalar & hizmetler" adıyla sayfa listesi olarak kaldı. Eski `seo/kumeler` bağlantısı İçerik fikirleri'ne iner.
- **Eşleme (`ClusterAudit`, "Eşleştir" / "Yeniden keşfet"):** aday sayfalar: kümenin Search Console gösteriminin ≥ %50'sini alan sayfa ilk aday (`ClusterPageShares`, 90 gün); sonra kelime örtüşmesi, sayfa tipine uygun kategori önde (hizmet→hizmet, rehber→blog, SSS→sss/blog, lokasyon→lokasyon, karşılaştırma→blog); ana sayfa / iletişim / hakkımızda / KVKK hiç aday değil; elle seçilen sayfa kalır. Ek fikirler (§5.3): kullanım satırı oluşur, aday kelimeleri başlık + açı + hedef sorgular, ana fikrin sayfası aday değil; `site.cluster_match` (v2, kind extra) + `site.cluster_gaps` (v2, taslak başlıklar alt konu olarak). "Yeniden keşfet" tek satır, tek çağrı, sisteme çekilmiş sayfalarla.
- **Durum (`ContentIdeaState`, §5.4):** Teknik sorun (son HTML alımı 4xx/5xx, noindex, canonical başka sayfa — `PageTechnical`) → Sayfa yok → Geliştirilmeli (kapsam kısmi, ya da tam + puan < 50 ve veri az değil) → Karşılıyor; okunmamış satır "İncelenmedi". Gerekçe: eksik sayısı + ilk eksik, ort. sıra, puan / veri az; "Google bu kümede /x sayfasını gösteriyor" (başka sayfa ≥ %50) ve "/x ve /y aynı kümede yarışıyor" (iki sayfa ≥ %25) — paylar gece puanında `cluster_page_scores.page_shares`.
- **SEO analizi (`ContentRecipe`, yeni AI işi `site.content_recipe`, prompt `site-content-recipe-v1`):** paket §8.2 (marka, fikir, sayfa + teknik durum, kapsam + eksikler, puan, sayfanın Search Console sorguları, site sayfaları, sektör kuralları; çıta ve yasaklı ifade Faz 4–5'te). Kontrol: geçerli alan, paket dışı rakam / URL içeren adım atılır, teknik sorun varsa ilk adım teknik, SEO başlığı ≤ 60 / meta ≤ 155. Reçete satırda saklanır ("Reçete").
- **AI ile geliştir:** yalnız Geliştirilmeli + WordPress sayfası; eksikler + reçete → eksik konu önerisi → `site.apply_change` (v3, reçete adımları ve önerilen SEO alanları pakette) → satırda "Önizle ve güncelle" Öneriler'de o öneriyi açar (eski ↔ yeni, uyum kapısı, Onayla → WordPress, ADR-070, geri alınabilir). **AI ile üret:** yalnız Sayfa yok + "Yeniden keşfet" sonrası; fikirden içerik önerisi (başlık, tür, taslak, AI soruları, hedef URL; ek fikirde açı, hedef sorgular, ana sayfa bağlantısı, reçete) + `site.write_article` (v3) → İçerik sekmesinde inceleme → WordPress taslağı (ADR-064). Ek fikir yazısında ana sayfa bağlantısı yoksa eklenir.
- **Yeni fikir üret (markadan):** küme satırının altında, marka bağlamı + site sayfalarıyla.
- **State:** CODED + PHPUnit (`ContentIdeasTabTest`: dört durum + teknik (noindex / canonical / 404) + yanlış sayfa / çakışma gerekçesi; GSC adayı önde, ana sayfa / iletişim aday değil, ek fikirde ana sayfa aday değil, tür uyumu, elle seçim korunur; reçete paketi, uydurma rakam / geçersiz alan atılır, teknik adım önde, AI ile geliştir kapısı ve reçeteli paket, Yeniden keşfet → AI ile üret, ana sayfa bağlantısı; `ClusterAuditTest` güncellendi). SQLite + PostgreSQL. Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` → `php artisan moxdop:clusters:score-pages` (paylar için) → Web sitesi › Sorgular › İçerik fikirleri › "Eşleştir".

## 2026-11-12 (b) — İçerik fikirleri kurgusu Faz 2: içerik havuzu + "Yeni fikir üret"

- **Havuz (`content_ideas`):** kümenin ek içerik fikirleri (ana fikir kümenin kendisi), sistem geneli: başlık, normalize başlık (kümede tekil), tür (hizmet / rehber / SSS / karşılaştırma / lokasyon), açı, hedef sorgular (`in_cluster` işaretli; kümede olmayan = "önerilen"), taslak H2'ler, üreten marka (Sorgular'dan boş), üreten kişi, prompt sürümü, durum (etkin / arşiv). **Marka kullanımı (`brand_content_ideas`):** marka + fikir + site → sayfa, durum (Karşılıyor / Geliştirilmeli / Sayfa yok / Teknik sorun), kapsam, eksikler, gerekçe, kilit; sayfası olan markalar "kullanan markalar" sayılır (satırları Faz 3 eşlemesi doldurur).
- **"Yeni fikir üret" (`ContentIdeaPool`, `GenerateContentIdeasJob`, AI işi `content.ideas`, prompt `content-ideas-v1`):** yalnız operatör basınca, 1–5 fikir (varsayılan 3), kuyrukta tek çağrı. Paket: küme (ad, sayfa tipi, ihtiyaç, alt konular, en çok aranan 30 sorgu, AI soruları), havuzdaki etkin fikirler, sayı; markadan basılınca marka (ad, hizmetler, bölgeler) + sitenin hizmet / blog / SSS / lokasyon sayfaları (Sorgular'dan marka bağlamı gitmez). Kontrol: tür geçerli, başlık ≥ 3 kelime, küme adıyla ya da havuzdaki (arşiv dahil) bir fikirle aynı değil, en az bir hedef sorgu kümenin sorgusu, 3–12 taslak başlık; geçmeyen kaydedilmez, nedeni ekranda yazar.
- **Ekran:** Sorgular › Küme penceresi › "İçerik fikirleri": fikir, tür, açı, hedef sorgular, üreten (marka / "Sorgular (genel)"), kullanan markalar, Arşivle; sayı seçimi + "Yeni fikir üret" (çalışırken sayfa yenilenir).
- **State:** CODED + PHPUnit (`ContentIdeaPoolTest`: 2 geçerli fikir havuza, küme adı tekrarı / küme dışı hedef / geçersiz tür elenir, markasız pakette marka yok, markalı pakette marka + bölge + sayfalar + mevcut fikirler, havuz tekrarı elenir, başka markada kullanım görünür, arşiv). SQLite + PostgreSQL. Üretim UAT yok. Web sitesi › İçerik fikirleri sekmesi (markadan üretim) Faz 3'te. **Operator after deploy:** `php artisan migrate --force`.

## 2026-11-12 — İçerik fikirleri kurgusu Faz 1: sayfa puanı + tüm markalardaki küme sayfaları

- Ürün kurgusu: `docs/product/CONTENT_IDEAS_BLUEPRINT.md` (onaylı). Faz 1 kodlandı; Faz 2–5 sırada (Faz 2 üstte).
- **Sayfa puanı (`ClusterPageScorer`, `cluster_page_scores`, gece 07:10 `moxdop:clusters:score-pages`):** her küme satırının sayfası, markanın Search Console verisinin son 90 gününde yalnız o kümenin gerçek sorgularında (`query_sources` bağıyla; varyantlar dahil, önerilen sorgular hariç): gösterim, tıklama, ağırlıklı sıra, kapsam (göründüğü küme sorgusu / küme sorgusu), tıklama oranı. Puan 1–100 = %50 sıralama (1. sıra 100 → 20+ 0) + %25 kapsam (%50 kapsam tam) + %25 tıklama oranı (%10 tam). Ham gösterim/tıklama puana girmez (şehir büyüklüğü). Puansız: GSC bağlı değil / GSC verisi yok / sayfa yok / veri az (< 100 gösterim). GA4 oturum + dönüşüm aynı pencerede bilgi. Önceki puan eğilim için tutulur.
- **Ekran:** Sorgular › Küme penceresi › "Bu kümeye atanmış sayfalar (tüm markalar)": marka (web sitesi ekranına bağlantı), URL, puan (↑↓), ort. sıra, gösterim, tıklama, kapsam, GA4.
- **State:** CODED + PHPUnit (`ClusterPageScoreTest`: formül, 90 gün penceresi, başka sayfa / küme dışı sorgu / önerilen sorgu hariç, GSC bağlı değil, veri az, önceki puan, ekran). **Operator after deploy:** `php artisan migrate --force`; puanlar ilk gece oluşur, hemen görmek için `php artisan moxdop:clusters:score-pages`.

## 2026-11-11 — Kümeleme v5 (sayfa büyüklüğünde, atlananların nedeni) + veri kaynağında "son çekim ne yazdı"

- **Prompt `queries-cluster-v5`:** küme = Google'ın tek URL ile cevapladığı konular; "diğer / çeşitli" çöp küme yasak; sektörün diğer hizmetlerine ait konular (`other_services` verilir) kümelenmez, `skipped` (other_service + hizmet adı | not_relevant) olarak döner; her konu ya bir kümede ya `skipped`'ta olmalı.
- **Atlananlar:** AI'ın açıkça atladığı konular nedeniyle sayılır; cevapta hiç geçmeyen konu bir sonraki parçada bir kez daha sorulur, yine geçmezse "işlenemedi" sayılır (eskiden ilk seferde kalıcı dışarıda kalıyordu). Küme listesinde: "Kümede olmayan sorgu: N · son kümelemede AI: başka hizmete ait X (hizmet adları) · hizmetle ilgisiz Y · işlenemedi Z".
- **Veri kaynakları (varlık › Veri kaynakları):** bağlı her hesabın altında son bitmiş çekimin yazdığı: yeni satır / güncellendi / değişmedi (`dataset_write_batches`), Search Console ve Google Ads için yeniden doğrulama penceresi ve "aynı gün ve boyut tek satırdır; tekrar çekim kopya oluşturmaz" açıklaması.
- **State:** CODED + PHPUnit (`QueriesScreenTest`, `OperatorAssetDataSourcesGuardsTest`). İmplant gibi önceden kümelenmiş hizmetleri yeni kurallarla almak için "AI ile kümele" ile yeniden kümelemek gerekir (onaylı kümeler korunur).

## 2026-11-11 — Sorgular › Kümeler: hizmet özeti, "Hepsini kümele", parça parça eksiksiz kümeleme

- **Hizmet özeti:** Kümeler sekmesi hizmet seçilmeden boş kalmıyor. Sektöre göre gruplu, talebe göre sıralı tablo: hizmet, **markalar** (hizmeti etkin olarak sunan markalar), sorgu, kümedeki sorgu, küme (onaylı), durum (kümelenmedi / N sorgu kümede değil / kümelendi / kümeleniyor · parça x / y / sırada / hata), satırda **Kümele** (kümesi yoksa) veya **Yerleştir** (yeni sorgular mevcut kümelere) ve **Aç**. Sektör filtresi tabloyu daraltır; hizmet seçilince küme listesi açılır.
- **Küme listesi:** **Talep** sütunu (üye sorguların gösterim toplamı), liste talebe göre büyükten küçüğe; üstte "Kümede olmayan sorgu: N".
- **Parça parça kümeleme (`QueryClusterer`, `ClusterQueriesJob`):** tek çağrı / 800 konu sınırı kalktı. Her iş bir AI çağrısı: (1) iskelet — en çok aranan 400 konu (`moxdop-query-rules.cluster.skeleton_topics`); (2) yerleştirme — kalan konular 300'erli parçalarla (`place_topics`), her konu mevcut bir kümeye (`existing_cluster_id`, kilitli/onaylı da olabilir — tanımı değişmez) ya da yeni kümeye; AI'ın hizmetle ilgisiz bulduğu konular bu çalıştırmada tekrar sorulmaz; (3) gözden geçirme (`queries.cluster_review`, yeni `QueryClusterReviewAgent`) — aynı sayfaya düşecek kümeler birleştirilir (kilitli küme hiçbir zaman başka kümeye katılmaz / silinmez), kilitsiz kümelerin adı, niyeti, sayfa tipi, ihtiyacı, alt konuları netleşir; gözden geçirme hatası kümeleri bozmaz. Başarısız parça 3 kez denenir (30 sn, 2 dk), sonra durum "hata". Prompt `queries-cluster-v4`. Bir sonraki parça önceki parçanın kayıtlı cevabından sonra kuyruğa girer.
- **Hepsini kümele (`QueryClusterQueue`):** kümede olmayan sorgusu olan tüm etkin hizmetler talebe göre sıraya alınır, aynı anda tek hizmet işlenir; kümesi olmayan hizmet tam, kümesi olan hizmet yalnız yerleştirme yapar. İlerleme (x / y hizmet, sırada N) ve **Durdur** (o anki parça biter, kuyruk boşalır).
- **State:** CODED + PHPUnit (`QueriesScreenTest`: parçalar + gözden geçirme + kilitli küme korunması, toplu kuyruk + özet tablosu markalar/durum, hata ve durdurma), SQLite + PostgreSQL. Gerçek veriyle UAT yok. Deploy sonrası komut gerekmez.

## 2026-11-11 — Sorgular › Kümeler: küme penceresi düzeltmesi

- Küme penceresi üst çubuğun (sticky header) altında kalıyordu (× ve üst alan tıklanamıyordu) ve buton sonuçları ("Küme kaydedildi." vb.) pencerenin arkasındaki sayfanın üstünde görünüyordu. Pencere artık tam ekran katmanda (arka plana tıkla / Esc ile kapanır) ve sonuç mesajı pencerenin içinde. State: CODED + PHPUnit (`QueriesScreenTest`).

## 2026-11-11 — WordPress içerik dışa aktarımı (Connector 1.7.0), yarım kalan çekim artık "tamamlandı" görünmez

- **Eklenti 1.7.0 (`GET moxdop/v1/content-export?ids=`, HMAC, `/snapshot` ile aynı tek-seferde-bir kilidi, salt okuma):** istenen en çok 50 yayımlanmış, parolasız, görüntülenebilir yazının içeriği temasız işlenir: Elementor sayfaları Elementor'un kendi içerik çıktısıyla (`get_builder_content_for_display`), diğerleri `the_content` filtreleriyle (Gutenberg blokları, WPBakery / Divi kısa kodları; etkin olmayan oluşturucu kısa kod etiketleri silinir, metin kalır). Her kayıt küçük bir HTML belgesi: SEO başlığı (Yoast / Rank Math / SEOPress; `%%değişken%%` içeriyorsa yazı başlığı), açıklama, canonical, noindex (varsa), dil (Polylang, yoksa site dili), oluşturucu sayfası değilse ve içerikte H1 yoksa yazı başlığı H1. Alanlar: id, status (content / not_public / skipped_size), url, type, builder, modified_at, sha256, html_gz_b64. 20 sn'de yetişmeyenler `pending_ids`; ~4 MB yanıt sınırı. `/status` → `content_export` yeteneği. `connector_version` 1.7.0.
- **Uygulama (`WebsiteDatasetExecutor`):** eklenti ≥ 1.7.0 (`moxdop-wordpress.content_export_min_plugin_version`) ve kuyrukta ≥ 10 sayfa (`crawl.content_export_min_queue`) varsa, kuyruktaki WordPress yazıları (envanter permalink → yazı id) 25'lik isteklerle (`crawl.content_export_per_request`) dışa aktarımdan okunur ve aynı `persistPage` hattından yazılır (`fetch_source = wp_content`: http snapshot, HTML, meta, başlıklar, içerik istatistikleri). Tema yok: bu sayfaların schema ve bağlantı satırları yazılmaz, son HTTP okumasındaki kalır. Ana sayfa, envanterde olmayan URL'ler ve dışa aktarımın veremediği yazılar HTTP ile okunur. Önbellek dosyaları okunabiliyorsa önce `/page-cache`, sonra içerik dışa aktarımı, sonra HTTP. Meşgul (429) → Retry-After; hata → kalanlar HTTP. Kaynak satırı "WordPress içeriği: N" gösterir. Yalnız değişenler mantığı aynı (kuyruk zaten değişen sayfalardır).
- **Düzeltme:** biten bir sayfa taraması planladığından az sayfa okuduysa çekim konsolunda "yarım kaldı: N sayfa okunmadı (sonraki Genel çekim okur)" yazar (#143: 826 / 4.381). Tarama bitişine neden (`finish_reason`: queue_empty / limit) ve okunmayan sayısı checkpoint'e yazılır, okunmayan sayfayla biterse uyarı loglanır. İşçi kesintisinden sonra devam eden adım "Expired worker lease; resuming saved checkpoint." notunu temizler (hata gibi kalmaz). Not: #143'te kuyruğun neden boşaldığı kodda kesinleştirilemedi; yeni log ve `finish_reason` bir sonraki olayda nedeni gösterir.
- **State:** CODED + PHPUnit (`CacheFriendlyCrawlTest::connector_1_7_content_export_replaces_page_reads_and_leaves_the_rest_to_http`, `WebsiteCollectionOverviewTest` yarım kaldı satırı, `InterruptedCollectionRecoveryTest` not temizliği; eklenti stub'lı WordPress ile duman testi). Gerçek sitede UAT yok. **Operator after deploy:** müşteri sitelerinde eklentiyi 1.7.0'a güncelle (MoxDOP'tan tek tık güncelleme veya ZIP), sonra Genel çekim.

## 2026-11-09 — AI ile planla / Filtre sepeti takılı kalmaz

- Sektör işlerinden biri cevapsız kalırsa (işçi yeniden başladı, AI işlerinden durduruldu, zaman aşımı) adım artık "5 / 7"de beklemez: 20 dakika ilerleme yoksa cevapsız sektörler "başarısız" sayılıp öneri kalanlarla tamamlanır; durdurulan sektör işi kendini başarısız olarak bildirir. Sihirbaz ve Filtre sepetinde "Durdur" butonu adımı hemen sıfırlar. **State:** CODED + PHPUnit (`QueryPlanWizardTest`), SQLite + PostgreSQL.

## 2026-11-09 — Önbellek dostu sayfa okuma, eklentiden önbellek dışa aktarımı (Connector 1.6.0), bir kez tam sonra yalnız değişenler

- **İstekler ziyaretçi gibi (`PublicHttpFetcher`, `DiscoveryConfig`):** User-Agent `Mozilla/5.0 (compatible; MoxDOP-SiteReader/1.0; +https://moximu.com)` (tarayıcı işareti + kimlik; WP Super Cache'in varsayılan reddettiği bot / crawl / spider / slurp / Yandex kelimeleri yok; robots.txt "MoxDOP" kuralları geçerli), tarayıcı benzeri Accept / Accept-Language (tr) / Accept-Encoding (gzip), çerez yok, `Cache-Control` / `Pragma` istek başlığı yok, önbellek kıran sorgu parametresi yok. Sonuçta `cache` (hit, plugin): `x-litespeed-cache`, `cf-cache-status` (+ APO), `x-cache` / `x-proxy-cache`, `age > 0`, `x-wp-super-cache` ve WP Rocket / WP Super Cache / W3 Total Cache / WP Fastest Cache / Cache Enabler alt yorumları.
- **Site önbellek durumu (`website_crawl_state`, `WebsiteCrawlState`):** isabet oranı (kayan pencere, en az 6 okuma), algılanan önbellek eklentisi, eklentinin önbellek dosyalarını okuyup okuyamadığı, son tam okuma, son çekimin kaynak dağılımı. İsabet ≥ %80 ise aynı anda 4 sayfa (`crawl.cached_concurrency`, adımda 8); ıskada 2; geri çekilme / robots Crawl-delay her zaman kazanır (1).
- **Koşullu istek (304):** http snapshot metadata'sında `etag` / `last_modified` (+ `cache_hit`, `cache_plugin`, `fetch_source`); sonraki okumada `If-None-Match` / `If-Modified-Since` gönderilir, 304 = değişmemiş sayfa yolu (`WebsitePageStateStore::touchUnchangedPage`, yeni satır yok). Tam yeniden okumada koşullu istek yok.
- **Eklenti 1.6.0 (`GET moxdop/v1/page-cache?page=&per_page=`, HMAC, `/snapshot` ile aynı tek-seferde-bir kilidi):** yayımlanmış, parolasız, herkese açık URL'ler için önbellek eklentisinin diske yazdığı HTML okunur, sayfa işlenmez: WP Rocket, WP Super Cache, W3 Total Cache (disk enhanced), WP Fastest Cache, Cache Enabler (düz + gzip dosyalar, dosya önbellek dizini dışına çıkamaz). LiteSpeed Cache (sunucu önbelleği) desteklenmez olarak raporlanır. Kayıt: url, status (cached / not_cached / skipped_size), cache_plugin, file_mtime, sha256, html_gz_b64; en çok 50 / sayfa, ~4 MB yanıt. URL listesi tek sayfalı ID/slug sorgusu (içerik yüklenmez). `/status` → `cache` özeti + `page_cache` yeteneği. `connector_version` 1.6.0.
- **Uygulama (`WebsiteDatasetExecutor`):** sayfa taramasının başında eklenti ≥ 1.6.0, önbellek okunabilir ve kuyrukta ≥ 20 sayfa varsa (`crawl.page_cache_min_queue`) önce `/page-cache` sayfaları okunur; SHA-256'sı tutan her önbellek kopyası taranmış sayfayla aynı `persistPage` hattından yazılır (http snapshot 200, `fetch_source = wp_page_cache`, HTML, meta, başlıklar, bağlantılar, içerik istatistikleri), kuyruktan düşer; yalnız not_cached sayfalar HTTP ile okunur. Aynı HTML → değişmemiş yolu. Eklenti meşgulse (429) Retry-After sonra; dışa aktarım hatası → kalan sayfalar HTTP ile. Not: veri seti yolu WordPress envanter çalıştırıcısında değil, sayfa HTML veri setlerini yazan tarama çalıştırıcısının içinde (aynı dataset run'ına yazar, ayrıştırma tekrarı yok).
- **Bir kez tam, sonra yalnız değişenler:** çekim modu `crawl_mode` / `refetch_unchanged` (iç içe `request_context.context`). Hata düzeltmesi: tarama önceden üst düzey `force_refresh`'i (her elle tetiklemede true) tam okuma sayıyordu, yani "Genel çekim" her sayfayı yeniden okuyordu; artık yalnız `refetch_unchanged = true` tam okumadır. **Genel çekim** = değişenler (+ tarihsiz sayfaya 7 gün, her sayfaya 30 gün yeniden bakış, koşullu istekle ucuz); kapsam seçicide yeni **"Tam yeniden okuma"** (`full_reread`, tüm sayfalar). Otomatik WordPress yenilemesi: günlük envanter + ardından yalnız tarihi değişen sayfalar (`crawl_mode = changed`, yeniden bakış yok); hiç okunmamış site hemen tam okunur; tam HTML yeniden okuma en çok 30 günde bir (`crawl.full_read_interval_days`) ve yalnız gece Europe/Istanbul 01:00–06:00 (`WordPressEventReconciliation::startNightlyFullReads`, tam envanter slotları).
- **Ekran (Website › Özet):** "Kaynak" satırı: "Önbellekten: N · Sayfa okuma: M · Değişmedi (304/aynı): K · <eklenti>, isabet %X · eklenti önbellek dosyalarını okuyor".
- **State:** CODED + PHPUnit (`CacheFriendlyCrawlTest` başlık / UA / önbellek işaretleri / eşzamanlılık / 304 / önbellek dışa aktarımı; `FullOnceThenChangedTest` Genel çekim / Tam yeniden okuma / günlük / gece tam okuma; `WordPressPageCacheExportTest` eklenti yol çözümü + geçici dizinde dosya okuma + `php -l`; `WebsiteProductionCollectorTest` güncellendi), SQLite + PostgreSQL. Gerçek sitede UAT yok. **Operator after deploy:** `php artisan migrate --force` (`2026_11_09_090000_create_website_crawl_state_table`); müşteri sitelerinde eklentiyi 1.6.0'a güncelle (MoxDOP'tan tek tık güncelleme veya ZIP).

## 2026-11-08 — Eşleme kelimeleri: kelime etkisi, kelime önerileri, çakışmalar, sektör uyumu

- **Eşleştirme kuralı (`QueryServiceMatcher`):** yalnız kelime (ayrıştırma / etiket / embedding yok). Bir kelime, sorguda geçen daha uzun bir kelimenin içinde kalıyorsa (kelime kelime, ek toleranslı; "yüz germe" ⊂ "sıvı yüz germe") uzun olan kazanır; kalan kelimeler iki+ hizmete aitse sorgu **atanmaz** (çakışma, `matchWithKeyword()['conflicts']`). İçe aktarma, bekleyen içe aktarma ve tarama çakışan sorguya hizmet atamaz; kural ataması çakışmaya dönen sorgu için Silinecekler › Hizmet değişikliği satırı "atama kalkıyor · çakışma: a / b" (`query_review_items.reason = conflict`), atanmamış çakışan sorgu için satır yok.
- **Kelime etkisi (`KeywordInsights::impact`):** Eşleme kelimeleri › Kelimeler'de (ve AI ile planla adım 2 satır içi eklemede) kelime yazılırken kaydetmeden önce "Bu kelime N sorgu yakalayacak · M'si şu an bu hizmette · K'si başka hizmetten gelecek (X: a) · L'si atanmamış" + elle / AI / kilitli (değişmez), daha uzun kelimeyle başka hizmette kalan, çakışmaya düşen sayıları ve gösterime göre örnek sorgular (sayfalı). Aynı eşleştirici, yeni kelime eklenmiş hâliyle, yalnız sektörün sorgularında; sektör sorgularının kelime önek dizini önbellekte (40 bin sentetik sorguda dizin 0,55 sn bir kez, önizleme ~0,05 sn). Kayıt bugünkü gibi (tarama → Silinecekler).
- **Kelime önerileri:** sektörün atanmamış (gizli / kilitli / önerilen olmayan) sorgularında sık 1–3 kelimelik ifadeler, gösterim ağırlıklı; niyet / soru / dolgu kelimeleri (fiyat, nedir, en iyi, yorum…), `ServiceKeywordService` genel kelimeleri (tek başına) ve il / ilçe / ülke adları (`LocationOptions::expressions`) hariç, sektörde zaten kelime olan ve "Yok say"ılan (`query_keyword_dismissals`, sektör başına) hariç; aynı sorgularda geçen kısa ifade uzun olanın yanında listelenmez. İfade · sorgu · gösterim · 3 örnek, hizmet seçici + "Ekle" (etki paneli, onayla kaydet) / "Yok say". Deterministik, AI yok; önbellek her taramada ve "Yenile" ile yenilenir.
- **Çakışmalar:** sektörün kural atamasına açık çakışan sorguları (sorgu, çakışan kelimeler → hizmetleri, mevcut hizmet, gösterim); satırda çakışan hizmetlerden biri elle atanır (kilitli) veya toplu atanır, "Daha uzun kelime" sorgu metniyle etki panelini açar.
- **Sektör uyumu:** hizmeti sorgunun sektöründen başka sektöre ait sorgular (sorgu sektörü × hizmet sektörü, sayı, örnekler); "Hizmetin sektörüne taşı" veya "Hizmeti kaldır" (atanmamış, kilitsiz, kümelerden çıkar). Taramanın önerdiği kaldırma sektör uyuşmazlığından ise satırda "sektör uyuşmuyor" (`reason = sector`).
- **State:** CODED + PHPUnit (`QueryServiceMatcherTest`, `KeywordInsightsTest`, güncellenen `QueryPipelineTest`; `tests/Feature/Queries` 60 test), SQLite + PostgreSQL. Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` (`2026_11_08_090000_query_keyword_insights`), ardından bir tarama (mevcut kural atamalarındaki çakışmalar Silinecekler'e düşer).

## 2026-10-01 — Küme ↔ içerik denetimi: AI eşleştirme, eksik listesi, lokasyon kuralı, AI soruları, Eksikleri gider / Konu üret

- **Kümeleri içerikle karşılaştır** (Web sitesi › Sorgular › Kümeler & Sayfalar, `ClusterAudit`, `SiteOperations::CLUSTER_AUDIT`): önce kural eşleyicisi markanın hizmetlerinin onaylı kümelerine satır açar (AI hakemi kapalı), sonra (1) AI soruları eksik kümelere `queries.ai_queries` (hizmet başına bir çağrı, 4–8 soru, yerel kümelerde `{bölge}`) → `clusters.ai_queries`; (2) `site.cluster_match` (hizmet × dil başına bir çağrı): aday sayfalar kelime örtüşmesiyle (yol, başlık, H1, alt başlıklar; küme başına 6, çağrı başına 60; kurumsal sayfalar aday değil), AI başlık / alt başlık / metnin başını okuyup kümeyi karşılayan sayfayı ve kapsamayı (full / partial / none) verir; bilinmeyen sayfa id'si saklanmaz, operatörün kilitli sayfası korunur; (3) `site.cluster_gaps` (eşlenen sayfa başına bir çağrı): sayfa metni (9 000 karakter) × kümelerin sorguları, yönleri, AI soruları ve gerekiyorsa hizmet bölgeleri → küme başına en çok 10 eksik (soru / bölüm / yön / lokasyon / AI sorusu). `brand_cluster_pages.coverage`, `gaps`, `audited_at`; durum full → yeterli, partial → kapsam yetersiz, none → uygun sayfa yok; URL / 2+ haneli sayı içeren gerekçe atılır.
- **Lokasyon kuralı** (`ClusterAudit::needsLocation`): ticari / yerel niyetli kümeler ve lokasyon sayfaları markanın hizmet bölgelerini alır (eksik kontrolü "lokasyon", Eksikleri gider, Konu üret, Taslak hazırla); bilgi / karşılaştırma kümeleri bölgesiz kalır. `{bölge}` markanın ana bölgesiyle doldurulur; bölgesi olmayan markada bu sorular gönderilmez.
- **Satır işlemleri:** "Eksikleri gör (N)" (eksikler + AI asistanına sorulanlar), **Eksikleri gider** (sayfası ve eksiği olan satır: `site.cluster_gaps` kararlı `missing_topic` önerisi → "AI ile yap" küme paketiyle — eksikler, sorgular, AI soruları, bölgeler — yeni sürümü hazırlar; Öneriler sekmesinde yan yana, Admin onayı, WordPress, geri alınabilir), **Konu üret** (sayfası olmayan satır: haftalık içerik ajanı tek küme ve kapasite 1 ile çalışır; İçerik sekmesinde onay → Taslak hazırla → WordPress taslağı). Promptlar: `site.apply_change` v2, `site.weekly_content` v2, `site.write_article` v2 (AI soruları ve bölgeler içerikte doğal kullanılır, uydurma bilgi yok).
- **State:** CODED + PHPUnit (`ClusterAuditTest`), SQLite tam takım + PostgreSQL (`tests/Feature/Site`, `tests/Feature/Queries`). **Üretim UAT yok.** **Operator after deploy:** `migrate` → Web sitesi › Sorgular › Kümeler & Sayfalar › "Kümeleri içerikle karşılaştır" (kümeler Sorgular'da onaylı olmalı).
- **Açık:** kümeleme yalnız hizmeti atanmış sorgularda çalışır (dışa aktarımda sorguların ~%80'i hizmetsiz); sayfa içeriği sitenin HTML çekimine bağlıdır (çekimi engellenen sitelerde karşılaştırma eksik kalır).

## 2026-10-01 — Kural motoru v2 (189 279 sorguluk dışa aktarımdan) ve filtre listesi

- **Kurallar v2** (`config/moxdop-query-rules.php`): İngilizce / Almanca sorgular kendi anahtarını alır (`[en] …`, `[de] …`: içerik dile göre yazılır), İngilizce bağlaç / çoğul (implants → implant) ve yön ifadeleri (price, cost, how much, how, what, best, near me…), sektörlerin ima ettiği kelimeler (Diş sağlığı: diş, dental, tooth, teeth, zahn; Medikal estetik: estetik; Perde: perde; Geri dönüşüm: hurda), eşanlamlılar (ücret → fiyat, çekim → çekimi, özelde → özel), yeni yön "yakın" (yakınımda, near me).
- **Motor:** kök yalnız kütüphanede gerçekten geçen kelimeye iner (≥ 3 sorgu ve kelimenin %5'i: tek sorgudaki "hurd" yazımı "hurda"yı kısaltmaz); **otomatik yazım hatası** (≤ 2 sorgudaki kelime, 30+ sorguda ve 20 kat sık geçen kelimeye bir harf uzaksa: "diastama", "diaestema" → "diastema"); İngilizce çoğul; okunamayan alfabe (Japonca vb.) kendi grubudur (boş anahtar yok). 200k sorguda ~2 sn.
- **Yer adı istisnaları:** dışa aktarımdaki gerçek eşleşmelerden gündelik kelime / soyadı / ürün kökeni olanlar (mısır, çarşamba, vize, menteşe, talaş, araç, yumurtalık, sürücü, yıldırım, keskin, "isviçre implant", "russian lips", "buldan bezi"…) yer sayılmaz.
- **Ölçüm (dışa aktarım, yer adı ve spam ayıklandıktan sonra):** Diş sağlığı 122 550 sorgu → 94 209 varyant → 85 624 konu; Medikal estetik 33 049 → 27 492 → 24 658; Perde 6 836 → 5 156 → 4 786. Hizmet atanmış: Diş 23 614 (13 200 konu), Medikal 2 829. **Kümeleme yalnız hizmeti olan sorgularda çalışır: sorguların ~%80'inin hizmeti yok.**
- **Filtre listesi** `docs/queries/filtre-sepeti-2026-10-01.txt` (Filtre sepeti › Toplu ekle): bahis / casino / kripto / yetişkin / tanışma / ilaç spam'i ve "rüyada"; her terim sektörlü sorgularda en çok 1 kez geçiyor; ~1 900 sorgu.
- **State:** CODED + PHPUnit (`QueryRuleEngineTest`), SQLite. Üretim UAT yok.

## 2026-11-10 — Sorgu kural motoru (varyant + konu / yön), CSV indir, filtre toplu ekle, konu bazlı kümeleme

- **Kural motoru** (`QueryRuleEngine`, kurallar `config/moxdop-query-rules.php`, sürümlü; operatörün CSV'sinden yazılır): AI yok. **Varyant anahtarı** = katlanmış metin → ifade eşanlamlıları → bağlaç / yıl atma → yazım hatası → kütüphane tabanlı kök (sorgu kütüphanesinde geçen en kısa ekli olmayan biçim: "implantları" → "implant", "estetiği" → "estetik"; `no_stem` istisna) → eşanlamlı ("ücret" → "fiyat") → genel ("tedavi") ve sektörün ima ettiği ("diş") kelimeler çıkar → sıralı. **Konu anahtarı** = yön ifadeleri (fiyat, nedir, nasıl, yorum, en iyi, süre, garanti, avantaj, randevu) çıkarılmış hâli; `queries.facets` yönleri tutar. `queries.variant_key`, `topic_key`, `facets`, `variant_head` (grubun en çok gösterimli görünür sorgusu). Her boru hattı çalışmasının sonunda, inceleme onayı / bekleyen içe aktarma sonrası (`ApplyQueryRulesJob`), "Kuralları uygula" ve `php artisan moxdop:queries:rules`. 200k sentetik sorguda anahtar hesabı ~1,5 sn; yalnız değişen satırlar yazılır.
- **Sorgular ekranı:** "N sorgu → V varyant → K konu · kural vX" satırı, **Varyantları birleştir** (varsayılan açık: grup başına tek satır, "+N varyant" ve toplam gösterim / tıklama ipucu, yönler satırda); toplu işlemler (ata, kaldır, gizle, geri al) grubun tüm varyantlarına uygulanır; AI'a giden seçim yine baş satırlardır. **CSV indir** (`/library/queries/export`): tüm kütüphane (gizli / önerilen dahil) sektör, hizmet, küme, metrikler, anahtarlar ve kural sürümüyle, akışla (UTF-8 BOM).
- **Filtre sepeti › Toplu ekle:** satır başına bir terim; var olan ve soru kelimesi içeren satırlar atlanır, tek tarama.
- **AI ile kümele** (`queries.cluster` v3): AI'a ham sorgu değil **konular** gider (en çok 800, gösterime göre): konu başlığı, yönler, varyant sayısı, gösterim / tıklama, örnek sorgular ve **Google'ın gösterdiği sayfa** (sektörün operasyonel marka sitelerine bağlı Search Console hesapları, son 90 gün; aynı sayfa = Google tek sayfa konusu sayıyor). Dönen konu kimliklerinin tüm sorguları kümeye girer. DataForSEO SERP kullanılmaz; birleştirme kararı AI'dadır.
- **State:** CODED + PHPUnit (`QueryRuleEngineTest`, güncellenen `QueriesScreenTest`), `tests/Feature/Queries` SQLite + PostgreSQL. Üretim UAT yok; kurallar operatörün gerçek CSV'siyle genişletilecek. **Operator after deploy:** `migrate` (deploy.sh) → `php artisan moxdop:queries:rules` → Sorgular › CSV indir → dosyayı gönder.

## 2026-11-10 — Sorgular: yer adı içeren sorgular silinir, soru sorguları korunur

- **Yer adı kuralı:** il / ilçe / ülke adı (ekli hâlleri: "ankarada", "çankaya'da"; iki-üç kelimelik adlar) içeren sorgu sepette terim olmadan filtrelenir (`QueryNormalizer::matchingTerm` → `placeIn`): içe aktarma, Bekleyenler ve tarama aynı kuralı kullanır; kütüphanedekiler Silinecekler'e yer adıyla düşer. Gündelik kelimeyle aynı yazılan yer adları (orta, olur, bulanık, kaş, kemer, termal…) `NOT_LOCATION` ile yer sayılmaz; ek yalnız Türkçe ek listesinden ("ortalama" ≠ "orta").
- **Soru sorguları korunur:** `QueryNormalizer::QUESTION_WORDS` içeren terim hiçbir sorguyu silmez, elle / AI ile eklenemez (Filtre sepeti, AI ile planla, AI ile kural üret, Sorgularda tara); göç `2026_11_10_090000_drop_question_filter_terms` sepetteki soru terimlerini ve tam yer adı terimlerini siler. Promptlar: `queries.filter_rules` v3, `queries.plan_filters` v4, `queries.scan_filters` v2.
- **Sorgularda tara:** yer adları artık önerilmez (kural siler); AI kategorisi "Semt / yer" yalnız listede olmayan semt / cadde adları için.
- **Silinecekler › "Kütüphaneyi yeniden tara":** tüm kütüphaneyi sepet, yer kuralı ve eşleme kelimeleriyle yeniden tarar.
- **State:** CODED + PHPUnit (`FilterScanTest`, güncellenen `QueriesScreenTest`, `QueryPipelineTest`), SQLite. Üretim UAT yok. **Operator after deploy:** `migrate` (deploy.sh) → Sorgular › Silinecekler › "Kütüphaneyi yeniden tara" → yer adı satırlarını onayla.

## 2026-11-09 — Filtre sepeti: Sorgularda tara

- **Ne yapar:** Filtre sepeti › "Sorgularda tara" (seçili sektör, yoksa kullanılan her sektör; sektör başına paralel iş, "3 / 7 sektör" ilerleme + Durdur). Kütüphane sorgularının kelimeleri PHP'de sayılır; eşleme kelimeleri (ek toleranslı), genel hizmet kelimeleri, niyet / dolgu kelimeleri, sektörün kendi marka adları, rakamlar ve sepette olanlar hiç önerilmez.
- **Yer adları** (il / ilçe / ülke, ekli hâlleri dahil: "ankarada" → "ankara") AI olmadan bulunur. Kalan kelimeler gösterime göre en fazla 1.200 tanesi, 400'lük partilerle, her biri bir örnek sorguyla küçük bir AI sınıflandırmasına gider (`queries.scan_filters`, ⓘ ile prompt görülür / düzenlenir); yalnız marka / firma, kişi adı, yer adı ve alakasız işaretlenenler döner, listede olmayan kelime atılır. Filtre talimatı kutusu bu taramaya da gider.
- **Onay:** sonuç kategoriye göre (Yer adı, Marka / firma, Kişi adı, Alakasız) kaç sorgu / gösterim sileceği ve örnek sorgularla listelenir; satır veya kategori toplu seçilir, "Seçilenleri sepete ekle" terimleri kaydeder ve tarama başlatır (içeren sorgular Silinecekler'e düşer). AI bağlı değilse yer adları yine önerilir.
- **State:** CODED + PHPUnit (`FilterScanTest`), SQLite + PostgreSQL (`tests/Feature/Queries`). Üretim UAT yok.

## 2026-11-09 — Web sitesi çekimini durdur, gerçek hata ve erişim testi

- **Çekimi durdur:** Website › çekim ekranında çekim sürerken "Çekimi durdur" (onaylı). Yalnız o sitenin aktif çekimleri normal iptal yoluyla durur (kuyruktaki / işçinin tutmadığı adım hemen, çalışan adım güvenli noktasında); bekleyen WordPress yenilemesi serbest kalır, sitenin bekleme (backoff) durumu silinir; ardından yeniden başlatılabilir. `WebsiteCollectionStopper` (aynı mantığı `moxdop:website:reset-collection` de kullanır).
- **Gerçek hata:** "Çekim hızı" satırı beklemede / yavaş modda son somut hatayı da yazar ("son hata: cURL error 28: … timed out", "HTTP 503"); vazgeçme mesajı da hatayı içerir.
- **Zaman aşımı:** sayfa okuma süre sınırı 12 → 20 sn (önbelleksiz yavaş paylaşımlı hosting sayfaları).
- **Erişim testi:** `php artisan moxdop:website:probe https://site/` sunucunun dış IP'si, DNS, 443 bağlantısı, MoxDOP okuyucusu ve tarayıcı gibi okuma; sonuca göre "güvenlik duvarı IP'yi engelliyor" / "kullanıcı ajanı engelli" / "okunabiliyor" der.
- **State:** CODED + PHPUnit (`WebsiteResetCollectionCommandTest`), SQLite. Üretim UAT yok.

## 2026-11-08 — Nazik mod: küçük paylaşımlı hostingte site çekimi ve WordPress Connector 1.5.1

- **Neden:** Connector 1.5.0 kurulu bir müşteri sitesi (paylaşımlı hosting) tekrar tekrar "Error establishing a database connection" verdi, kaynak kullanımı arttı. Kaynaklar: sayfa taraması adım başına 15 sayfayı paralel çekiyordu (website işçi hattı numprocs=2 ile ~30 eşzamanlı sayfa); WordPress anlık görüntüsü 100 yazıyı bloklarıyla işliyordu; eklenti her kayıtta loopback (`spawn_cron`) açıyor, her 5 dk boş outbox için de istek atıyor, her kayıtta `OFFSET 10000` sorgusu çalıştırıyordu.
- **Sayfa taraması (`WebsiteCrawlPoliteness`):** site başına aynı anda 2 sayfa (`crawl.concurrency`, `MOXDOP_CRAWL_CONCURRENCY`), adım başına 6 sayfa (`crawl.batch_size`), adımlar arası en az 2 sn (`crawl.min_delay_seconds`), aynı siteye tüm işçilerde tek adım (host kilidi; meşgulse 30 sn sonra). robots.txt `Crawl-delay` uygulanır (adımda 1 sayfa, o kadar saniye; en çok 30). Site 429 / 502 / 503 / 504, zaman aşımı veya WordPress veritabanı hatası sayfası ("Error establishing a database connection" / "Veritabanı bağlantısı kurulurken hata") verirse adım durur, hiçbir sayfa yazılmaz, 6 saat aynı anda 1 sayfaya iner ve 5 → 15 → 60 dk bekler (checkpoint korunur; Continue + gecikme, deneme hakkı yemez). Tek yavaş / bozuk sayfa üç beklemeden sonra hata olarak kaydedilip geçilir; site 8 beklemeden sonra hâlâ zorlanıyorsa çekim açık nedenle durur (`WEBSITE_HOST_STRUGGLING`). Veritabanı hatası sayfası sayfa içeriği olarak asla saklanmaz (`PublicHttpFetcher`).
- **Ekran (Website › Özet):** "Çekim hızı" satırı: "Nazik mod: aynı anda 2 sayfa · site yavaşlarsa otomatik yavaşlar"; bekleme sırasında "Site yavaş yanıt veriyor (neden); çekim X dk sonra (SS:DD) yavaşça sürecek".
- **WordPress anlık görüntüsü:** içerik / medya sayfası 25 kayıt (diğerleri 50, hiçbiri 50'den fazla), iki sayfa arası 2 sn (`page_delay_seconds`); 429 / 502 / 503 / 504 / veritabanı hatası `WordPressConnectorBusyException`: aynı sayfa Retry-After'dan sonra, sürerse 5 → 15 → 60 dk sonra tekrar istenir; 8 kez sonra durur. Sessiz site eşiği `delivery_stale_hours` (24 sa; eklenti 6 saatte bir sinyal verir).
- **Eklenti 1.5.1:** kayıttan ~60 sn sonra tek gönderim (loopback yok); yedek plan 15 dk (eski 5 dk planı kurulumda taşınır); boş outbox'ta istek yok, son onay 6 saatten eskiyse sinyal; kurulum / `dbDelta` sürüm başına bir kez (init'te sorgu yok); outbox boyut kontrolü saatte bir; snapshot en çok 50 kayıt ve aynı anda tek snapshot (ikincisi 429 + `Retry-After: 30`; atomik `MoxDOP_Connector_Lock`); ön yüzde IndexNow anahtarı yalnız kendi adresinde okunur, yönlendirme / şema seçenekleri autoload.
- **State:** CODED + PHPUnit (`WebsiteProductionCollectorTest` nazik mod / 503 / veritabanı hatası / tek yavaş sayfa, `WordPressConnectorV1Test` 429 Retry-After, `WebsiteCollectionOverviewTest`, `SiteChangePropagationTest` eklenti statik kontrolleri + `php -l`). Gerçek sitede UAT yok. **Operator after deploy:** müşteri sitesinde eklentiyi 1.5.1'e güncelle (MoxDOP'tan tek tık güncelleme veya Entegrasyonlar › Site bağlayıcıları › WordPress › ZIP indir → WordPress'te yükle).

## 2026-11-07 — Silinecekler › Hizmet değişikliği: yalnız kural atamaları, neden görünür

- Tarama hizmet değişikliğini yalnız eşleme kelimesiyle (kural) atanmış kilitsiz sorgular için önerir; operatörün (`manual`) veya AI'ın (`ai`) atadığı hizmet asla değiştirilmez / kaldırılmaz (onayda da kontrol). Satırda karar veren kelime görünür ("eşleşen kelime: X" / "hiçbir eşleme kelimesi eşleşmiyor"). **State:** CODED + PHPUnit (`QueryReviewFlowTest`), SQLite + PostgreSQL.

## 2026-11-07 — Sorgular: kalıcı Silinecekler sekmesi, temiz Bekleyenler, paralel "Hizmet keşfet", toplu hizmet ekleme

- **Silinecekler sekmesi (`/library/queries?tab=deletions`):** her filtre / eşleme kelimesi taramasının önerileri tek kalıcı listede birikir (`query_review_items` artık havuz: sorgu başına tek satır, son tarama kazanır; tam taramanın tekrar önermediği satır düşer). Alt görünümler "Silinecek sorgular · N" (sorgu, filtre terimi, gösterim; terim filtresi) ve "Hizmet değişikliği · M" (mevcut → yeni); arama, sektör, toplu seçim (sayfadaki tümü / filtreye uyan tümü, işareti kaldırılan hariç). "Onayla ve sil" / "Onayla ve uygula": uygulanırken yeniden kontrol (terim hâlâ eşleşiyor mu, sorgu kilitsiz ve hâlâ eski hizmette mi). "Tut": satır listeden çıkar, aynı öneri (aynı terim / aynı hedef hizmet) tekrar gelmez; "Tutulanlar" görünümünde "Geri al". Sekmede sayı rozeti, tarama sürerken "Tarama sürüyor…".
- **Bildirim:** "Filtre taraması hazır: N silinecek, M hizmet değişikliği" (sayılar listenin açık toplamı) sekmeye gider; yeni tarama bildirimi, aynı operatörün okunmamış eski tarama bildirimlerini okundu yapar. Eski `/library/queries/review/{id}` bağlantıları sekmeye yönlenir (tıklama sorununun nedeni: her tarama önceki incelemeyi siliyordu, eski bildirim 404 açıyordu). Ayrı inceleme ekranı (`QueryReviewPage`) kaldırıldı.
- **Bekleyenler temiz:** `PendingQueries::prune` kütüphane karşılaştırmasını kütüphanenin normalize biçimiyle yapar (kayıtlı hash + metnin yeniden normalize edilmiş hash'i + normalize metin; büyük/küçük harf, Türkçe İ/I, boşluk, noktalama). Çalıştığı yerler: her toplama ve tarama, her filtre terimi eklemesi (terim ekle, Filtreye ekle, AI kural / filtre onayı — iş beklemeden), Sorgular açılışında (terim / kütüphane / kuyruk değiştiyse), görünen sayfada her çizimde ve saatlik `moxdop:queries:prune-pending`. İçe aktarma normalize metin + hash yazar.
- **AI ile planla adım 2 / 3 paralel:** sektör başına bir kuyruk işi (`PlanQueriesSectorJob`, heavy kuyruk), sonuç önbellekteki öneriye kilitle birleştirilir; sihirbazda "3 / 7 sektör tamamlandı" ilerleme çubuğu + dönen simge (2 sn yoklama); nihai liste sektör sırasında, filtre terimi sektörler arası tekilleşir, başarısız sektör "Yanıt alınamayan sektörler"de. Filtre sepeti "AI ile oluştur" da aynı yol.
- **Adım 2 toplu ekleme:** öneri listesinde "Tümünü seç / Hiçbirini" ve "Seçilenleri ekle (N)" (işaretli yeni hizmetler tüm kelimeleriyle ve kelime değişiklikleri tek seferde; uygulanan satırlar öneriden çıkar, adım açık kalır); sektör başına "Toplu hizmet ekle" (her satıra bir hizmet, isteğe bağlı `Hizmet: kelime1, kelime2`; mevcut hizmete yalnız kelime eklenir, başka sektörün hizmeti ve sektörde alınmış kelime atlanır ve mesajda listelenir).
- **State:** CODED + PHPUnit (`QueryReviewFlowTest`, `QueryPlanWizardTest`, `tests/Feature/Queries` 50 test), SQLite + PostgreSQL. Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` (`2026_11_07_090000_query_review_items_pool`).

## 2026-11-07 — Marka sayfası: varsayılan "Özet" sekmesi, bilgilendirici kanal sekmeleri

- **Özet (`/brands/{id}`, varsayılan sekme; `BrandOverviewReader`):** başlıkta marka, müşteri bağlantısı, sektör / bölge, Aktif / Pasif (müşteri durumu) ve dönem seçici (28 / 90 gün; mevcut dönem ön ayarı `period=last_28|last_90`). KPI satırı önceki eşit döneme göre %: organik tıklama (Search Console) · web oturumu ve web dönüşümü (GA4, `SiteAnalysisReader`) · reklam harcaması ve reklam dönüşümü (Google Ads `GoogleAdsScreen::overview` + Meta `MetaScreen`; farklı para birimleri toplanmaz) · profil etkileşimi (İşletme Profili: arama + yol tarifi + site tıklaması). Kaynak bağlı değilse kartta "veri yok" + neden + düzeltme bağlantısı, bağlı ama veri gelmediyse "Bağlı · veri henüz gelmedi"; 0 gösterilmez. Rakamlar marka × dönem × bağlı hesaplar başına 10 dk önbellekte. "Dijital varlıklar" ızgarası: her varlık (alan adı / hosting hariç) tip simgesi, ad, kaynak başına `DataStatusReader` durumu (Güncel / Gecikmiş / İlk veri yükleniyor / Erişim sorunu / Bağlı değil + son veri, son toplama) ve düzeltme (Kaynağı bağla, Yeniden bağla, Verileri yenile), "Ekranı aç" ve "Veri kaynakları"; "Hesap bağla" / "Varlık ekle". "Açık işler": markanın açık (ve ertelemesi dolmuş) önerileri, öncelik sırasıyla, kanal sayıları ve öneriyi açacak varlık ekranı bağlantısı. "Hizmetler": sayı, öncelikli, sitede sayfası eşlenmiş (offering_pages) hizmetler → Ayarlar › İşletme. Kurulum eksikse tek satır uyarı + Otomatik kur.
- **Kanal sekmeleri (Arama · Harita · Google Ads · Meta):** kanal bileşeni henüz yokken "Hazırlanıyor" ve boş "Bu hafta yapılacaklar" yerine kanalı besleyen varlıkların veri durumu kartları, varlık yoksa "Markaya bağlı … yok" + Hesap bağla / Varlık ekle, kanalın açık önerileri. Ayarlar aynı; alt sekme "Genel bakış" adı "Kurulum" oldu. Eski `?tab=` bağlantıları çalışır; bilinmeyen sekme Özet'e düşer.
- **State:** CODED + PHPUnit (`tests/Feature/Portfolio/BrandOverviewTest`, `BrandOverviewMetaTest`, güncellenen `PanelDesignFreezeTest`, `DemoProductRoutesTest`), SQLite + PostgreSQL. Üretim UAT yok.

## 2026-11-07 — AI işleri sayfası: sıradaki / çalışan / geçmiş AI işleri, ayrıntı, durdur, sil

- **Kayıt:** `ai_live_operations` artık iki tür satır tutar: ajan çağrısı (`call`) ve kuyruğa alınmış AI işi (`job`). Bilinen AI işleri (`AiJobCatalog`: AI ile planla, hizmet öner, kümele, kural üret, hizmet keşfi, otomatik kur, marka adayları, yorum yanıtı, AI yorumu, kanal analisti, GBP / Google Ads / Meta asistanları, prompt denemesi, rakip analizi / güncelleme, backlink kaynakları, web sitesi AI işlemleri) ya da `TracksAiJob` uygulayan işler `JobQueued` ile "Sırada" satırı açar; `JobProcessing` → Çalışıyor, `JobProcessed` / `JobExceptionOccurred` → Bitti / Hata (tekrar denenecekse yeniden Sırada) / Durduruldu (`AiJobTracker`). İş içindeki çağrılar işe bağlanır (`parent_id`); işin maliyeti / token'ı çağrılarının toplamıdır. Çağrı satırı girdinin ve çıktının (yapısal çıktı JSON) ilk 64 KB'ını, prompt sürümünü, sağlayıcı / modeli, token'ı saklar.
- **Sayfa `/ai-jobs` (`operator.ai-jobs`, `AiJobsPage`):** durum / işlem / kullanıcı / tarih filtresi, 25'lik sayfalama; satır: işlem, konu (marka / site / sektör), kullanıcı, başlangıç, süre, maliyet, durum. Ayrıntı paneli (`?is=ID`): amaç, prompt sürümü, girdi, çıktı / hata, sağlayıcı / model, token, maliyet, zamanlar, kuyruk işi, bağlı iş ve çağrıları, "Sonucun kullanıldığı yer" bağlantısı. Bağlantılar: üst çubuk öğeleri (çalışan / sırada / son biten) ve "Tümünü gör", Ayarlar › AI (buton), Ayarlar › AI işlemleri › Canlı.
- **Durdur (Admin):** sıradaki iş veritabanı kuyruğundan silinir (başka sürücüde satır iptal edilir, işçi işi çalıştırmadan siler); çalışan işe `cancel_requested_at` konur ("Durdurma isteği gönderildi" / "Durduruluyor"). İş sonraki AI çağrısından önce durur: `AiCancellation::throwIfRequested()` (QueryPlanner, QueryServiceAssigner, SiteAi, CompetitorClassifier, BrandCandidateBuilder çağrı başında) ve her `PromptingAgent`; sürmekte olan çağrının sonucu atılır (`AgentPrompted` sonrası). `AiCancelledException` iş hattında (`Bus::pipeThrough`) yutulur: iş hata / tekrar deneme olmadan biter, satır "Durduruldu". Sayfa isteği içindeki çağrılar durdurulamaz.
- **Sil (Admin):** seçilenler ve "N günden eski" (çalışan / sıradaki silinmez; işle birlikte çağrıları). Saklama 30 gün (`moxdop-retention.telemetry.ai_live_operations`, `MOXDOP_RETENTION_AI_JOBS_DAYS`).
- **State:** CODED + PHPUnit (`AiJobsTest`, `AiLiveOperationsTest`), SQLite + PostgreSQL. Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force`.

## 2026-11-06 — Takılı iptal kendiliğinden biter

- Çalışan işçisi (deploy'da yeniden başlatma vb.) ölen bir çekimin iptali "Durduruluyor"da kalıyordu ve sitenin yeni çekimini (WordPress envanteri) engelliyordu. `RecoverInterruptedCollections` (her dakika) artık kilidi ve son hareketi 10 dakikadan eski olan, iptali istenmiş çekimin adımlarını iptal edip çekimi kapatır. **State:** CODED + PHPUnit (`InterruptedCollectionRecoveryTest`), SQLite + PostgreSQL.

## 2026-11-06 — Sorgular: hizmet ataması kuyruğu, toplu seçim, temiz Bekleyenler, filtre talimatı

- **Hizmet ataması kuyruğu:** Sorgular sekmesinde "Hizmet ataması kuyruğu · N atanmamış sorgu" (tıklayınca Atanmamış filtresi) ve "AI ile hizmet öner": gizli olmayan, önerilmiş olmayan, sektörü olan atanmamış sorgular (seçili sektör, yoksa hepsi) kuyrukta 200'lük partilerle, sektör sektör (sektörün hizmetleri + eşleme kelimeleriyle) AI'a gider (`queries.assign_services`, prompt v1); tüm partiler işlenir (iş zaman bütçesinde kendini imleçten yeniden kuyruğa alır), ilerleme "x / y sorgu". Doğrulama: partide olmayan / bilinmeyen sorgu id'si, başka sektörün hizmeti, tekrar düşer; önerilen eşleme kelimeleri (genel değil, partide geçen, sektörde boşta) ayrıca işaretli satır. Bitince bildirim "Hizmet önerisi hazır: N sorgu". Liste (sorgu → önerilen hizmet · neden) 100'lük sayfalarda, varsayılan hepsi işaretli, "Tümünü seç / Hiçbirini seçme"; "Onayla" tek tıkla uygular (yalnız hâlâ atanmamış sorgular, `assignment=ai`, kilitli); kelime eklendiyse onaylı tarama başlar.
- **Toplu seçim:** satır kutusu, "Sayfadaki tümünü seç", "Filtreye uyan tümünü seç" (tüm sayfalar; filtre sorgusu olarak uygulanır, id listesi tutulmaz; işareti kaldırılan satırlar hariç tutulur), "Seçimi temizle". Toplu işlemler: Hizmete ata (kilitli), Hizmeti kaldır (kilitli, kümeden çıkar), Sil (gizle) / Geri al, Filtreye ekle ve AI ile filtre kural üret (seçimin en çok gösterimli 200'ü). Hizmet filtresine "Atanmış" eklendi (Atanmamış vardı).
- **Bekleyenler:** yalnız kütüphanede olmayan ve hiçbir filtre teriminin silmeyeceği sorgular. Filtreye takılan metin kuyruğa hiç girmez; her toplama ve her filtre / kelime taramasında (`RescanQueriesJob`) bekleyenler yeniden kontrol edilir (kütüphanedeki veya filtreye takılan satır çıkar; terim silinirse sonraki toplamada geri gelir). "silinecek / temiz" sütunu kalktı; sayaç aynı koşulla.
- **Filtre talimatı:** "AI ile planla" adım 3 ve Filtre sepeti sekmesinde "AI ile oluştur" yanında talimat alanı ("iş ilanı ve eğitim içerikli kelimeler üret"); talimat kayıtlı prompta ek olarak her sektör çağrısında DATA_JSON `operator_instruction` alanıyla gider (`queries.plan_filters` prompt v3). Filtre sepeti sekmesi öneriyi işaretli liste olarak gösterir (seçili sektör, yoksa kullanılan sektörler); "Seçilenleri kaydet" ilk içe aktarmadan sonra onaylı tarama başlatır.
- **State:** CODED + PHPUnit (`QueryBulkAndAssignTest`, `QueryReviewFlowTest`, `tests/Feature/Queries`), SQLite + PostgreSQL. Üretim UAT yok.

## 2026-11-06 — Canlı AI işlemleri ve prompt bilgi (ⓘ) butonu

- **Canlı AI işlemleri:** her laravel/ai ajan çağrısı `PromptingAgent`/`StreamingAgent` ile "çalışıyor" satırı açar, `AgentPrompted`/`AgentStreamed` ile "bitti" (süre, maliyet), `AgentFailedOver` ile "başarısız" kapatır; hata fırlatan çağrılar istek / iş / komut bitince (son sağlayıcı HTTP hatası ya da iş istisnası metniyle) başarısız kapanır, 30 dk'dan eski açık satırlar zaman aşımıyla kapanır. Kullanıcı (kuyruğa da taşınır), kısa konu (düz metin prompt başı; veri paketlerinde yok) ve Türkçe işlem adı tutulur. `ai_live_operations` 7 gün (veri saklama, telemetri).
- **Üst çubuk "AI · N":** yalnız çalışan ya da son 5 dakikada biten işlem varken görünür; çalışırken 5 sn, değilse 30 sn yoklama; açılır listede çalışanlar + son 10 biten, Admin için Ayarlar › AI işlemleri › Canlı bağlantısı. Ayarlar sayfasında "Canlı" bölümü (çalışan + son 20) ve detayda "Varsayılana dön".
- **ⓘ prompt bilgisi:** tek modal (`AiPromptInfo`, düzende bir kez) + buton bileşeni; amaç, güncel şablon, sürüm, varsayılan / düzenlenmiş, model. Admin "Düzenle" → `PromptRegistry::publish` (yeni sürüm), "Varsayılana dön" → `PromptRegistry::resetToDefault`; Admin olmayan yalnız görür. Yerleşim: AI ile planla (3 adım), Sorgular (kümele, filtre kuralı, AI ile düzenle), Google Ads (arama terimleri ×2, kampanya yapısı, reklam metni), Meta (3 AI butonu), İşletme Profili (hizmet karşılaştır, açıklama, yorum yanıt taslağı ×2, sayfadan gönderi), marka ayarları (hizmet çıkar), marka adayları (yeniden grupla), web sitesi (sınıflandır, hizmet ↔ sayfa, küme ↔ sayfa, URL analizi ×2, AI ile yap ×2, standart öner, haftalık içerik, fırsat keşfi, taslak ×2, rakipleri güncelle, rakip analizi, backlink kaynak).
- **State:** CODED + PHPUnit (`AiLiveOperationsTest`, `AiPromptInfoTest`), SQLite + PostgreSQL. Kuyruğa alınmış işlerin "Sırada" durumu yok (yalnız çalışıyor / bitti / başarısız). Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force`.

## 2026-11-06 — Web sitesi ekranı yeniden düzenlendi: Özet · Sayfalar · Sorgular · Sağlık · Ayarlar

- **Sekmeler (`/assets/website/{id}`):** Özet (dönem 28 / 90 gün: Search Console tıklama / gösterim + GA4 oturum / anahtar etkinlik, önceki döneme göre %, günlük çizgi; ana hizmet sayfaları iyi · düşüşte · sorunlu sayıları + liste; ilk 5 açık iş; ikinci satırda Öneriler · İçerik · Rakipler · Backlinkler) · **Sayfalar** (URL başına tek satır) · Sorgular (Kümeler · Hedef sorgular · Sorgular · Dönüşümler · Kümeler & Sayfalar eşleştirme) · Sağlık (mevcut) · Ayarlar (mevcut ayarlar + bağlı varlıklar + son veri toplama satırı, "Şimdi güncelle" mevcut çekim ekranına gider — yeni çekim yok). Eski sekme kimlikleri (`genel`, `seo`+`sub`, `analiz`, `varliklar`, `health`, `ga4_analysis`, `pages` …) yeni görünümlere yönlenir.
- **Sayfalar (`SitePagesReader`):** envanter (`pages` = WordPress + sitemap, `website_url`) × Search Console (tıklama, gösterim, sıra, Δ önceki dönem) × GA4 (oturum, anahtar etkinlik) × Google Ads açılış sayfası (markanın Ads hesapları, yalnız bu sitenin host'u; tıklama / maliyet) × sağlık (her URL'nin son HTTP / meta gözlemi: durum kodu, noindex, o alımın tarama sorunları) × hizmet (offering_pages) / küme (brand_cluster_pages). Filtreler: Ana hizmet sayfaları (varsayılan: hizmet / kümeye bağlı ya da kategori hizmet) · Tüm · Trafik almayan · Sorunlu (HTTP ≥ 400, noindex, kritik / yüksek sorun) · Düşüşte (önceki dönem ≥ 5 tıklama ve %20+ düşüş); arama (Türkçe İ katlamalı), sıralama, sayfalama. Birleşik liste site × dönem × son veri günü başına 30 dk önbellekte. Satır → sayfa detayı (çekmece): dönem sorguları, 90 günlük tıklama / oturum çizgisi, GA4 kaynak / ortam + dönüşüm, teknik sorunlar, iç bağlantı gelen / giden, ilgili öneriler ve Öneriler (WordPress'e uygula) bağlantısı.
- **Erişim:** sol menüde "Web siteleri" (`/websites`: markaya atanmış siteler, marka, 28 gün organik tıklama, açık iş → site ekranı); marka sayfası başlığında "Siteyi aç"; üst aramada alan adıyla site bulunur (site ekranına gider); Entegrasyonlar › Web siteleri satırında ve detayında "Site ekranına git".
- **State:** CODED + PHPUnit (`tests/Feature/Site/SitePagesTest`, güncellenen `SiteSuggestionsTest`, `PanelDesignFreezeTest`), SQLite + PostgreSQL (`SitePagesTest`, `AnalysisTest`). Üretim UAT yok.

## 2026-11-06 — Web sitesi çekimi: son durum depolama, önce WordPress, paralel tarama, ayrı işçi

- **Son durum:** Değişmeyen sayfa (aynı durum kodu, son URL, hata ve gövde özeti) yeniden yazılmaz; sayfa tablolarındaki (HTTP, HTML, metadata, başlık, schema, içerik, tarama sorunu, bağlantı) son satırları yeni gözleme taşınır (`WebsitePageStateStore`). Değişen sayfa yeni satır yazar (HTML geçmişi kalır); sayfanın bağlantı kenarları eklenmez, değiştirilir. HTTP satırı gövde özetini (`metadata.body_sha256`) taşır.
- **Önce WordPress:** WordPress bağlı sitede "Genel çekim" (ve ilk eşleştirmedeki otomatik envanter) önce yalnız WP_REST envanterini çalıştırır; bitince (`StartWebsiteCrawlAfterWordPress`) sayfa HTML taraması başlar, sayfa listesi WordPress'ten gelir. İptal edilen envanter taramayı başlatmaz. Otomatik WordPress yenilemesi artık `system` tetikleyicisiyle planlanır (önceden `incremental` olduğu için tüm aileler "uygun değil" planlanıyordu).
- **Hız:** Tarama adımı 15 URL'yi paralel alır (`PublicHttpFetcher::fetchMany`, her yönlendirmede aynı güvenlik / boyut kontrolleri); checkpoint yalnız tüm sayfalar yazılınca ilerler, tekrar deneme idempotent. Web sitesi sağlayıcıları (`WEBSITE_DIRECT`, `DOMAIN_DNS_TLS`, `PAGESPEED_TECHNICAL`, `WORDPRESS_SITE_CONNECTOR`) ayrı işçi hattında (2 süreç); `moxdop:collection:work-db --provider` / `--exclude-provider` virgüllü liste alır. `deploy/staging/deploy.sh` hattı otomatik kurar.
- **Ekran:** Genel bakış üç satır: "Sayfalar: 330 / 1.849 alındı · tahmini …", "WordPress: N sayfa (son envanter …)", "Son çekim: …". Dataset / kayıt / paket sayaçları genel bakıştan kalktı (Veri Kaynakları, Çekimler, Toplanan Veriler sekmelerinde duruyor).
- **Tek seferlik temizlik:** `php artisan moxdop:website:reset-collection --force` aktif web sitesi çekimlerini iptal eder, tüm `website_link_edge` satırlarını siler, sayfa tablolarında sayfa başına yalnız son satırı (tarama sorununda sayfa + kod) ve yalnız silinen HTML satırlarının kullandığı saklı HTML dosyalarını siler.
- **State:** CODED + PHPUnit (`WebsiteProductionCollectorTest`, `WebsiteResetCollectionCommandTest`, `WebsiteCollectionOverviewTest`, `WordPressConnectorV1Test`), SQLite. PostgreSQL ve üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` (deploy.sh), `php artisan moxdop:website:reset-collection --force`, `php artisan moxdop:db:reclaim --execute`.

## 2026-11-05 — Web sitesi çekimi sadeleşti; yalnız markaya atanmış varlıklar çekilir

- **Otomatik public tarama yok.** Saatlik sitemap izleyicisi artık tarama başlatmaz; yalnız markaya atanmış, WordPress Connector'lı ve sitemap geçersiz kılması olan siteleri izler (ek URL listesi). Connector'sız site yalnız elle "Genel çekim" ile toplanır.
- **WordPress bağlı sitede sayfa listesi WordPress'ten (+ sitemap) gelir;** sayfadaki bağlantılar izlenerek yeni URL keşfedilmez. Elle çekimde (`refetch_unchanged=false`) son alımdan beri değişmeyen sayfalar yeniden indirilmez / yazılmaz. Menü / header / footer bağlantıları yalnız ana sayfadan bir kez kaydedilir (iç sayfalarda yalnız içerik bağlantıları) — kayıt sayısını düşürür. Etiketler: "Public Site Taraması" → "Sayfa HTML taraması", "Dışarıdan HTML ve TLS" → "Sayfa HTML ve TLS".
- **Yalnız markaya atanmış varlıklar çekilir.** `ResourceAutomationService::portfolioGate`: hesap, markası olan bir dijital varlığa aktif bağlı değilse `unbound` bekler (uyarı yok) ve atanınca kendiliğinden başlar (pasif müşteri çekmeye devam eder). WordPress uzlaştırması ve Meta ülke/şehir çekimi de yalnız markası olan varlıklar için.
- **State:** CODED + PHPUnit (`WebsiteProductionCollectorTest`, `WebsitePageAnalyzerTest`, `ResourceAutomationRecoveryTest`, `PassiveCustomerGateTest`, `SiteChangePropagationTest`, `ProductionCollectionRepairTest`, `MetaCentralCollectionTest`), SQLite tam suite + PostgreSQL. Üretim UAT yok.

## 2026-11-05 — AI: kalan bakiye görünür, işlem sınırı yok; AI ile planla sektör başına kapsamlı

- **İşlem başına sınır yok; aylık bütçe tek (son) sınır.** Aylık AI bütçesi Ayarlar › AI'da elle değiştirilir; aynı yerde "Bu ay harcanan" ve "Kalan bakiye". Bakiye bitince ay sonuna kadar yalnız ücretsiz modeller çalışır.
- **AI ile planla:** adım 2 (`queries.plan_services`, prompt v2) ve adım 3 (`queries.plan_filters`, v2) sektör başına ayrı çağrı; adım 1 (`queries.plan_sectors`, v2) 25 markalık partiler. Hizmetsiz / verisiz sektör de tam katalog alır (sektör bilgisi: 10–30 hizmet, hizmet başına 5–15 kelime); kelimesiz her hizmete kelime. Kelime / terim artık örnekte geçmek zorunda değil (genel kelime, tekrar, bilinmeyen id hâlâ düşer; filtre terimi hiçbir sektörün eşleme kelimesini yakalamamalı). Yanıt alınamayan sektörler öneri başlığında listelenir; iş zaman aşımı 900 sn. Diğer AI öneri üst sınırları büyütüldü (kural önerisi 300, URL analizi 30, rakip 15, negatif 100).
- **State:** CODED + PHPUnit (`QueryPlanWizardTest`, `AiCostControlTest`, `PromptRegistryTest`), SQLite tam suite + PostgreSQL. Üretim UAT yok.

## 2026-11-04 — Sorgular: AI ile planla, negatif filtre, bekleyen sorgular, onaylı tarama

- **Sektör:** marka sektörü varlıklara geçer; varlığın kendi sektörü olabilir (`digital_assets.sector_id`, `DigitalAsset::sector()` = kendi ?? marka). Sorgu hattı hesap sektörünü varlıktan okur; Bugün ve İşletme Profili ayarlarında varlığın etkin sektörü görünür.
- **AI ile planla** (`/library/queries/plan`, Sorgular sağ üst): 1) Hesaplar ve sektörler — her marka (müşterisiyle) ve varlıkları (tür · ad · hesap no) sektör seçimiyle; markaya bağlı olmayan keşfedilen hesaplar salt okunur. "AI ile sektör ata" tek toplu çağrı (`queries.plan_sectors`), yalnız sektörü boş operasyonel markalar; öneri seçili gelir, "Yeni sektör: X" kabul edilince sektör oluşur; "Onayla ve devam" öncesi hiçbir şey kaydedilmez. 2) Hizmetler ve eşleme kelimeleri — satır içi hizmet / kelime ekle-sil (kelime sektörde tek hizmete ait); "AI ile hizmet keşfet" tek çağrı (`queries.plan_services`): eksik hizmet, kelime ekle / sil / taşı, işaretli liste. 3) Sektör filtre sepeti — "AI ile oluştur" tek çağrı (`queries.plan_filters`); "Onayla ve içe aktar" = ilk (tek otomatik) içe aktarma. AI çıktısı doğrulanır: bilinmeyen id düşer, kelime / terim örneklerde geçmeli, filtre terimi sektörün eşleme kelimesini yakalamamalı.
- **Negatif filtre:** filtre terimi içeren sorgu tamamen silinir (terim sorgudan çıkarılmaz); terimler sektöre göre düzenlenir ama tüm sektörlerin terimleri tüm sorgulara uygulanır. Normalleştirme yalnız küçük harf / kırpma / tek boşluk.
- **İlk içe aktarma** (`agency_settings.queries_imported_at`): markalara bağlı tüm hesapların sorguları filtreden geçer, normalleştirilmiş metin başına tek kayıt, eşleme kelimeleriyle hizmete atanır; bitince bildirim.
- **Bekleyenler** (sekme + sayaç, `pending_queries`): sonraki toplamalarda yeni sorgular kütüphaneye girmez; hesap (marka · varlık) · sorgu · önerilen hizmet · filtre sonucu (silinecek / temiz). Kütüphanedeki sorgular görünmez, kütüphane sorgularının metrikleri her toplamada güncellenir. "Seçilenleri içe aktar" (varsayılan temizler), "Yoksay" (hatırlanır).
- **Filtreye ekle + onaylı tarama:** seçili sorgular → terim listesi (satır başına bir), sektör, "AI ile düzenle" (`queries.filter_rules` v2: en kısa negatif terimler), her terimin yakalayacağı diğer sorgular; "Kaydet" → kuyrukta tarama (tüm terimler × tüm sorgular + eşleme kelimeleri; elle / kilitli küme atamaları dokunulmaz) → bildirim "Filtre taraması hazır: N silinecek, M hizmet değişikliği" → onay ekranı (`/library/queries/review/{id}`: Silinecek sorgular + Hizmeti değişecek sorgular, varsayılan hepsi işaretli, "Onayla" yalnız işaretlileri uygular). Eşleme kelimesi değişikliği (Eşleme kelimeleri sekmesi, Hizmet kataloğu, planlayıcı adım 2, "AI ile filtre kural üret") da aynı incelemeden geçer; sessiz silme / yeniden atama yok. Silinen sorgu kümeden çıkar, kaynakları bağsız kalır, metni hatırlanır (Bekleyenler'e dönmez).
- **Bildirimler:** zil okunmamış sayısını gösterir; bildirime tıklamak hedefini açar ve okundu yapar. Önemli bildirimler (tarama hazır, ilk içe aktarma bitti, AI adımı başarısız) solda bir kez 8 sn toast (60 sn yoklama, `user_notifications.toasted_at`).
- **State:** CODED + PHPUnit (`tests/Feature/Queries/QueryPlanWizardTest`, `QueryReviewFlowTest`, `QueryPipelineTest`, `QueriesScreenTest`), SQLite + PostgreSQL. Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force`, sonra Sorgular › AI ile planla (ilk içe aktarma onaylanana kadar yeni sorgu Bekleyenler'e düşmez).

## 2026-11-03 — v2 düzeltmeleri: web sitesi ekranı

- **Küme ↔ URL:** her site dili ayrı satır (yalnız ana dil değil); operatör kümeye ek URL ekler (`brand_cluster_pages.extra_page_ids`, kilitli). URL analizi ek URL'de de kümeyi görür.
- **URL analizi paketi:** kümenin seçili rakip örnekleri (ilk 3 çekilmiş ticari / bilgi sayfası: URL, başlık, H2 + analizdeki ihtiyaç) ve markanın hedef kitlesi / pazarları; yoksa "veri yok".
- **Standartlar:** kütüphanede karar standardı (koşul · istisnalar · kapsam · sürüm) gösterilir; Admin "Düzenle" → yeni sürüm, önceki sürümler geçmişte (en çok 20).
- **Marka hafızası:** karar satırı uygulama tarihini ve 28 / 56 gün sonuçlarını da tutar (`OutcomeTracker` apply / measure); sayfa hafızası H2 bölümlerini tutar. AI'ya yalnız ilgili kısım gider.
- **Yeniden kontrol:** içerik değişikliği, URL değişikliği, sayfa silinmesi (sayfa bağı düşmeden önce) ve ortak şablon değişikliği açık **ve onaylı-uygulanmamış** önerileri "yeniden kontrol gerekli" yapar; sayfası silinen öneri Öneriler'de kalır.
- **Rakipler:** öneride Onayla / Reddet; sayfamız varsa "AI ile yap" (yeni sürüm Öneriler'de), yoksa "Yeni içerik olarak ekle" (İçerik planı) → "Taslak hazırla". Liste SQL'de siteye göre süzülür.
- **Backlink durumları (tam beş):** henüz tespit edilmedi · başvuru / iletişim yapıldı · kullanıcı eklediğini bildirdi · sayfada doğrulandı · daha sonra kaldırıldı (gerçek durum). Operatör başvuru ve verildi'yi işaretler; eski "kaldırıldı" notlu satırlar taşındı. Kullanılmayan `dataforseo` backlink kaynağı kaldırıldı.
- **Analiz › Hedef sorgular:** sorgu · bölge · tık · gösterim · sıra · URL; `brand_queries` doluysa oradan, değilse kümelerin hedef sorgusu + Search Console.
- **Hız:** sayfa toplamları ve site tıkları 10 dk önbellek (eşleştirme / haftalık yenileme temizler); küme satırları sayfalı; hedef URL seçenekleri aramayla en çok 50.
- **Sitemap:** WordPress Connector sitesinde Ayarlar'daki sitemap adresi, WordPress envanterinde olmayan yollar için ek kaynak (en çok 500 URL); tur başına çekim 200. Eski yönlendirilmeyen `Demo\Website\OverviewPage` ve görünümü silindi.
- **State:** CODED + PHPUnit (`tests/Feature/Site/SiteScreenFixesTest`, `BacklinksTest`; eski sayfayı kullanan 9 test v2 ekranına uyarlandı). SQLite + PostgreSQL. Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force`.

## 2026-11-03 — v2 düzeltmeleri: sorgular ve kümeler

- **Marka katmanı gerçek:** `brand_queries` artık hedef bölge / dil / URL yazar (`QueryPipeline::brandTargets`, tek yer; pipeline sonunda, küme / marka düzenlemesinde ve Küme ↔ sayfa eşlemesinden sonra çalışır). Markanın aktif hizmetlerinin onaylı kümeleri (ana hizmet önce, markada hariç olanlar dışarıda): ticari / yerel niyette ana sorgu her hizmet bölgesi için bir satır ("ankara implant merkezi"), bilgi niyetinde bölgesiz tek satır. Dil = marka dili (ilk), yoksa sitenin ana sayfa dili. URL = kümenin `brand_cluster_pages` sayfası. Bölgesiz satır hedef değilse ve 28 günde sayısı yoksa silinir. İçerik keşfi yalnız bölgesiz satırları okur.
- **Küme alanları:** `user_need`, `exclusions` (dahil edilmeyecek konular), `version` (her kayıtlı düzenlemede +1). Çekmecede niyet, sayfa tipi, ad, ana sorgu, temsil sorguları (en çok 3), alt konular, dahil edilmeyecekler, kullanıcı ihtiyacı düzenlenir; temsil sorguları listede görünür. `queries.cluster` promptu (queries-cluster-v2) + şema + doğrulama `user_need` / `exclusions` üretir.
- **Elle:** kümeye tek sorgu ekleme (kümesiz sorgu da; bilinmeyen metin "önerilen" olarak kalır), kümeden çıkarma (gerçek sorgu kümesiz kalır, önerilen silinir); "Gizlenenler" filtresi + "Geri al"; düğme "AI ile filtre kural üret"; hiçbir öneri seçilmezse / kaydedilen yoksa yeniden işleme kuyruğa alınmaz.
- **Ortak vs markaya özel:** ortak kümeyi kullanan (aktif hizmetinde küme hizmeti olan) markalar çekmecede listelenir; ortak düzenleme "Ortak kütüphaneyi düzenle" onayı ister (`ClusterEditor`, tek yer). "Bu markaya özel düzenle" yalnız `brand_cluster_pages`: hedef sorgu (`target_query_override`), URL, markada hariç (`excluded`); ortak küme değişmez. Site ekranı Kümeler & Sayfalar da aynı `ClusterEditor::brandRow` ile yazar.
- **Eşleme kelimesi tekliği yarışa dayanıklı:** kapsamdaki tüm hizmet satırları (sektör + sektörsüz) id sırasıyla kilitlenir; sektörsüz hizmet global sayılır (tüm hizmetlerle çakışma kontrolü).
- **İşletme Profili arama kelimeleri** toplama olayıyla da `query_sources`'a gider (`AggregateQuerySourcesAfterCollection::QUERY_DATASETS`). Önerilen sorgu gerçek veri alınca `cluster_queries.is_suggested` da temizlenir. `config/moxdop-queries.php` yalnız `product_brands` (eski göç okur). Olay keşfi kapalı (`withEvents(discover: false)`); tüm dinleyiciler `AppServiceProvider` içinde bir kez kayıtlı (önceden her dinleyici iki kez çalışıyordu).
- **Yapılmadı:** `queries.volume` (entegrasyon işi). Veritabanında saklı `queries.cluster` prompt sürümü kendiliğinden güncellenmez; Ayarlar › Promptlar'dan yeni sürüm yayımlanmalı (şema yeni alanları yine ister).
- **State:** CODED + PHPUnit (`tests/Feature/Queries/ClusterBrandLayerTest`, `QueriesScreenTest`, `QuerySourceAggregatorTest`, `SiteMappingTest`), değişen test dosyaları PostgreSQL'de de koşuldu. Üretim UAT yok.

## 2026-11-03 — v2 düzeltmeleri: reklamlar, entegrasyonlar, promptlar

- **Meta: bağsız hesaplar da toplanıyor** (`MetaCentralCollectionService`, GA4 / Google Ads merkezi toplayıcılarının eşi): kaynak ilk (`provider_resource_first`) çalıştırma, varlık / bağlama yok; ilk sefer ailenin geçmiş penceresi, sonra kapsama sonundan (en çok son 7 gün geç atıf) dünkü güne. `ResourceAutomationService::readiness()` Meta için artık `binding` döndürmüyor; eski `binding` bekleyenler yeniden sıraya girer. V1 Meta tabloları (hesap / kampanya / set / kreatif anlık görüntüleri, kampanya / set / reklam günlükleri, tipli eylemler) kaynak anahtarlı (`external_resource_id`); migration aynı hesabın iki varlıkla yazılmış kopyalarını tekler. Sıkıştırılmış tablolar kayıtlı düzen anahtarını korur. Meta kullanım sınırı bekleme süresi (`MetaUsageGovernor`) aynı.
- **DataForSEO arama hacmi bağlandı:** `moxdop:intel:query-volumes` (ayda bir, 4'ü 05:37 Europe/Istanbul) operasyonel markaların kullandığı kümelerin ana sorguları (`queries.volume`) ve marka hedef sorguları (`brand_cluster_pages.target_query`, yalnız `query_volumes`) için; AI önerisi ana sorguya hacim yazılmaz; 90 gün önbellek + harcama tavanı. Konum `moxdop-intel.query_volumes` (Türkiye).
- **Ölü DataForSEO kodu kaldırıldı:** `DataForSeoEnrichmentOrchestrator`, ücretli Labs aileleri (ranked keywords, keywords for site, competitor domains) ve normalizer'ı; `moxdop:intel:collect` + görev kuyruğu yoklaması (harita grid / yorum / prospect) ve `DataForSeoTaskHandler`. `DataForSeoTaskQueue` yalnız canlı çağrı maliyet kaydı.
- **Google Ads dil kontrolü:** kampanya dil hedeflemesi (campaign_criterion LANGUAGE) `google_ads_campaign_snapshot.language_codes`'a toplanıyor ("all" = tüm diller); "Hedef bölge ve dil" kontrolü markanın dilleriyle karşılaştırır, "veri yok" yalnız bölge ve dil ikisi de bilinmiyorsa.
- **Google Ads dönüşüm gecikmesi:** maliyet / dönüşüm sapması kontrolü ve yapı / bütçe AI paketi son gecikme penceresini dışarıda bırakır (birincil dönüşümlerin tıklama sonrası penceresi, en çok 14 gün; toplanmadıysa 7 gün) ve kanıtta yazar.
- **Google Ads "Uygulandı" zamanı:** Editor dosyası indirmek artık uygulamaz; her indirilen dosya "Editor'a aktardım" ile uygulanır (baseline o an). Paylaşılan listeye negatifler yalnız Google yazımı başarılı olunca uygulanır; başarısızsa yeniden gönderilebilir.
- **Google Ads lead kalitesi:** Ölçümleme'de kampanya × ay elle form geldi / uygun / randevu / satış (`google_ads_lead_quality`); Google Ads dönüşümünün yanında, asla toplanmaz; yapı / bütçe AI paketine girer. Meta CSV lead işaretleme aynı.
- **Meta dil uyumu:** reklam seti `locales` kimlikleri (tr / en / de / ar / ru / fr haritası) markanın dilleriyle "Bölge ve dil uyumu" kontrolünde; bilinmeyen kimlik "veri yok". CAPI sunucu olayı durumu okunmuyor ("veri yok").
- **Promptlar "Örnekte dene"** (Admin): kayıtlı prompt işlemlerinin son girdileri `ai_usage_records.input_text`'te 90 gün (`moxdop:retention` siler); taslak (yayınlanmamış) şablon aynı ajan / şema ile bir kez kuyrukta çalışır, çıktı + süre + maliyet gösterilir, öneri kaydedilmez, maliyet `prompt_trial` olarak sayılır.
- **İşletme Profili:** yorum yanıtı arşivi registry prompt sürüm kimliğini yazar; Onayla → "Uygulanacaklar", **Uygulandı** → uygulandı + baseline (Meta gibi).
- **State:** CODED + PHPUnit (`MetaCentralCollectionTest`, `QueryVolumeRefreshTest`, `GoogleAdsScreenTest`, `MetaScreenTest`, `PromptRegistryTest`, `GbpWorkspaceTabsTest`, `ReviewReplyDraftTest`, `V2DatasetCatalogueTest`, `ResourceAutomationRecoveryTest`; değişen dosyalar PostgreSQL'de de). Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force`; scheduler `moxdop:intel:query-volumes` çalıştırır.

## 2026-11-03 — v2 temizlik: eski sayfalar kaldırıldı

- **Operatör kararı:** ürün yalnız v2 menüsü (Bugün · Müşteriler · Markalar · Sorgular · Entegrasyonlar · Ayarlar), varlık ekranları (Site, Google Ads, Meta, İşletme Profili, GA4 / Search Console), müşteri / marka ekranları, marka adayı onayı, entegrasyon sayfaları ve Ayarlar sekmeleridir. Geri kalan operatör sayfaları koddan çıktı; veritabanı tabloları duruyor (yıkıcı migration yok).
- **Kaldırılan rotalar (eski URL → `/` Bugün'e yönlenir):** `/opportunities`, `/findings`, `/recommendations`, `/tasks`, `/tasks/{id}`, `/work/{id}`, `/work/{type}/{id}`, `/alerts`, `/archive`, `/activity`, `/compliance`, `/customers/discover`, `/settings/background-operations`, `/settings/costs`, `/settings/ai-quality`, `/settings/push`, `/settings/ai/control-plane`, `/settings/ai/agents`, `/settings/ai/skills`, `/integrations/site-connectors` (liste; WordPress eklenti sayfası `/integrations/site-connectors/wordpress` duruyor).
- **Kaldırılan bileşenler:** `Demo\Operations\*` (Fırsatlar, Bulgular, Öneriler, Görevler, Görev, İş, Etkinlik), `Operator\Work\AlertsPage`, `Operator\Archive\ProductionArchivePage`, `Operator\Compliance\CompliancePage`, `Operator\Portfolio\DiscoverAndGroupPage`, `Demo\Settings\*` (AI kontrol paneli, ajanlar, beceriler, arka plan işleri), `Operator\Settings\{AiQualityPage, CostsPage, PushSettingsPage}`, `Operator\Integrations\SiteConnectorsIndex`, üst çubuktaki "Hızlı kayıt" (`Demo\CaptureModal`), rotasız eski site sayfası `Demo\Website\OverviewPage` (+ `website/overview`, `website/tabs/*` görünümleri, `x-operator.open-work`), `Demo\Partials\DataSyncControl`, `LegacyWorkRedirectController`; marka sayfasındaki "İşler" sekmesi ve müşteri sayfasındaki "Dikkat gerektirenler" / "Etkinliği gör".
- **Çağıranı kalmayan servisler silindi:** `ActivityReadService`, `OperatorExecutionReadService`, `DefaultPlaybookCatalog` + `PlaybookReferenceUrl` / `PlaybookRevisionFingerprint`, `OpportunityReadService` / `OpportunityDispositionService` / `OpportunityReadDto`, `CreateRecommendation` / `CreateRecommendationFromFinding` / `CreateRecommendationFromOpportunity` / `UpdateRecommendation` / `RecommendationReadService` / `RecommendationSourceResolver` / `RecommendationActivityRecorder` / `RecommendationReadDto`, `CreateTask` / `CreateDirectTask` / `CreateTaskFromRecommendation` / `TaskLifecycleService` / `TaskActivityRecorder` / `TaskReviewedStateFingerprint`, `WorkUrl`, `BackgroundOperationsService`, `CostReader`, `AiQualityReport`, `DataSyncLatestDatasetOutcomeService`, eski site sayfasının okuyucuları (`WebsiteDataSourcesReadService`, `WebsiteInfrastructureReadService`, `WebsitePagesContentReadService`, `WebsiteHealthScoreService`, `WebsiteIssueVerificationService`), `lang/*/background_operations.php`, `lang/*/operator_website.php`.
- **Ölü kod:** `Brand` / `DigitalAsset` / `Run` / `Task` / `BrandOffering` üzerinde var olmayan sınıflara (SearchDemand*, BrandQueryPortfolio*, ServicePageAssignment) giden ilişkiler silindi; `MetaCredentialBroker` yanlış `MetaException` içe aktarımı düzeltildi. "SEO görevleri" / WhatsApp yanıtı metin kalıntıları temizlendi; `moxdop:system-map` akış metni v2'ye çekildi.
- **Bilerek tutulan:** Sektör paketleri (`/settings/sector-packs`; uyum kurallarının tek düzenleme yeri, AI çıktısı kapısı bunları kullanır, Ayarlar › Genel'den bağlı), AI sağlayıcı anahtarları (`/integrations/{openai|anthropic|gemini|groq|openrouter}`, Ayarlar › AI'dan bağlı), Dosyalar (`/files`; marka / müşteri dosya sekmesi yükleme için bağlı), genel varlık listesi + varlık oluştur / düzenle (marka, Meta, İşletme Profili ekranlarından bağlı), yinelenen site birleştirme (`/integrations/website-duplicates`), Google bağlayıcı sayfaları (`/integrations/connectors/*`, Google Ads bağlayıcı), WordPress eklenti sayfası, `ai_productions` yazıcısı (`ProductionArchive`), uyum denetimi servisleri (`ComplianceAuditor`, `SectorPackRegistry`), `PortfolioDiscoveryGrouper` / `PortfolioGroupCreator` (marka adayları kullanıyor), Filament `/admin` (yalnız kaldırılan rotalara giden bağlantıları çıkarıldı).
- **Bilinen boşluklar:** AI aylık bütçesi ve rota adımları artık ekrandan değiştirilemiyor (kontrol paneli kaldırıldı; varsayılan / kayıtlı değer geçerli). Telefon bildirimi (ntfy / Telegram) ayarı ekrandan değiştirilemiyor. Günlük uyum taraması (`ComplianceScanCommand`) bulgu yazmaya devam ediyor ama bulgu listesi ekranı yok. Eski görev alanı (`TaskReadService`, `WorkReadService`, `TaskOutcomeEvaluator`) liste sayıları için okunuyor; yeni görev oluşturma yolu yok.
- **State:** CODED + PHPUnit (yalnız kaldırılan sayfaları test eden testler silindi / kırpıldı; `OperatorRouteSmokeTest` geçiyor). Üretim UAT yok.

## 2026-11-02 — Üretim ilk deneme düzeltmeleri: yedek, marka adayı AI hatası

- **`moxdop:reset` kapsamı daraltıldı (operatör kararı):** yalnız hesaplardan çekilmiş veri ve ondan türeyenler (toplama çalıştırmaları, sayfalar, sorgular, öneriler, eski modül tabloları) boşaltılır. Müşteriler, markalar, varlıklar, hesap bağlamaları, marka ayarları (hizmetler, bölgeler, hedefler), operatör girdileri (backlink, lead işaretleri, bütçe planı) ve denetim kayıtları kalır. **Yedek şartı kaldırıldı**; yalnız uygulama adı onayı var. PostgreSQL'de korunan bir tablo boşaltılan tabloya bağlıysa komut deneme aşamasında durur.
- **PostgreSQL: prompt sürümü oluşmuyordu** (`FOR UPDATE is not allowed with aggregate functions`) → üretimde tüm AI işlemleri "Prompt version could not be created" ile düşüyordu. Son sürüm satırı kilitlenip okunuyor.
- **Yedek:** `pg_dump` / `mysqldump` çıktısı artık yalnız gzip dosyasına akar (`Process::disableOutput()`); Symfony önceden tüm sıkıştırılmamış dökümü `php://temp` üzerinden sistem temp klasörüne (/tmp) kopyalıyordu → "No space left on device" (yedek klasöründe 43,9 GB boşken). Hata metni stderr'den okunur.
- **Marka adayları:** AI çağrısı hata verirse eşleşmeyen hesaplar artık tek tek aday olmaz (önceki davranış: 148 gereksiz aday); bir sonraki çalıştırmada yeniden denenir. `moxdop:brand-candidates --sync` hata mesajını yazar. AI hiç yoksa (`no_provider`) hesap adına göre aday davranışı aynı.
- **State:** CODED + PHPUnit (`KvkkAndBackupTest::test_pgsql_dump_is_streamed…`, `BrandCandidatesTest::test_failed_ai_call…`). Üretimde yeniden denenecek.

## 2026-11-01 — MoxDOP v2 Faz 9 (Sonuç takibi): uygulamada baseline, 28 / 56. gün ölçümü, Bugün "Sonuçlar"

- **Tek mekanizma** (`App\Services\Outcomes\OutcomeTracker`): her uygulama yolu (site `ChangeApplier` onay / canlıya al, `AnalystDecisionStore::markDone` → İşletme Profili Onayla + Meta Uygulandı, `GoogleAdsSuggestions::markApplied` → negatif gönderimi / Editor dosyası / operatör işi) `apply()` ya da `baseline()` çağırır. Baseline şekli tek: `{<metrik>: değer, …, window_days: 28, from, to, captured_at, scope: {…}}` (+ `facts`, `write_action_id`, `editor_file_at` gibi ekler); pencere uygulamadan önceki 28 gün, son toplanan günde biter. Eski kanal başına baseline kodu ve `ChannelAnalyst::baseline()` kaldırıldı.
- **Kanal okuyucuları** (`App\Services\Outcomes\Readers`, `OutcomeTracker::READERS`): `search` (öneri sayfasının URL'si: Search Console tık / gösterim / sıra), `meta` (reklam hesabı: harcama / sonuç / sonuç başı / CTR), `maps` (profil: görüntülenme, arama, yol tarifi, site tıklaması, etkileşim toplamı), `google_ads` (`google_ads_campaign_daily`: öneride kampanya varsa o kampanya (`action.campaign_id` ya da `action.campaign` adı), yoksa hesap; bilinmeyen kampanya → "veri yok"). Yeni kanal = bir okuyucu + `RULES` satırı.
- **Ölçüm** (`moxdop:outcomes:measure`, her gün 07:13 Europe/Istanbul, withoutOverlapping): `d28` = uygulamadan sonraki 1–28. gün, `d56` = 29–56. gün; pencere bitip verisi toplanınca bir kez ölçülür (geç veri için en çok 14 gün beklenir, sonra "veri yok"), `outcome.{d28,d56}` + `measured_at`. **Karar kuralı tek yerde:** kanalın ana metriği (arama: tık, harita: etkileşim, Google Ads: dönüşüm başı maliyet, Meta: sonuç başı maliyet; maliyet / sıra düşükse iyi) en az %10 iyileştiyse **işe yaradı**, değilse **işe yaramadı**; veri yok / eksik ya da hacim iki dönemde de alt sınırın altındaysa (20 tık, 20 etkileşim, 5 dönüşüm, 10 sonuç) **belirsiz** — tahmin yok. Uygulamadan önce baseline'ı olmayan satırlarda baseline ölçümde saklı veriden hesaplanır (`recomputed`).
- **Bugün:** "Sonuçlar" bloğu — son 90 gün işe yaradı / yaramadı / belirsiz sayıları + son 8 ölçüm (marka · kanal · 28/56 gün · başlık · önce → sonra · rozet). Site Öneriler listesinde uygulanan satırda sonuç rozeti (`livewire.demo.partials.outcome-badge`).
- **Saklama:** `moxdop:retention` kapalı önerileri (uygulandı / reddedildi) kapanıştan 12 ay sonra siler (`closed_suggestion_months`).
- **Yapılmadı:** İşletme Profili gönderi / yorum yanıtı öneri satırı değil (dış yazma kaydı), bu yüzden ölçülmez.
- **State:** CODED + PHPUnit (`tests/Feature/Outcomes/OutcomeTrackingTest`; Meta / Site / Google Ads baseline testleri yeni şekle uyarlandı). Üretim UAT yok. **Operator after deploy:** scheduler'da `moxdop:outcomes:measure` çalışmalı; ilk sonuçlar uygulamadan 28 gün sonra Bugün'de.

## 2026-10-31 — MoxDOP v2 Faz 6 (Meta ekranı): 10 sistem kontrolü, 3 AI işlemi, kreatifler, ölçümleme, lead kalitesi

- **Ekran** (`/assets/meta/{id}`, `App\Livewire\Demo\Meta\OverviewPage`; operatör rotası `App\Livewire\Operator\Meta\OverviewPage` yalnız id'siz girişi Meta varlık listesine yönlendirir): **Genel Bakış** (harcama / sonuç / sonuç başı / CTR 28 gün ± % önceki 28 gün, pixel durumu, açık öneri; ilk 5 kampanya) · **Yapılacaklar** (Yeniden kontrol et · Kreatif öner · Kampanya yapısı öner · Form / açılış sayfası öner; 10 kontrolün durumu Sorun / Tamam / veri yok / veri az; öneri kartı başlık · tek satır neden · kopyalanabilir talimat · Onayla / Düzenle / Reddet / Ertele · Kanıt; **Uygulanacaklar**: onaylananlar, Kopyala, **CSV indir**, **Uygulandı**) · **Kreatifler** (reklam başına küçük görsel (saklıysa), harcama, sonuç, sonuç başı, CTR, sıklık, "Yoruldu") · **Kampanya Stratejisi** (mevcut yapı kampanya + hizmete göre; yapı ve form / sayfa önerileri) · **Ölçümleme** (ilişkilendirme ayarı reklam setlerinden; pixel son olay, CAPI "veri yok"; Meta sonuçları · GA4 (Meta kaynaklı oturum / anahtar etkinlik) · CRM (lead işaretleri) **ayrı kartlar, toplanmaz**; Lead kalitesi) · **Analiz** (7 / 28 / 90 gün vs önceki eşit dönem: kampanya, reklam seti, reklam, hizmete göre, bölgeye göre (`meta_geo_results_daily`, "Bölge verisini çek")) · **Ayarlar** (bağlı hesap, para birimi / saat dilimi, markanın bölgeleri ve dilleri, Meta entegrasyonu bağlantısı). Eski sekme anahtarları yönlenir (campaigns / adsets / ads / audience / funnel / breakdowns → Analiz; advisor / operations / insights → Yapılacaklar; destinations → Ölçümleme).
- **Okuma** (`App\Services\Meta\MetaScreen`): yalnız açıkça bağlı reklam hesabı (`MetaAdsSpecialistBindingResolver`; işletme / bağsız hesap asla); tek performans kaynağı reklam günlükleri + reklam düzeyi tipli eylemler, kampanya / sete reklam anlık görüntüsüyle toplanır. Sonuç = lead + mesaj + satış (her biri tek kanonik eylem tipi, çift sayım yok). Merkezi (varlıksız) satırlar varsa onlar, yoksa varlık satırları.
- **Sistem kontrolleri** (`App\Services\Meta\MetaChecks`, `SyncMetaSuggestionsJob`, günlük `moxdop:meta:suggestions` 06:56 + düğme + ilk ziyaret): pixel / CAPI · yayın sorunları (reddedilen, son 3 gün gösterimsiz) · hedef ↔ optimizasyon olayı · UTM · açılış sayfası (farklı alan adı / sitede sayfa yok) · bölge (hizmet bölgesi dışı hedef, şube şehri hedeflenmiyor) · hizmet (reklamsız ana hizmet, hizmete bağlanamayan kampanya) · harcama / sonuç değişimi (önceki dönemde ≥ 10 sonuç) · kreatif yorgunluğu (son 7 gün sıklık ≥ 1,8 ve CTR ≥ %30 düşüş) · sonuç getirmeyen reklam (hesapta ≥ 5 sonuç ve harcama ≥ 2× sonuç başı; az veride "kapat" önerisi yok). Kontrol başına en çok bir öneri, kanıt satırlarıyla.
- **AI işlemleri** (şablonlar `config/moxdop-prompts.php`, rotalar `AdvisorServiceProvider`, `MetaAssistant` + `RunMetaAssistantJob` kuyrukta, yalnız operasyonel marka): `meta.creatives` (ana hizmet başına 2 varyant: metin ≤ 500, başlık ≤ 40, açıklama ≤ 30, video kancası, test), `meta.structure` (kampanya / reklam seti yapısı + yeniden pazarlama), `meta.landing` (form / açılış sayfası). Her öğe veri paketiyle doğrulanır: hizmet / kampanya / reklam seti / hedef paket içinde (yeni olanlar "Yeni:"), sayılar (≤ 10 sayımlar hariç) ve URL'ler pakette, sektör uyum kapısı; az veride durdurma önerisi atılır. Geçersiz öğe atılır; geçerli öğe yoksa hiçbir şey saklanmaz. Arama terimi kaynağı yok.
- **Öneriler tek tabloda** (`App\Services\Meta\MetaSuggestions`): `suggestions` kanal `meta`, hedef `meta` × varlık, gruplar `check` / `creative` / `structure` / `landing`. Onayla → `approved` (talimat + CSV); **Uygulandı** → `applied`, `applied_at`, baseline (28 gün harcama, sonuç, sonuç başı, CTR) Faz 9 için. Operatör düzenlemesi kilitler (`action.locked`): AI yeniden çalışınca metin değişmez, yeni sürüm "Değişiklik önerisi" olarak bekler (Öneriyi al). **Meta'ya hiçbir şey yazılmaz.**
- **Lead kalitesi** (`meta_leads` tablosu + `App\Services\Meta\MetaLeads`): Reklam Yöneticisi lead dışa aktarımı (UTF-16 / UTF-8, sekme / ; / ,) yüklenir; yalnız lead id, tarih, kampanya / reklam / form saklanır, ad / telefon / e-posta **saklanmaz**. Lead başına elle işaret uygun / randevu / satış / uygunsuz; kampanya başına sayılar Ölçümleme'de ve yapı AI paketinde.
- **Kaldırılan:** eski Meta sekmeleri (Performans, Kampanyalar gezgini, Kitle & Dağıtım, Dönüşümler, İçgörüler & Aksiyonlar) ve servisleri (`MetaAdsCampaignExplorer`, `MetaAdsCreativeFatigueReadService`, `MetaAdsProfessionalWorkspaceReadService` / `Enhancer`, `MetaAdsSpecialistReadService`, `MetaAdsPoolReadRepository`, `MetaGeoResultsReader`), kullanılmayan `Advisor\MetaAds` kural motoru, `insights.meta_geo` AI içgörüsü. Bağlayıcılar, toplayıcılar ve `MetaGeoResults` toplama işi duruyor.
- **State:** CODED + PHPUnit (`tests/Feature/Meta/MetaScreenTest`, `MetaAssistantTest`; `MetaGeoResultsTest`, `MetaAssetPageTest`, `MetaAdsOperatingWorkspaceTest`, panel / rota testleri yeni sekmelere uyarlandı). Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` → Panorama Meta → Yapılacaklar: Yeniden kontrol et → Kreatif öner / Kampanya yapısı öner → Onayla → CSV indir → Reklam Yöneticisi'nde uygula → Uygulandı; Ölçümleme: lead dosyasını yükle, işaretle. Scheduler'da `moxdop:meta:suggestions` çalışmalı.

## 2026-10-31 — MoxDOP v2 · Faz 5 (Google Ads ekranı): sistem kontrolleri, arama terimi incelemesi, kampanya stratejisi, reklam metni, Editor dosyası

- **Ekran** (`/assets/google-ads/{id}`, `App\Livewire\Operator\GoogleAds\OverviewPage`, eski ekranın yerine): **Genel Bakış** (maliyet · tık · dönüşüm · dönüşüm başı maliyet, 28 / 90 gün ± % önceki dönem; sistem kontrolleri: etiket · Geçti / Sorun / Veri yok · tek satır neden) · **Yapılacaklar** (Kontrolleri çalıştır · Arama terimlerini incele; kontrol + negatif kartları: Onayla / Gönder (Admin) / Düzenle / Reddet / Ertele · Kanıt; paylaşılan liste gönderimleri + Geri al; onaylı taslaklar + **Editor dosyası indir**) · **Arama Terimleri** (terim · kampanya · maliyet · tık · dönüşüm · hizmet (Faz 3 `queries` ataması, yoksa sektör eşleme kelimeleri) · AI kararı; seçip **Negatif öner**) · **Kampanya Stratejisi** (Kampanya yapısı öner · reklam grubu seç → Reklam metni yaz; kampanya / bütçe dağılımı / deney / RSA taslakları) · **Ölçümleme** (dönüşüm işlemleri: birincil / ikincil, durum, 30 gün, son dönüşüm; izleme kontrolleri) · **Analiz** (kampanya / reklam grubu / anahtar kelime / hizmet / bölge, 28 / 90 gün, önceki dönemle) · **Ayarlar** (bağlı hesap, para birimi, saat dilimi, son toplama, markanın hedef bölgeleri ve dilleri, entegrasyon bağlantısı). Eski sekme anahtarları yönlenir (advisor / landing_pages → Yapılacaklar, search_demand / search_terms → Arama Terimleri, budget_bidding / ads → Kampanya Stratejisi, conversions → Ölçümleme, campaigns / performance / auction_insights / changes / pmax … → Analiz, data_connection → Ayarlar).
- **Sistem kontrolleri** (`GoogleAdsChecks`, AI yok, 9 kontrol; `SyncGoogleAdsSuggestionsJob` — günlük `moxdop:google-ads:suggestions` 07:07 + düğme + ilk ziyaret): dönüşüm izleme, birincil / ikincil hedef, bütçe kullanımı / beklenmeyen değişim, hedef bölge (dil: veri yok), açılış sayfası (markanın sitesi / `pages`), reklam onayı (`google_ads_ad_daily.approval_status`), negatif çakışması, hizmet ↔ kampanya, maliyet / dönüşüm sapması (en az 10 dönüşüm + 50 tık; yoksa "Yeterli veri yok"). Veri yoksa "Veri yok"; hiçbir kontrol kapat / durdur önermez. Başarısız olanlar `suggestions` (kanal ve hedef `google_ads`) satırı + kanıt.
- **AI işlemleri** (üç; `PromptRegistry` şablonları, `RunGoogleAdsAssistantJob` kuyrukta, yalnız operasyonel marka): `google_ads.search_terms` (terim başına niyet + hizmet uyumu; negatifler: eşleme türü, kapsam (paylaşılan liste / kampanya / reklam grubu), engelleyebileceği faydalı sorgular kodla hesaplanır), `google_ads.structure` (ana hizmetlere göre kampanya / reklam grubu + günlük bütçe dağılımı + deney planı; Faz 3 kümeleri girdi), `google_ads.ad_texts` (RSA: başlık ≤ 30, açıklama ≤ 90, yol ≤ 15 — aşan metin atılır; açılış sayfası markanın sayfalarından). Doğrulama: terim / kampanya / reklam grubu / hizmet / URL veride olmalı, gerekçedeki sayılar veride olmalı, dönüşüm getiren terimi engelleyen ya da hiçbir gözlenen terimi kapsamayan negatif atılır, bütçeler toplam günlük bütçeye indirilir, az veride kapat / durdur önerisi atılır, reklam metninde sektör uyum kapısı. Eski `insights.search_term_triage` ve `insights.landing_fit` kaldırıldı.
- **Uygulama:** paylaşılan liste negatifleri → Admin onayı → mevcut ADR-064 yazıcısı (`GoogleAdsNegativeListWriter`, geniş eşleme sıralı gönderilir), geri alınabilir. Diğer her şey → Onayla → **Google Ads Editor CSV** (yeni kampanya duraklatılmış, reklam grubu, anahtar kelime, RSA, kampanya / reklam grubu negatifi); indirme taslakları uygulandı yapar. Uygulamada `baseline` (son 28 gün hesap sayıları + kanıt) ve `applied_at` saklanır (Faz 9). Operatör düzenlemesi taslağı kilitler (`action.locked`); AI yeni sürümü yalnız "değişiklik önerisi" olarak bırakır.
- **Kaldırılan:** eski Google Ads ekranı (`App\Livewire\Demo\GoogleAds\OverviewPage`, 16 sekme görünümü), ölçüm / açılış sayfası / açık artırma panelleri ve yalnız onların kullandığı okuma servisleri (`GoogleAdsSpecialistReadService`, `…ProfessionalWorkspaceReadService`, `…SearchExpertWorkspaceService`, `…CampaignAnalyticsReadService`, `…MeasurementControlService`, `…LandingPageControlService`, `…BudgetBiddingControlService`, `…WorkspaceTruthReconciler`, `…EntityHierarchyReconciler`, `…SearchLiveReadFallbackService`, `…SearchWorkspaceRecoveryService`, `…UiDatasetGate`, `AuctionInsightsImporter`) ve testleri. Toplayıcılar, bağlayıcılar, negatif yazıcı ve kural motorları (girdi olarak) duruyor.
- **State:** CODED + PHPUnit (`tests/Feature/GoogleAds/GoogleAdsScreenTest`; asset page / route / freeze testleri yeni sekmelere uyarlandı). Üretim UAT yok. **Operator after deploy:** Panorama Google Ads → Genel Bakış: kontroller → Arama Terimleri: Tümünü incele → Yapılacaklar: negatifleri Gönder → Kampanya Stratejisi: Kampanya yapısı öner, Reklam metni yaz → Onayla → Editor dosyası indir → Google Ads Editor'da içe aktar.

## 2026-10-30 — MoxDOP v2 · Faz 7 (İşletme Profili ekranı): standartlar → öneriler, hizmet karşılaştırma, açıklama, siteden gönderi, zamanlanmış gönderi

- **Ekran** (`/assets/gbp/{id}`, `App\Livewire\Demo\Gbp\OverviewPage`, eski sekmeler yerine): **Genel Bakış** (5 sayı: harita + arama görüntüleme 28 gün ± % önceki 28 gün · telefon / yol tarifi / web tıklama 28 gün · puan + yorum sayısı · yanıtsız yorum · profil standartları geçen / toplam; veri yoksa "—") · **Yapılacaklar** (Standartları kontrol et · Hizmetleri karşılaştır · Açıklama öner; öneri kartı: başlık · tek satır neden · Onayla / Reddet / Ertele (7 gün) · Kanıt; açıklama önerisinde mevcut / önerilen yan yana + Kopyala) · **Yorumlar** (yıldız, tarih, metin, yanıt durumu, "Yanıtsız" filtresi; Yanıt taslağı → düzenle → Admin **Gönder**; gönderilen yanıt Geri al) · **Gönderiler** (liste: MoxDOP'un gönderdikleri + Google'dan toplananlar, Zamanlandı / Yayınlandı / Geri alındı / İptal edildi; **Siteden paylaş**: sayfa seç → Yaz → Düzenle ve yayınla; Yeni gönderi formu: metin ≤ 1500, buton, bağlantı, Şimdi / Zamanla; zamanlanmış gönderi İptal et, yayınlanan Geri al) · **Analiz** (28 / 90 / 180 gün günlük tablo: arama / harita görüntüleme, telefon, yol tarifi, web tıklama + toplam; son 3 ay arama ifadeleri ilk 20; 12 ay yorum trendi) · **Ayarlar** (bağlı marka + sektör, konum bilgileri salt okunur, Google'da düzenle, Varlığı düzenle). Eski sekme adresleri yönlenir (performance / queries → Analiz, profile / health → Yapılacaklar, collect → Yorumlar, setup → Ayarlar). Kaldırılan: Yorum toplama (QR) sekmesi, yorum özeti / dağılımı, konu alanlı genel AI gönderi taslağı ekranı (`GbpPostDrafter` servis olarak duruyor, ekrandan çağrılmıyor).
- **Öneriler tek tabloda** (`App\Services\Gbp\GbpSuggestions`): `suggestions` kanal `maps`, `target_type = gbp`, `target_id = varlık`, parmak izi `gbp:{varlık}:{grup}:{anahtar}`. Gruplar: `standard` (başarısız / kontrol-et profil standartları, önce "Sorun", önem sırası, en çok 10; `SyncGbpSuggestionsJob` — günlük `moxdop:gbp:suggestions` 06:52 + ekrandaki düğme + ilk ziyarette bir kez), `service` / `category` (Hizmetleri karşılaştır), `description` (Açıklama öner). Yeni geçiş açık kartları günceller, kapalı kartı yalnız eylem değişirse yeniden açar, artık önerilmeyen açık kartı `recheck` yapar. Onayla = `AnalystDecisionStore::markDone` (baseline saklanır), Reddet / Ertele aynı mağaza. AI kartları `prompt_version_id` taşır.
- **AI işlemleri** (şablonlar `config/moxdop-prompts.php`, ajanlar `RegistryPrompted` + `UsesPromptRegistry`, rotalar `AdvisorServiceProvider`, çağrı `GbpAssistant` + `RunGbpAssistantJob` kuyrukta; yalnız operasyonel marka, aksi halde AI çağrısı yok): `gbp.services_compare` (onaylı hizmetler (öncelik) ↔ birincil / ek kategoriler + profil hizmet listesi → eksik hizmet adı **hizmetlerden birebir** olmalı ve profilde bulunmamalı; kategori notu mevcut bir kategoriye (ya da genel "eksik kategori") ait olmalı; işletme adına kelime öneren not atılır), `gbp.description` (marka hafızası profil satırları + hizmetler + bölgeler (fiziksel şube önce) + mevcut açıklama → ≤ 750 karakter (fazlası cümle sonunda kesilir), bağlantı / telefon / e-posta yok, sektör uyum kapısı (yüksek / orta ihlal = gösterilmez); Google'a yazılmaz, Kopyala), `gbp.post_from_page` (markanın sitesinin `pages` satırlarından hizmet / blog (kategorisiz olanlar da, Faz 4 etiketleyene kadar) → metin ≤ 1500, CTA bağlantısı kodla sayfa URL'si, bağlantı / telefon yok, uyum kapısı; taslak `ai_productions` `gbp.post_from_page`). Mevcut `gbp.review_reply` taslağına da uyum kapısı eklendi (ihlalli taslak gösterilmez). Elle yazılan gönderi metni de yayından önce uyum kapısından geçer.
- **Zamanlanmış gönderi (ADR-073 içinde):** `ExternalWriteService::requestLocalPost(..., publish_at)` Admin onayında kaydı `scheduled` olarak yazar (Europe/Istanbul, en çok 90 gün ileri; 2 dakikadan yakınsa hemen); `moxdop:gbp:publish-scheduled` her dakika zamanı gelenleri `queued` yapıp aynı yazıcıya gönderir; `cancelScheduled` (Admin) `cancelled`. Google tarafında zamanlama yok; yazma yine tek kayıt, geri alınabilir.
- **Okuma:** `App\Services\Gbp\GbpScreen` (Genel Bakış + Analiz sayıları, `gbp_performance_daily`, `gbp_search_keywords_monthly`, `gbp_reviews`, `gbp_location_snapshots`; sağlayıcı çağrısı yok).
- **State:** CODED + PHPUnit (`tests/Feature/Gbp/GbpWorkspaceTabsTest`, `GbpAssistantTest`; `GbpAssetPageTest`, `ReviewReplyDraftTest`, `GbpLocalIntelligenceWorkspaceTest` yeni sekmelere uyarlandı). Üretim UAT yok. **Operator after deploy:** Panorama İşletme Profili → Yapılacaklar: Standartları kontrol et → Hizmetleri karşılaştır → Açıklama öner (Kopyala → Google) → Gönderiler: bir blog sayfası seç → Yaz → Düzenle ve yayınla (Zamanla) → Yorumlar: Yanıtsız → Yanıt taslağı → Gönder. Scheduler'da `moxdop:gbp:publish-scheduled` (her dakika) çalışmalı.

## 2026-10-30 — MoxDOP v2 · Faz 4b (Site ekranı): Rakipler, Backlinkler, Site Sağlığı, Analiz, Bağlı Varlıklar

Beş bağımsız Livewire bileşeni (`App\Livewire\Operator\Website\V2\{CompetitorsTab, BacklinksTab, HealthTab, AnalysisTab, LinkedAssetsTab}`, her biri `mount(int $websiteId)`, yalnız aktif kullanıcı; görünümler `resources/views/livewire/operator/website/v2/*`). Web sitesi ekranı kabuğu (Faz 4a) bunları sekme olarak gösterir; bu fazda kabuğa / eski site sayfasına dokunulmadı.

- **Rakipler.** Girdi: markanın onaylı kümeleri (aktif hizmetlerinin kümeleri, markanın sektörü). Küme başına temsilci sorgu = ana sorgu; niyet ticari / yerel ise markanın ana hedef bölgesinin ili (öncelik → fiziksel şube → ilk) başa eklenir ("ankara implant merkezi"), bilgi sorgusu olduğu gibi. Konum: DataForSEO ücretsiz Türkiye SERP konum dizininden il adı (30 gün önbellek) → sitenin SEO pazar konumu → Türkiye (2792). Dil: eşlenen sayfanın dili → sitenin ilk dili → pazar dili → tr. Cihaz: mobil (`moxdop-site.competitors.device`). SERP `SerpResults::topTen` (30 gün önbellek, harcama koruması, pasif müşteride ücretli çağrı yok). Alan adı türü: kendi site (markanın site alan adları) · dizin / haber / bilgi (`config/moxdop-site.php` listeleri) · `competitor_domains` (bir kez sınıflanır) · kalanlar partiler hâlinde AI (`competitors.classify`, girdide olmayan alan adı atılır). İlk 5 ticari / bilgi rakibinin sayfası güvenli çekiciyle alınır, ana içerik (`MainContentExtractor`), metin 6000 karakter, 30 günde bir yeniden; açılamayan = "eksik" (`competitor_pages`). Ekran tablosu `brand_cluster_serps` (sorgu, konum / dil / cihaz, bizim sıra, top-10 + tür, son analiz). "Rakipleri güncelle" (`RefreshCompetitorsJob`, heavy kuyruk; operasyonel olmayan markada düğme kapalı + sunucu reddi) ve aylık `moxdop:site competitors` (ayın 3'ü 05:13). **"Analiz et"** (`AnalyzeCompetitorClusterJob`, AI `competitors.analyze`): bizim sayfa (brand_cluster_pages → pages; yoksa "sayfa yok") + en az 2 çekilmiş rakip sayfası → ihtiyaç, baskın sayfa tipi, bizde eksik bilgi, yerel / güven öğeleri, geliştir / yeni sayfa + öneriler. Doğrulama: öneri girdideki ≥ 2 farklı rakip URL'sine dayanmalı ya da sayfamız yokken açık boşluk (≥ 1 URL) olmalı; bilinmeyen URL atılır; sektör uyum kuralı (yüksek / orta) öneriyi düşürür. Öneriler tek `suggestions` tablosuna (kanal search, `action_type` / `decision_key` = rakip, `cluster_id`, `page_id`, kanıt = rakip URL'leri, `prompt_version_id`); yeniden analizde artık önerilmeyen açık (işlem görmemiş) rakip önerisi silinir. Ekran: küme başına sorgu, bizim sıra, top-10 (sıra · domain · tür · eksik), Analiz et, analiz satırları, öneriler.
- **Backlinkler.** "Bağlantı verenler": Search Console › Bağlantılar dışa aktarımı (CSV `,` `;` sekme, BOM; XLSX ilk sayfa) — Linking page / Bağlantı veren sayfa, Site, Target page / Hedef sayfa, Last crawled / tarama sütunları; kendi site ve bozuk satırlar atlanır; bağlantı başına tek satır (`backlinks`, kaynak gsc_import | manual | dataforseo), tekrar içe aktarmada en erken tarih kalır; elle ekle / sil. "Potansiyel kaynaklar" (`backlink_sources`): "AI ile kaynak öner" (`backlinks.sources`, `ProposeBacklinkSourcesJob`, yalnız operasyonel marka; sektör, ana hizmetler, bölgeler; mevcut / bağlantı veren / kendi alan adları tekrar önerilmez; yalnız https): ücret ücretsiz / ücretli yalnız aynı sitede kanıt URL'si varsa, yoksa "teyit gerekli". Elle kaynak ekleme. Durum: yok → **verildi** (operatör bağlantının olduğu sayfa URL'sini girer; hemen `VerifyBacklinkSourceJob`) → **doğrulandı** (sayfada markanın alan adına `<a href>` bulundu). Haftalık yeniden kontrol (`moxdop:site backlinks`, Pazartesi 05:43): doğrulanmış bağlantı kaybolursa "yok" + not "Bağlantı kaldırıldı · tarih"; açılamayan sayfa yalnız not.
- **Site Sağlığı.** Satırlar (durum · tarih · tek satır): WordPress çekirdek / eklenti / tema güncellemeleri ve kritik Site Sağlığı sayısı (Connector sağlık anlık görüntüsü `wordpress_site_health`); SSL bitişi (`SslCertificateProbe`, TLS eş sertifikası) ve alan adı bitişi (RDAP `expiration` olayı, `moxdop-site.health.rdap_base_url`) — `website_expiry_checks`, günde en fazla bir kez (`moxdop:site expiry` 05:27; "SSL / alan adı kontrol et" → `CheckSiteExpiryJob`); ≤ 30 gün uyarı, geçmiş kritik; hosting bitişi elle (`digital_assets.hosting_expires_on`); erişilebilirlik (mevcut uptime izleyici `uptime_states`). "WordPress'e giriş" düğmesi mevcut denetimli tek tık giriş yoluna POST eder (yalnız Admin, Connector varsa).
- **Analiz.** Sitenin bağlı Search Console (`gsc_query_page_daily`, web) + GA4 (`ga4_landing_source_daily`) verisi; dönem 7 / 28 (varsayılan) / 90 gün, son Search Console gününe kadar, önceki eşit dönemle karşılaştırma (toplam kartlar, satırda tıklama Δ). Alt sekmeler: **Kümeler** (onaylı küme başına tıklama, gösterim, ağırlıklı ort. sıra; ham GSC sorguları `query_sources` üzerinden kümeye bağlanır ve markanın hedef bölgesine göre bölünür — önce ilçe / bölge adı, sonra il; bölgesiz ayrı; eşlenen URL'nin GA4 oturum / anahtar etkinliği), **Sayfalar** (URL yolu: tıklama, gösterim, sıra, oturum, anahtar etkinlik; GA4 açılış sayfası sorgu dizesiz yol ile eşlenir), **Sorgular** (ham GSC sorguları), **Dönüşümler** (GA4 anahtar etkinlik > 0: açılış sayfası × kaynak / ortam). Yalnız okuma; 50'lik sayfa; sonuçlar site × dönem × son veri günü başına 1 saat önbellek (ayrı ön-hesap tablosu gerekmedi).
- **Bağlı Varlıklar.** Sitenin Search Console / GA4 bağları ve markanın İşletme Profili / Google Ads / Meta varlıkları: bağlama durumu ve son veri tarihi (`DataStatusReader`), varlık ekranı bağlantısı.
- **AI işlemleri** (`config/moxdop-prompts.php`, ajanlar `App\Ai\Agents\Site\*` `RegistryPrompted` + `UsesPromptRegistry`; rotalar `SiteScreenServiceProvider`): `competitors.classify` (sınıflandırma zinciri), `competitors.analyze`, `backlinks.sources` (analiz zinciri).
- **Şema** (`2026_10_30_090000_moxdop_v2_site_screen`): `competitor_domains`, `brand_cluster_serps`, `competitor_pages`, `backlinks`, `backlink_sources`, `website_expiry_checks`, `digital_assets.hosting_expires_on`.
- **Komut:** `php artisan moxdop:site competitors|backlinks|expiry [--site=] [--sync] [--force]`.
- **State:** CODED + PHPUnit (`tests/Feature/Site/{CompetitorsTest, BacklinksTest, HealthAndAssetsTest, AnalysisTest}`: temsilci sorgu + bölge kuralı, SERP önbelleği, alan adı sınıfları, rakip sayfa / eksik, analiz doğrulaması (≥ 2 atıf / boşluk), GSC dışa aktarım içe aktarma, ücret kuralı, bağlantı bulundu / bulunamadı / kaldırıldı, SSL sertifikası + RDAP ayrıştırma, analiz sayıları, her bileşenin görüntülenmesi, operasyonel kapı). Üretim UAT / PostgreSQL çalıştırması yok. **Open:** .tr alan adları RDAP vermez (satır "RDAP kaydı yok" der; alan adı bitişi bilinmiyor kalır); DataForSEO backlink kaynağı (`dataforseo`) kodlanmadı (Faz 1 izin listesi dışı); öneriler yalnız Rakipler sekmesinde listelenir (Öneriler sekmesi Faz 4a). **Operator after deploy:** `php artisan migrate --force` → Sorgular › Kümeler'de Panorama hizmet kümelerini onayla → Site › Rakipler "Rakipleri güncelle" → bir kümede "Analiz et" → Backlinkler: Search Console › Bağlantılar › Dışa aktar dosyasını yükle, "AI ile kaynak öner" → Site Sağlığı: hosting bitişini gir, "SSL / alan adı kontrol et".

## 2026-10-30 — MoxDOP v2 · Faz 4a (Web sitesi ekranı — SEO çekirdeği): kategori, hizmet ↔ sayfa, küme ↔ sayfa, marka hafızası, URL analizi, AI ile yap, standart öner, İçerik

- **Ekran** `/assets/website/{id}` (`operator.website`) artık `App\Livewire\Operator\Website\V2\WebsiteScreen` (eski `Demo\Website\OverviewPage` rotasız). Sekmeler: **Genel Bakış** (`OverviewTab`: sayfa sayısı kategoriye göre, küme kapsama % = yeterli / toplam, açık öneri, 28g organik tıklama ± % önceki 28 güne göre, son içerik tarihi, son veri tarihi + ilk 5 açık öneri) · **SEO Yapılacaklar** (alt sekmeler **Kümeler & Sayfalar** `ClustersPagesTab`, **Öneriler** `SuggestionsTab`, **İçerik** `ContentTab`, **Rakipler** / **Backlinkler** Faz 4b) · **Site Sağlığı** / **Analiz** / **Bağlı Varlıklar** (Faz 4b; sınıf yoksa "Hazırlanıyor") · **Ayarlar** (`SettingsTab`: sitemap URL override → `SitemapChangeWatcher` onu kullanır, haftalık içerik kapasitesi (marka, varsayılan 4), kategori düzeltmeleri + "Kilidi kaldır"). Eski sekme adları (`?tab=health`, `search_console`, `setup` …) yeni sekmeye gider. Ekranlar yalnız saklanan sonuçları okur; AI işleri kuyrukta.
- **URL kategorisi** (`App\Services\Site\PageCategorizer`): `pages.category` ∈ hizmet | blog | kurumsal | sss | lokasyon | diger; kurallar önce (WP `post` → blog; ana sayfa / hakkımızda / iletişim / yasal / ekip / kariyer … → kurumsal; SSS → sss; blog klasörü / tarihli URL → blog; onaylı hizmet adının tüm ayırt edici kelimeleri başlık / H1 / slug'da (Türkçe ek toleranslı) → hizmet; marka bölge kelimesi → lokasyon; hizmet klasörü → hizmet), kalanlar **tek AI partisi** (`site.page_categories`, 200'lük). Operatör kategorisi kilitli (`pages.category_locked`, `category_source` rule | ai | manual).
- **AI adım 1 — hizmet ↔ sayfa** (`ServicePageMapper`, tablo `offering_pages`: brand_offering_id (null + locked = "hizmet yok"), page_id, source, locked): hizmet / lokasyon sayfaları; ad kuralı (tek açık kazanan) → kalanlar `site.service_pages` (id'ler doğrulanır). Elle seçim kilitli.
- **AI adım 2 — küme ↔ sayfa** (`ClusterPageMapper`, `brand_cluster_pages` + `clicks_28d`, `impressions_28d`, `position_28d`, `reason`, `decided_by`, `refreshed_at`): markanın hizmetlerinin onaylı kümeleri; hedef sorgu = ana sorgu, yalnız ticari / yerel niyette başında hedef bölge ("ankara implant tedavisi"). Aday sayfalar = hizmete bağlı sayfalar + kümenin sorgularında görünen sayfalar (Faz 1 `gsc_query_page_daily`, `query_sources` ile bağlanır). Belirleyici sıra: aday yok → **uygun sayfa yok**; ≥ 2 sayfa gösterimin ≥ %25'ini alıyor → **çakışma olabilir**; Google'ın en çok gösterdiği sayfa hizmet sayfası değil (≥ %50) → **yanlış sayfa görünüyor**; alt konu kapsaması < %40 → **kapsam yetersiz**; GSC yok / < 30 gösterim → **veri yetersiz**; ortalama pozisyon > 10 → **performans zayıf**; kapsama %40–70 veya sadece benzer adlı sayfa → hizmet başına **tek AI çağrısı** (`site.cluster_pages`, aday sayfa + durum doğrulanır, gerekçede uydurma sayı / URL yok); yoksa **yeterli**. Dil: sitenin ana dili. Operatör URL / durum → kilitli (yalnız sayılar yenilenir).
- **Marka hafızası** (`BrandMemoryService`, `BrandMemory::contextFor(Brand, pageIds, clusterIds)`): profile (onaylı marka bilgisi, hizmetler + öncelik, bölgeler; notlar ayrı satır), page (AI özeti 2–4 cümle + temel bilgiler, `site.page_summary`, analizde kullanılan sayfalar için tembel, `pages.content_summary`'ye de yazılır; özet / bilgi sayfada olmayan sayı / URL içeriyorsa atılır), decision (her Onayla / Reddet + neden). contextFor yalnız istenen sayfaların özetini, aynı hizmet / küme hedefi olan ilgili sayfaların özetini (≤ 8), bu hedeflerdeki son 10 kararı ve kapsamı eşleşen standartları döner. Sayfa içeriği değişince (`Page` updated, content_hash) özet silinir ve o sayfanın açık önerileri `recheck` ("yeniden kontrol gerekli").
- **URL analizi & Öneriler** (`UrlAnalyzer`, `site.url_analysis`, satırda "Analiz et" / toplu "Seçilenleri analiz et", iş başına 3 URL, en çok 60): paket = marka + ana hizmetler, bölgeler / diller / notlar, URL'nin kümeleri, sayfa içeriği + SEO alanları, GSC 28g (tık, gösterim, pozisyon, ilk 15 sorgu) / GA4 28g (oturum, anahtar olay) ya da "veri yok", ilgili sayfa özetleri, standart sonuçları (kütüphanenin URL değerlendiricisi, saklı alanlarla) + kapsamlı standartlar, kararlar, site URL listesi. Türler: yanlış niyet, eksik konu / yanıtsız soru, başlık / açıklama, iç bağlantı, yinelenen / çakışan içerik, hizmet–lokasyon uyumsuzluğu, dönüşüm adımı, teknik SEO / yapılandırılmış veri. Doğrulama: başlık / gerekçede sitede olmayan URL veya pakette olmayan sayı (≥ 2 hane) → öneri atılır; kanıt öğeleri (alıntı sayfada, sayı pakette, URL sitede) tek tek doğrulanır, hiçbiri kalmazsa "veri yok"; bilinmeyen küme id → null. `suggestions` (kanal search, hedef sayfa, `prompt_version_id`); karara bağlananlar yeniden açılmaz, tekrar edilmeyen açıklar silinir. Öneriler sekmesi: filtre URL / tür / durum; Onayla, Reddet (neden zorunlu), AI ile yap, Bu karardan standart öner.
- **AI ile yap** (`ChangeApplier`, `site.apply_change`): başlık / açıklama, iç bağlantı (yalnız site sayfaları, ≤ 5), şema (geçerli JSON), içerik bölümü / SSS / yeniden yazım (WordPress Connector'dan canlı HTML alınabilirse tam yeni HTML; dış bağlantılar kaldırılır, sayfada olmayan sayı → reddedilir). Sektör uyum kapısı (`ContentComplianceGate`) göstermeden önce; takılırsa öneri gösterilmez ("uyum kuralına takıldı"). Mevcut / yeni yan yana (HTML blok farkı vurgulu). **Onayla** (Admin) → alanlar `ExternalWriteService::requestSiteFixes` (seo_title, seo_description, internal_link, schema; geri alınabilir), HTML → `requestContentDraft` (taslak kopya) → "Canlıya al" `requestContentApply` (geri alınabilir). `applied_at` + `baseline` (URL'nin GSC 28g tık / gösterim / pozisyon, pencere sonu) öneriye yazılır; karar hafızaya.
- **Bu karardan standart öner** (`ScopedStandards`, `site.standard_from_decision`): onaylı / uygulanmış öneriden başlık, kural, koşul, istisnalar, kapsam (URL / marka / sektör / genel) → operatör düzenler → "Standardı onayla" (Admin) → `website_standard_settings` (`website:decision:*`, yeni kolonlar `scope_type`, `scope_id`, `version`, `created_from_suggestion_id`); düzenleme sürümü artırır. `WebsiteStandardCatalog::all()` bunları kapsamıyla döner, `applicable()` yalnız kapsamı eşleşeni (marka / sektör / sayfa) bırakır; AI paketleri yalnız bunları görür. Standartlar kütüphanesi ekranında listelenir.
- **İçerik** (`ContentPlanner`): **Haftalık içerik öner** (`site.weekly_content`: marka profili, uygun sayfası olmayan / kapsamı yetersiz kümeler, geliştirilebilir URL'ler, son 8 haftanın planları, ay, kapasite) → en çok kapasite kadar öneri: başlık, yeni / güncelle, hedef küme, sayfa tipi, taslak başlıklar, "AI asistanlarına sorulanlar" listesi, hedef URL (yeni: sitenin URL düzeni `SiteUrlPattern`; güncelle: gerçek site sayfası olmalı). Uyum (`BriefCompliance`: sağlıkta fiyat / garanti vb.) ve önceki planla aynı başlık → atılır. **Kümeler dışında fırsat keşfet** (`site.content_discovery`: hiçbir kümede olmayan marka sorguları) → "küme dışı" öneri (en az bir gerçek sorgu id'si şart); **Kütüphaneye ekle** → sektör + hizmet kütüphanesinde onaysız küme + önerilen sorgular. **Taslak hazırla** (`site.write_article`) → dış bağlantısız, uydurma sayılı blokları atılmış HTML → uyum kapısı → **WordPress'e taslak gönder** (Admin, `requestArticleDrafts`, geri alınabilir). Sitede başka dil varsa çeviri aracı olmadığı için atlanır ve not düşülür.
- **İşler / zamanlama:** tüm AI işleri `App\Jobs\Site\RunSiteOperationJob` (heavy kuyruk, tekil site × işlem × konu, 840 sn, tek deneme, sınırlı partiler); ekran `SiteOperations::status()` tek satırını okur. `php artisan moxdop:site:weekly [--site=]` (Pazartesi 05:52): yalnız operasyonel markaların siteleri — yeni sayfaların kategorisi, hizmet ↔ sayfa, küme ↔ sayfa, kullanılan ve değişen sayfaların özetleri, profil yenileme. Pasif müşteride hiçbir AI çağrısı yok (her servis `Brand::isOperational()` kapısı).
- **AI işlemleri** (`config/moxdop-prompts.php`, `SiteServiceProvider` rotaları, ajanlar `App\Ai\Agents\Site\*` `RegistryPrompted` + `UsesPromptRegistry`): `site.page_categories`, `site.service_pages`, `site.cluster_pages`, `site.page_summary`, `site.url_analysis`, `site.apply_change`, `site.standard_from_decision`, `site.weekly_content`, `site.content_discovery`, `site.write_article`. `AiUsageRecorder` kayıtlı ajanın işlem anahtarını rota anahtarı olarak yazar.
- **State:** CODED + PHPUnit (`tests/Feature/Site/SiteMappingTest`, `SiteSuggestionsTest`). Üretim UAT yok (Panorama). Rakipler / Backlinkler / Site Sağlığı / Analiz / Bağlı Varlıklar Faz 4b. **Operator after deploy:** `php artisan migrate --force` → site ekranı › SEO Yapılacaklar › Kümeler & Sayfalar: "Sayfaları sınıflandır" → "AI adım 1" → (Sorgular'da kümeler onaylı olmalı) "AI adım 2" → sayfaları seçip "Seçilenleri analiz et" → Öneriler → İçerik "Haftalık içerik öner".

## 2026-10-29 — MoxDOP v2 · Faz 3 (Sorgular): normalleştirme, filtre sepeti, hizmet ataması, AI kural / kümeleme, marka sorguları

- **Sorgu hattı** (`App\Services\Queries\QueryPipeline`, `ProcessQueriesJob` heavy kuyruk, tekil + kilitli, 1000'lik parçalar, idempotent): `query_sources` → her satır hesabın sektörüyle (bağlı hesap: markanın `sector_id`'si; bağsız: marka adayı sektör önerisi; yoksa sektörsüz) normalleşir (`QueryNormalizer`: Türkçe küçük harf, filtre sepeti terimleri (genel + sektör) tam kelime ve Türkçe ek toleranslı silinir — "çankayada", "ankara'da" —, noktalama / boşluk sadeleşir; boş kalan satır bağlanmaz) → normalleşmiş metin başına tek `queries` satırı (`text_hash`). Toplamlar hesaplar / aylar boyunca: `impressions` / `clicks` (GSC + Ads), `ads_cost`, `ads_conversions`, `gbp_impressions`, `sources`, `first_seen_on` / `last_seen_on`; sektör = en çok gösterim + tıklama getiren sektör. Kaynağı kalmayan sorgu silinir (AI önerisi hariç). Hizmet: sektörün eşleme kelimeleri (`QueryServiceMatcher`, tam kelime + ek toleransı; en uzun kelime (kelime sayısı, sonra karakter) kazanır; iki hizmet eşitse atanmamış). Kilitli (elle) sorgular ve kilitli kümedeki sorgular yeniden atanmaz; hizmeti değişen sorgu kilitsiz eski kümesinden çıkar. `brand_queries`: bağlı GSC / Ads hesaplarının son 28 günü (tıklama, gösterim, ağırlıklı pozisyon), marka × sorgu, hedef bölge / URL boş (Faz 4). Tetikleyiciler: her `query_sources` toplaması sonrası (`AggregateQuerySourcesJob`), filtre terimi / eşleme kelimesi değişikliği, AI kural onayı, `php artisan moxdop:queries:process [--queue]`.
- **Şema:** `queries.hidden`, `ads_cost`, `ads_conversions`, `gbp_impressions`, `sources` + indeksler (`hidden, impressions`; `sector_id, hidden, impressions`). `ClusterQuery::searchQuery()` / `BrandQuery::searchQuery()` (eski `query()` ilişkisi Eloquent `query()` ile çakışıp modeli yükletmiyordu).
- **Eşleme kelimesi sektörde tekildir** (`ServiceKeywordService::add` / `replace` / `conflicts`): aynı kelime aynı sektörde ikinci hizmete eklenemez (Türkçe hata, hangi hizmette olduğu yazılır); başka sektörde serbest; AI `append` çakışanı atlar.
- **Sorgular ekranı** (`/library/queries`, `App\Livewire\Operator\Library\QueriesPage`), sekmeler: **Sorgular** (sorgu · hizmet · küme · gösterim · tıklama · Ads maliyet · kaynaklar · durum atanmamış / önerilen; filtre sektör, hizmet (+ Atanmamış), küme (+ Kümesiz), arama; 50'lik sayfa; toplu **Hizmete ata** (elle, kilitli), **Sil** (= gizle, `hidden`); **AI ile kural üret** (seçim gerekir), **AI ile kümele** (hizmet filtresi gerekir)) · **Kümeler** (hizmet başına: ad, ana sorgu, niyet, sayfa tipi, sorgu sayısı, onaylı / kilitli; çekmece: yeniden adlandır, onayla, sil, seçilenleri taşı, seçilenlerle ayır, bu kümeye birleştir) · **Filtre sepeti** (genel / sektör terimleri, ekle / sil) · **Eşleme kelimeleri** (sektör → hizmet → kelimeler, ekle / sil, tekillik hatası).
- **AI ile kural üret** (`queries.filter_rules`, şablon `config/moxdop-prompts.php` (PromptRegistry), `QueryRuleProposer`, `ProposeQueryRulesJob`): seçili sorgular (≤ 200) → tek çağrı → filtre terimleri (genel / sektör) + hizmet başına eşleme kelimeleri; her öğe seçili sorgularda geçmeli, sektör / hizmet girdide olmalı, mevcut / genel / sektörde çakışan kelime atılır. Öneri operatöre özel bekler (önbellek, 1 gün); modalda işaretlenenler kaydedilir → tüm sorgular yeniden işlenir.
- **AI ile kümele** (`queries.cluster`, şablon `config/moxdop-prompts.php` (PromptRegistry), `QueryClusterer`, `ClusterQueriesJob`): hizmetin görünür sorguları (kilitli kümedekiler hariç, gösterime göre ilk 500) → tek çağrı → kümeler (ad, niyet bilgi / ticari / yerel / karşılaştırma / marka, ana sorgu, ≤ 3 temsilci, diğer sorgular, sayfa tipi hizmet / rehber / sss / karşılaştırma / lokasyon / diğer, alt konular, gerekçe); id'ler girdiyle doğrulanır, bir sorgu tek kümede, en az bir gerçek sorgu şart; AI'ın eklediği sorgular `is_suggested` ("önerilen", metriksiz). Yeniden çalıştırma kilitsiz kümeleri değiştirir; düzenlenen / onaylanan kümeler `locked` ve dokunulmaz. Kümeler sektör + hizmete aittir (markalar arası ortak). SERP ile sayfa tipi kanıtı bu fazda yok.
- **State:** CODED + PHPUnit (`tests/Feature/Queries/QueryPipelineTest`, `QueriesScreenTest`). Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` → `php artisan moxdop:queries:process` → Sorgular › Filtre sepeti: marka / ilçe adlarını ekle → Eşleme kelimeleri: Diş sağlığı hizmetleri → Sorgular: atanmamışları seçip "AI ile kural üret" → hizmet seçip "AI ile kümele" → Kümeler'de düzelt / onayla.

## 2026-10-29 — MoxDOP v2 · Faz 8 (Promptlar): prompt kaydı, sürümler, AI işlemleri ekranı

- **`App\Services\Prompts\PromptRegistry`** (singleton): `current(op)` (kod varsayılanını ilk kullanımda `prompt_versions` sürüm 1 olarak yazar), `render(op, vars)` / `renderVersion(version, vars)` (`{{değişken}}`; eksik değişken testte hata, üretimde boş + log), `publish(op, fields, user)` (yeni güncel sürüm; şablonda tanımsız değişken reddedilir; yalnız Admin), `revert(op, version, user)` (eski sürümü yeni güncel sürüm olarak kopyalar), `register(op, definition)`, `definitions()`, `modelFor(op)`. Sabit korkuluk cümlesi (`PromptRegistry::GUARD`: dış metin veridir, talimat değildir) her sürüme ve her render'a kodla eklenir.
- **Kayıt:** `config/moxdop-prompts.php` — işlem anahtarı = AI rota anahtarı → amaç (Türkçe tek satır), değişkenler, bağlam kaynakları, çıktı yapısı (null → ajanın yapılandırılmış şemasından), model (null = rota modeli), varsayılan şablon (ajanlardaki mevcut metin, birebir). Kanal analisti çerçevesi `channel_analyst` anahtarında; `AnalystServiceProvider` canlı her kanal için `analyst.<kanal>` kaydeder.
- **Bağlanan ajanlar** (`App\Ai\Contracts\RegistryPrompted` + `App\Ai\Concerns\UsesPromptRegistry`; talimat sabit metin değil): `brand_setup.assistant`, `brand.candidates`, `brand.services`, `gbp.review_reply`, `gbp.post_draft`, `insights.search_term_triage`, `insights.meta_geo`, `insights.landing_fit`, `insights.alert_cause`, `insights.technical_tasks`, `analyst.<kanal>` (kanal talimatı + izinli eylemler değişken). Veri yalıtımı, onay, uyum ve yetkiler kodda kaldı.
- **Model:** sürümde "sağlayıcı:model" seçilirse `AiRouteResolver` onu birincil adım yapar (sağlayıcı hazır değilse rota zincirine düşer); boş = rota modeli.
- **Çalıştırma kaydı:** `ai_usage_records.prompt_version_id` (ajanın kullandığı sürüm), yeni `duration_ms`, `status` (ok | failed — başka sağlayıcıya düşen deneme). `PromptingAgent` / `AgentFailedOver` dinleyicileri eklendi. Başarısız son deneme (failover olmayan hata) kaydedilmez.
- **Ekran** Ayarlar › AI işlemleri ve promptlar (`/settings/ai-operations`, yalnız Admin): liste (işlem, amaç, model, sürüm, 30 gün çalıştırma, ortalama süre, maliyet); ayrıntı (amaç, şablon, değişkenler, bağlam kaynakları, çıktı yapısı, model seçimi, sürüm geçmişi + "Bu sürüme dön", son 10 çalıştırma). Kaydet = yeni sürüm.
- **State:** CODED + PHPUnit (`tests/Feature/Prompts/PromptRegistryTest`). Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` → Ayarlar › AI işlemleri ve promptlar'ı aç → bir işlemi aç, şablonu kontrol et.

## 2026-10-28 — MoxDOP v2 · Faz 2 (Sahiplik): marka adayları, marka ayarları, hizmet keşfi, Bugün

- **Keşfedilen varlıklar → marka adayları** (Entegrasyonlar › Keşfedilen varlıklar). Tablolar `brand_candidates` (ad, `signals` json: hosts / GBP birincil kategori / site başlığı / reklam hesap adları / türler, `sector_id` önerisi, `sector_signal` gbp_category | site | ads | manual, `sector_reason`, `confidence`, `method` deterministic | ai | manual, `status` proposed | approved | dismissed, `brand_id`) + `brand_candidate_resources` (bir kaynak / markasız web sitesi en fazla bir adayda: iki unique index). `App\Services\Portfolio\BrandCandidateBuilder`: önce belirleyici gruplama (web sitesi host ↔ Search Console mülkü ↔ GA4 akış URL ↔ GBP web sitesi (metadata / `gbp_location_snapshots`) ↔ Google Ads final URL (`google_ads_ad_snapshot`) ↔ Meta kreatif linki (`meta_creative_snapshot`); host'u olmayan hesaplar ad benzerliği ≥ 0.8), host'u mevcut bir markanın sitesi olan grup "mevcut markaya" önerilir; sonra kalanlar + sektörü hiç sorulmamış adaylar için **parti başına tek AI çağrısı** (`brand.candidates`, 80'lik parti): kalanları gruplar ve her aday için en güvenilir sinyalden sektör önerir; bilinmeyen anahtar / sektör kodu atılır; AI yoksa kalan her hesap kendi adayı olur. Günlük `moxdop:brand-candidates` (06:47, `--sync` ile anında) + "Yeniden grupla" (`RefreshBrandCandidatesJob`, heavy kuyruk). Onaylı / yoksayılmış adaylar hiç değişmez; önerilen adaylar yalnız yeni üye kazanır veya markaya bağlanan üyelerini kaybeder (boş aday silinir). Butonlar: **Onayla** (mevcut müşteri seç veya yeni müşteri adı → `PortfolioGroupCreator` / `BrandSetupApplier` ile marka + varlıklar + bağlar; sektör `brands.sector_id`'ye), **Düzenle** (ad, sektör, üyeyi başka adaya / yeni adaya taşı), **Yoksay**.
- **Sektör yalnız markada.** `brands.sector_id` (service_categories) eklendi, eski `sector` kodundan / `brand_service_category` pivotundan dolduruldu; `Brand::sectorCategory()`, `sectorCodes()` yalnız `sector_id`'yi okur; eski `sector` kodu kayıtta `sector_id`'den yansıtılır (salt ayna). `brand_service_category` pivotu okunmuyor (deprecated). `resource_automations.sector` kolonu kaldırıldı. `DigitalAsset::sector()` markanın sektörünü döner. `BrandSetupApplier` ve marka formu sektörü `sector_id`'ye yazar.
- **Marka › Ayarlar › Marka** (`App\Livewire\Operator\Portfolio\BrandSettings`, `?tab=ayarlar`): Sektör (seç + Kaydet) · Hizmet bölgeleri (tek liste: ad, il / ilçe, "Fiziksel şube var" anahtarı, Sil; `brand_service_areas.name` eklendi) · Hizmetler (Ana / İkincil öncelik, Kaldır, "Sayfalardan hizmet çıkar", öneri tablosu: ad düzenle · katalog / "Yeni katalog" · sayfa sayısı · öncelik · Onayla / Atla · Birleştir) · Notlar (hedefler, kısıtlar → `brand_memory` kind=profile ref_type=manual_notes) · Varlıklar (tür, ad, bağlı hesaplar; Admin: Bağla (aday hesap / markasız site), Bağı kaldır, Taşı → `OwnershipTransferService::moveAsset`). `BrandMemory` modeline doğru tablo adı (`brand_memory`) verildi.
- **Hizmet keşfi** (`App\Services\Portfolio\BrandServiceExtractor`, `ExtractBrandServicesJob`): markanın site(ler)inin `pages` satırlarından ana sayfa / hakkımızda / blog yazısı / iletişim / yasal / kategori / tarihli / sayfalama / indekslenmeyen sayfalar URL + kategori + WP türü + başlık kurallarıyla çıkarılır; **tek AI çağrısı** (`brand.services`) normalize Türkçe ad, sektör kataloğundan eşleşen `ServiceCatalogItem` (yoksa "yeni katalog" işaretli) ve kaynak sayfa id'leri önerir; sayfa / katalog id'leri girdiyle doğrulanır. Tablo `brand_service_candidates` (proposed | approved | skipped). Onay: katalog kalemi (yeniden adlandırılırsa markanın sektöründe çözülür / oluşturulur) + `brand_offerings` (öncelik, `locked` = true). Yeniden çalıştırma yalnız yeni öneri ekler; onaylı hizmetler AI tarafından yeniden adlandırılmaz. Operasyonel olmayan / sektörsüz / sayfasız markada çalışmaz.
- **Bugün** (`/`): operasyonel markalar · sektör · hizmet sayısı · bölge sayısı · her varlık türü ve son veri tarihi (`DataStatusReader`, yoksa "—") · "Öneri —" (Faz 4+).
- **AI rotaları:** `brand.candidates` (sınıflandırma zinciri), `brand.services` (analiz zinciri) `AiRouteRegistry`'de, kullanım kaydı `AiUsageRecorder`'da; istemler sayfa / hesap metnini veri olarak işler.
- **State:** CODED + PHPUnit (`tests/Feature/Ownership/BrandCandidatesTest`, `BrandServicesAndSettingsTest`; pivot kullanan eski testler `sector_id`'ye çevrildi). Üretim UAT yok. **Operator after deploy:** `php artisan migrate --force` → `php artisan moxdop:brand-candidates --sync` → Entegrasyonlar › Keşfedilen varlıklar'da Panorama adayını kontrol et (sektör + üyeler) → Onayla (müşteri seç) → Marka › Ayarlar: bölgeler (Çankaya şubesi fiziksel), notlar → "Sayfalardan hizmet çıkar" (Faz 1 `pages` dolunca) → önerileri Onayla (Ana / İkincil).

## 2026-10-28 — MoxDOP v2 · Faz 1 (Toplama): tek veri seti kataloğu, tüm hesaplar, `pages`, `query_sources`, saklama, DataForSEO

**Veri seti kataloğu — tek doğru: `config('moxdop-collection.datasets')`** (`App\Support\Collection\CollectionDatasetCatalog`). Her toplayıcı istek ailelerini buradan süzer (GA4 / Search Console / Google Ads merkezi katalogları, `CollectionPlanner` + `DueCollectionQueryService` bağlı hesap yolu (Meta), İşletme Profili adımları). Listede olmayan veri seti planlanmaz; `on_demand` (Search Console URL denetimi) yalnız açık istekle.

- Search Console: `gsc_property_daily` (toplamlar), `gsc_query_page_daily` (sorgu × sayfa günlük, 16 ay), `gsc_sitemap_snapshot`, `gsc_site_metadata` (izin / arama türleri). URL denetimi yalnız istekle.
- GA4: `ga4_property_metadata`, `ga4_property_daily`, **yeni** `ga4_landing_source_daily` (açılış sayfası × oturum kaynağı / ortamı + key events; `GA4_RF_LANDING_SOURCE_DAILY`), `ga4_key_event_daily`.
- İşletme Profili: profil (NAP, kategoriler, açıklama, saatler), öznitelikler, hizmetler, yorumlar, gönderiler, günlük performans, aylık arama ifadeleri, medya. Yer eylemleri ve doğrulama bırakıldı.
- Google Ads: aylık aktivite geçmişi (ilk aktarım planı), hesap anlık görüntüsü (kampanya + teklif stratejisi + bütçe + reklam grubu + reklam / öğe / politika), teklif stratejileri, kampanya / reklam grubu negatif anahtar kelimeleri (çakışma kontrolü için), dönüşüm işlemleri, hesap / kampanya / reklam grubu / anahtar kelime / arama terimi / reklam / coğrafya günlük. Açılış sayfası, cihaz, saat, ağ, kullanıcı konumu, yaş, cinsiyet, kitle, PMax, Shopping, video, öneriler, değişiklik geçmişi bırakıldı.
- Meta: reklam hesabı, kampanya / reklam seti (+ **ilişkilendirme ayarı `attribution_spec` her çekimde**) / reklam seti hedeflemesi / reklam / kreatif (+ **kullanılan lead form id**) anlık görüntüleri; hesap / kampanya / reklam seti / reklam günlük, eylemler (sonuçlar), video, **yalnız bölge (region) kırılımı**, piksel / veri kümesi (dönüşüm kaynakları). Saatlik, değişiklik geçmişi, ülke / demografi / yerleşim / cihaz kırılımları bırakıldı.
- DataForSEO: yalnız `serp_results` (top-10, 30 gün önbellek) ve `query_volumes` (arama hacmi, 90 gün önbellek).
- Kaldırılan tablolar (okuyan yok): `ga4_landing_channel_daily` (+ `ga4_f_landing_channel`), `dataforseo_ranked_keyword_snapshot`, `dataforseo_keyword_site_snapshot`, `dataforseo_competitor_domain_snapshot`. Bırakılan diğer veri setlerinin tabloları okuyucu servisler hâlâ referans verdiği için duruyor (yeni satır gelmez; saklama 16 ayda boşaltır). `website_html_snapshot` Site Sağlığı / standartlar tarafından okunduğu için duruyor; `pages` HTML saklamaz.

**Tüm keşfedilen hesaplar.** `ResourceAutomationService::portfolioGate()` artık hiçbir hesabı durdurmaz, `isQueryOnly()` her zaman false: bağlı / bağsız, aktif / pasif müşteri fark etmeksizin her hesap katalogdaki tüm veri setlerini toplar (eski `unbound` / `customer_passive` park kayıtları bir sonraki tikte serbest). Operatör uyarısı yalnız operasyonel varlığa bağlı hesaplar için. `ExecuteDatasetRunJob` pasif müşteride iptal etmez (yalnız silinmiş varlık); WordPress uzlaştırma, sitemap izleme ve Meta bölge sonuçları pasif müşterilerde de çalışır. **Sınır:** Meta toplama motoru bağlama (Meta reklam hesabı ↔ varlık) ister; bağsız Meta hesabı `binding` durumunda bekler (uyarı yok).

**`pages` (site içeriği).** WordPress (Connector anlık görüntüsü): her yayımlanmış yazı / sayfa (her dil) → URL, `wp_post_id`, tür, Polylang dili, SEO eklentisi başlık / açıklama / canonical (Yoast, Rank Math, SEOPress; şablon değişkenli başlık yok sayılır), ana içerik metni + H1–H3 (`headings`), kelime sayısı, `is_indexable` (noindex → false), `changed_at` (WordPress değişiklik zamanı). `category = null` (Faz 4), `content_summary = null`. İçerik hash'i değişmezse satır yazılmaz; değişince `analyzed_at` ve özet boşalır. Eklenti olayları: silinen / çöpe atılan / yayından kalkan → sayfa hemen silinir; güncellenen / yeni / URL değişen → değişen nesne yenilemesi (satır kimliği korunur, URL güncellenir); tema değişimi / tema güncellemesi → tüm sayfalar `changed_at = now`, yeniden çekim ve tam envanter yok. Tam envanter sonrası WordPress'te yayında olmayanlar silinir. WordPress dışı siteler: sitemap URL'leri → güvenli çekici → ana içerik çıkarımı (header / footer / nav / aside / menü / çerez atılır) → aynı kolonlar; saatlik geçişte en çok 40 sayfa, lastmod son kontrolden yeniyse tekrar; sitemap'ten çıkan URL silinir. Servisler: `App\Services\Website\Pages\{PageStore, WordPressPageSync, SitemapPageSync, MainContentExtractor}`. Komut: `moxdop:pages:sync --site=<id> | --all`.

**`query_sources` (ham sorgu katmanı).** Search Console sorgu × sayfa (yalnız web), Google Ads arama terimleri, İşletme Profili arama ifadeleri → hesap × ham sorgu × ay (gösterim, tıklama, gösterim ağırlıklı pozisyon, maliyet, dönüşüm). Her toplama sonrası yazılan aylar yeniden hesaplanır (`AggregateQuerySourcesAfterCollection` → `AggregateQuerySourcesJob`, heavy kuyruk; İşletme Profili ifade adımından sonra da). İdempotent; ayda kaybolan sorgu satırı silinir; Faz 3 `query_id` korunur. Doldurma: `moxdop:queries:sources --all | --resource=<id> [--months=16]`.

**Saklama.** `moxdop:retention` (varsayılan deneme; `--apply`), ayda bir (`2. gün 04:10`): günlük veriler 16 ay (eskisi `performance_monthly_rollups`'a çevrilip silinir), sorgu günlükleri 16 ay (aylık hali `query_sources`, 24 ay), ham kopyalar 90 gün (her sayfanın son HTML'i kalır; toplu silme bitene kadar), telemetri kendi süresi. Bölüm hazırlığı `moxdop:db:ensure-partitions` tüm bölümlenmiş tabloları kapsar. Eski `moxdop:data:retention` kaldırıldı.

**DataForSEO.** İzinli uç noktalar: ücretsiz hesap / konum dizinleri, `serp/google/organic/live/advanced` (`App\Services\Intel\SerpResults::topTen(query, location_code, language, device)`), `keywords_data/google_ads/search_volume/live` (`QueryVolumes::volumes([...])`). Labs sıralanan / site anahtar kelimeleri, rakip alan adları, anahtar kelime fikirleri, harita / yorum kuyrukları, backlinkler izin listesinden çıktı (kod çağırsa bile reddedilir); SEO intelligence yenileme düğmesi "v2'de kaldırıldı" döner. Harcama koruması + aylık tavan aynen; her çağrı `dataforseo_tasks`'a maliyetiyle yazılır; pasif müşteri markası için ücretli çağrı yok.

**Tanı.** `moxdop:diagnose` Veri toplama bölümü: katalog satırları, tüm hesapların (bağlı / bağsız) otomasyon durumu, `query_sources` toplam / son ay / hesap başına (son toplama). Web siteleri bölümü `pages`'ten okur (sayfa, WordPress, indekslenebilir, analiz bekleyen, son değişiklik; ayrıntıda diller, kısa içerik, sitemap izleme). Ayrıntılı tarih tabloları v2 veri setlerine çevrildi.

- **State:** CODED + PHPUnit (yeni: `V2DatasetCatalogueTest`, `PagesFromWordPressTest`, `PagesFromSitemapTest`, `QuerySourceAggregatorTest`, `SerpAndVolumeCacheTest`, WordPress Connector uçtan uca envanter → `pages`, tema değişimi olayı; kaldırılan ücretli DataForSEO yollarının testleri silindi). Üretim UAT / PostgreSQL çalıştırması yok. **Operator after deploy:** `php artisan migrate --force` → `php artisan moxdop:resources:automate` (tüm hesaplar kuyruğa) → `php artisan moxdop:pages:sync --site=<Panorama site id>` → toplama bitince `php artisan moxdop:queries:sources --all` → `php artisan moxdop:diagnose --brand=Panorama --section=collection,website`.

## 2026-10-27 — MoxDOP v2 · Faz 0 (Temizlik): reset, kaldırılan modüller, yeni tablo aileleri, sade menü

**Kaldırıldı — REMOVED (v2).** Aşağıdaki yetenekler koddan (rota, Livewire, view, servis, job, komut, zamanlayıcı, menü, config, dil anahtarı, test) ve şemadan (`2026_10_27_090000_moxdop_v2_reset_schema` tek DROP migration'ı) çıkarıldı; verileri deploy öncesi `moxdop:reset` ile silinir, veri kaybı amaçlıdır. Yeniden kurulacak olanlar ilgili fazda yeni model üzerinde yazılır.

- Hizmet Beyni (`/brain/*`, `App\Services\Brain`, `brain_*`, `method_settings`, Yöntem Kütüphanesi, Sektör örüntüleri) — REMOVED (v2)
- Arama Talebi (`/library/search-demand-*`, `App\Services\SearchDemand\*`, `search_demand_*`, sorgu kütüphanesi `search_query_library_*`, sorgu dışlama, manuel kümeler, marka sorgu portföyü, `/library/search-queries`, `/library/brand-query-portfolios`) — REMOVED (v2); Faz 3 katmanları (`query_sources` · `queries` · `brand_queries` · `filter_terms` · `clusters`) yerine geçer
- Eski danışman ekranları (`/ads-advisor*`, Danışman paneli, `advisor_plans` / `advisor_items`, haftalık plan / ölçüm / özet e-postası, AI reklam metni / kreatif / profil taslakları) — REMOVED (v2); Google Ads / Meta / İşletme Profili **kural motorları** yalnız servis olarak kaldı (`App\Services\Advisor\{GoogleAds,MetaAds,Gbp}\*RuleEngine|*InputCollector`, `Support`, `Anomaly`) ve Faz 5–7'de girdi olarak kullanılır
- SEO Görevleri (`/seo-tasks*`, `seo_plans` / `seo_tasks` / `service_page_assignments`, SEO planı, site anlama, içerik planlayıcı) — REMOVED (v2); `SeoText`, `SiteUrlPattern`, `BrandLocationWords`, `SeoStoredHtmlReader`, `SeoPlanInputCollector` (yalnız veri okuma yardımcıları) kaldı
- Site düzeltmeleri (`site_fix_items`, SiteFixes panel / AI) ve URL karnesi (`website_url_audits` / `website_url_verdicts`) — REMOVED (v2); Faz 4'te öneriler + `pages` üzerinde yeniden kurulur
- Konu haritası / İçerik Stüdyosu / içerik dağıtımı (`topic_*`, `content_ideas` / `content_articles`, WXR, yerelleştirme) — REMOVED (v2); Faz 4 İçerik yeniden yazar (`ArticleDraft` + `ContentComplianceGate` `App\Services\ExternalWrites` altında kaldı)
- Komuta merkezi (`/command-center`, `inbox_*`), Portföy sağlığı (`/portfolio/health`), İçerik takvimi (`/content`, `content_calendar_items`, müşteri onayı `/onay/*`) — REMOVED (v2)
- Lead kutusu (`/leads`, `agency_leads`, `lead_outcomes`, Meta leadgen / form webhook), Potansiyel müşteriler (`/prospects*`, `prospect_*`, niyet radarı `sales_*`, dış denetim `prospect_audits`) — REMOVED (v2)
- Ajans işletmesi (`/agency`, faturalar, zaman kayıtları, taahhütler, görüşme kaydı), müşteri sağlık puanı (`customer_health*`), KVKK takibi, yenilemeler (`asset_renewals`, `/renewals`, takvim beslemesi) — REMOVED (v2); SSL / alan adı bitiş gerçekleri veri havuzunda (`website_infra_snapshot`) kalır
- WhatsApp asistanı (`/whatsapp*`, `whatsapp_*`, webhook) — REMOVED (v2)
- Harita grid sıralaması, KML, yorum istihbaratı, rakip site izleme, backlink v1, AI görünürlüğü (`/market/*`, `map_grid_*`, `review_*`, `backlink_*`, `competitor_site_snapshots`, `ai_visibility_checks`) — REMOVED (v2); Faz 4 Rakipler / Backlinkler yeniden tasarlar (`DataForSeoTaskQueue` kaldı)
- Aylık rapor v2 ve rapor v1 (`/reports/*`, `monthly_reports`, `report_*`, değer hikâyesi, ajans karnesi, grafik notları) — REMOVED (v2)
- BrandDemand hub (`brand_demand_*`, `demand_*`, `moxdop:demand:*`) ve eski sorgu hattı (`query_variants`, `query_ingest_states`, `asset_sectors`, `moxdop:queries:*`) — REMOVED (v2)
- Marka çalışma alanı kanal sekmeleri (Arama · Harita · Google Ads · Meta Livewire bileşenleri) ve kanal analistleri (`Analyst\{Search,Maps,GoogleAds,Meta}`) — REMOVED (v2); motor çekirdeği (`AnalystEngine`, `AnalystPack`, `AbstractChannelAnalyst`, doğrulama, `analyst_runs`) kaldı; sekmeler "Hazırlanıyor" gösterir

**Eklendi / değişti.**

- `moxdop:reset` (`App\Console\Commands\MoxdopResetCommand`): varsayılan deneme çalıştırması her tablo için satır sayısı ve TRUNCATE / KEEP; `--apply` uygulama adıyla onay ister ve son 24 saatte başarılı yedek (`system_backups`) yoksa reddeder (`--skip-backup-check`). Korunan: kullanıcı / rol / izin, entegrasyon + bağlantı + kimlik bilgisi + keşfedilen kaynak, ayarlar, sektör / hizmet kataloğu (+ eşleştirme ifadeleri, sektör ürün markaları, sektör paketi ayarları, uyum kuralları), standartlar, AI rota ayarları, `prompt_versions`, `filter_terms`, `migrations`. Önbellek ve Horizon kuyrukları temizlenir. Test: `tests/Feature/Operations/MoxdopResetCommandTest`.
- `analyst_decisions` → **`suggestions`** (tek öneri tablosu, tüm kanallar): `why` → `reason`, `action_params` → `action`, yeni `target_type` / `target_id` / `page_id` / `cluster_id` / `prompt_version_id` / `applied_at` / `measured_at`; parmak izi marka başına benzersiz; durumlar `open | approved | applied | dismissed | snoozed | recheck`. Model `App\Models\Suggestion`.
- Yeni tablolar + modeller (yalnız şema + fillable/casts, mantık yok): `pages` (`Page`), `query_sources` (`QuerySource`), `queries` (`Query`), `brand_queries` (`BrandQuery`), `filter_terms` (`FilterTerm`), `clusters` (`Cluster`), `cluster_queries` (`ClusterQuery`), `brand_cluster_pages` (`BrandClusterPage`, 7 durum), `brand_memory` (`BrandMemory`), `prompt_versions` (`PromptVersion`). `brand_service_areas.physical_branch`, `brand_offerings.priority` (main | secondary), `ai_usage_records.prompt_version_id`, `external_write_actions.suggestion_id` (eski `advisor_item_id` / `seo_task_id` düştü).
- `ExternalWriteService` yük tabanlı: `requestNegativeList(User, DigitalAsset, lines, ?Suggestion)`, `requestDraft(User, DigitalAsset, draft[])`, `requestSiteFixes(User, DigitalAsset, changes[])`, `requestContentDraft(User, DigitalAsset, payload)`, `requestContentApply(User, DigitalAsset, draftId)`, `requestArticleDrafts`, `requestReviewReply`, `requestLocalPost` (takvimsiz), `requestUpdate`, `requestConnectorUpdate`, `requestUndo`. WordPress fix / draft writer'lar öğe modeli olmadan çalışır.
- Menü: **Bugün · Müşteriler · Markalar · Sorgular · Entegrasyonlar · Ayarlar**. Ayarlar sekmeleri: AI işlemleri ve promptlar (`/settings/ai-operations`, yer tutucu; Faz 8), Standartlar, Sektör ve hizmet kataloğu, Kullanıcılar (`/settings/users`), Sistem. Sorgular (`/library/queries`) yer tutucu; Faz 3. Bugün = operasyonel marka listesi (Faz 9 sonuçları ekler).
- İşletme Profili sayfası: gönderi formu doğrudan ADR-073 yazmasına gider (takvim yok); yorum yanıtı ve AI taslak aynı.
- **State:** CODED + PHPUnit (tam paket geçti; kaldırılan özelliklerin testleri silindi). Üretim UAT / PostgreSQL migration çalıştırması yok. **Operator after deploy:** `moxdop:backup` → `php artisan moxdop:reset` (deneme) → `moxdop:reset --apply` → `php artisan migrate --force`.

## 2026-10-26 — Üretim veri toplama düzeltmeleri (2026-09-29 `moxdop:diagnose` Panorama)

- **Search Console bölümleri.** Compact `gsc_f_*` fact tables are monthly RANGE-partitioned but their logical datasets are declared `partition_strategy: NONE`, so `PostgresWarehouseWriter` never ensured a partition → "no partition of relation gsc_f_page_country / gsc_f_query_country found for row" for every month not created at conversion. The writer now calls `PartitionManager::ensureForWrite()` for any partitioned target (declared RANGE_MONTHLY **or** a partitioned compact fact), over the batch's min–max dates. Every partitioned parent also gets a DEFAULT partition (`{table}_default`); creating a month later moves rows out of the default (detach → create → move → re-attach). New `moxdop:db:ensure-partitions --months=3 [--back=N]` (scheduled daily 03:20) + migration `2026_10_26_080000_ensure_fact_partitions_and_defaults` (current + 3 months + defaults, PG only).
- **Search Console veri seti onarımı.** Smart update self-heal: a query / page / country / device dataset whose facts hold no row for the property although `gsc_property_daily` shows ≥ 50 impressions in the window is fetched over the whole 395-day window (at most once a week per dataset) instead of the 4-day restatement forever. New `moxdop:gsc:repair-facts --asset=<id|ad> | --resource=<id> [--days=486] [--dataset=…] [--apply]` (dry-run default): ensures the gsc_f_* partitions for the window + 3 months (+ DEFAULT), lists every dataset's rows / latest date / last run, and with `--apply` queues `SearchConsoleCentralCollectionService::startRefetch()` for datasets with no rows or a failed last run (fresh checkpoints). `moxdop:diagnose` detail dates (`gsc_query_daily`, `gsc_page_daily`, `google_ads_campaign_daily`, …) no longer filter by `digital_asset_id` — resource-first rows carry `digital_asset_id = null`, so they showed "yok" although rows existed.
- **GA4.** A natural-key text dimension the provider did not return (`pagePathPlusQueryString` null / missing) is stored as `(not set)` instead of failing the whole write (empty string stays `(empty)`; `allow_empty_string` columns unchanged). A GA4 repair run now always includes `ga4_property_daily` from its own coverage, so one failing dataset no longer freezes the property totals. Accounts stopped by fixed write errors (`missing natural key`, `no partition of relation`) are re-admitted automatically by the daily `moxdop:resources:retry-stopped` (and on demand `moxdop:resources:automate --recover-ga4-landing-pages`). The asset-bound `ga4_property_daily` "not_eligible" rows come from the legacy bound incremental planner (no asset materialization); data flows through the resource-first run.
- **Saat dilimi.** `App\Support\Time\SafeTimezone` (the normalizer) now maps ~70 tzdata backward links (Turkey, US/*, Asia/Calcutta, …), matches identifiers case-insensitively and falls back to `app.timezone` (else Europe/Istanbul). Applied in all four binding contexts (GA4 / GSC / Google Ads / Meta `realBound`), Google Ads / Meta advisor collectors, Google Ads analyst facts, AdBudgetWatch, Google Ads live-read / history discovery / budget control / campaign analytics, GA4 central property clock, projection adapters, freshness resolvers, evidence eligibility.
- **Markasız varlıklar.** `RebuildWebsiteProjectionJob` skips brandless websites, `QueueWebsiteProjectionAfterCollection` dispatches only for websites with a brand, `ExecuteIntelligencePlanService` marks the plan BLOCKED for a brandless asset (was ValidationException "Digital Asset must belong to a Brand.").
- **Sorgu kaynakları (bağsız hesaplar).** Search Console / Google Ads / Business Profile accounts not bound to an operational asset collect **only** their query dataset (`gsc_query_daily`, `GADS_CENTRAL_RF_SEARCH_TERM`, `gbp_search_keywords_monthly`; run metadata `query_only`); GA4 / Meta stay gated (`unbound`). Such query-only Ads runs never become the history baseline (full initial import once bound). Resource-automation alerts are raised only for operationally bound accounts; `ResourceAutomationService::resolveUnboundAlerts()` (daily with retry-stopped, on demand `moxdop:resources:automate --resolve-unbound-alerts`) resolves the old ones (`NOT_OPERATIONAL`).
- **Yedek.** `moxdop:backup` writes and verifies the dump in `BACKUP_TEMP_DIR` (default `storage/app/backup-tmp`, main disk) and moves it into `MOXDOP_BACKUP_DIR` (default `storage/app/backups`); streamed gzip with checked writes; free space is checked first (database size × `MOXDOP_BACKUP_COMPRESSION_RATIO` 0.35 + `MOXDOP_BACKUP_FREE_MARGIN_MB` 200) with a Turkish message "gereken ~X, boş Y"; disk-full errors are explained in Turkish; partial files removed; reason stored in `system_backups.error`.
- **Veri havuzu denetimi.** `moxdop:data-pool-audit` returns success when checks fail (findings stored, logged as warning) and on unexpected runtime errors (reported); `--strict` keeps the non-zero exit for CI.
- **Meta istek sınırı.** `MetaUsageGovernor` reads `x-app-usage`, `x-ad-account-usage`, `x-business-use-case-usage` on every Graph response and keeps a shared cooldown (≥ 90 % usage or "estimated_time_to_regain_access"); rate-limit errors set it (code 4 app limit 900 s, 17 / 613 / 800xx 300–600 s). Dataset retries use that backoff and rate limits get a 12-attempt budget (`moxdop-collection.rate_limit_max_attempts`). `CollectMetaGeoResultsJob`: one at a time (`WithoutOverlapping` shared), waits (`release`) during the cooldown and on rate-limit errors (`retryUntil` 12 h, `maxExceptions` 1, `failOnTimeout`); the daily dispatch is spread 3 min apart.
- **İşçi nabzı uyarısı.** `worker_heartbeat_missing` opens only when the newest heartbeat is older than `MOXDOP_OPS_WORKER_ALERT_AFTER_SECONDS` (600) and resolves as soon as heartbeats resume (HEALTHY or DEGRADED); fresh heartbeats under other supervisor names are DEGRADED, not UNHEALTHY.
- **Web sitesi toplama.** `WebsiteCollectionOrchestrator::start()` returns the active run instead of throwing "A collection is already active for this website." (also when another trigger holds the admission lock).
- **Harcamasız Google Ads hesabı.** `DormantAccountHint`: diagnose shows "HARCAMASIZ: hesap 1 yıldır harcamasız (son harcama 2025-09-25) — doğru hesap bağlı mı?" instead of STALE / VERİ YOK (last day with `cost_amount > 0` ≥ 45 days ago); the Google Ads analyst "Veri yok" line gets the same hint. Account daily / campaign daily queries were not wrong: the account (#26) has not spent since 2025-09-25.
- **State:** CODED + PHPUnit (`tests/Feature/DataPool/FactPartitionManagerTest`, `tests/Feature/Collection/ProductionCollectionRepairTest`, `tests/Feature/Operations/ProductionHardeningTest`; PG-only `tests/Integration/DataPool/FactDefaultPartitionPostgresTest` skipped on SQLite). **No PostgreSQL run in this environment, no production UAT.** **Operator after deploy:** `php artisan migrate --force`, `php artisan moxdop:db:ensure-partitions --months=3`, `php artisan moxdop:gsc:repair-facts --asset=24` then `--apply`, `php artisan moxdop:resources:retry-stopped`, set `BACKUP_TEMP_DIR` if storage is not on the big disk, `php artisan moxdop:backup`. **Open:** GBP query-only runs reuse the bound collector step list (keywords only) without their own coverage window; legacy asset-bound GA4 incremental plans still record `not_eligible`; Meta cooldown is app-wide (not per ad account).

## 2026-10-27 — Production pipeline / performance fixes (Panorama `moxdop:diagnose` 2026-09-29)

- **Console `--brand` / `--asset` options (`App\Support\Console\ConsoleScope`).** `moxdop:analyst:weekly`, `moxdop:demand:build|serp|compare`, `moxdop:measurement:refresh`, `moxdop:compliance:scan`, `moxdop:brain:refresh`, `moxdop:topics:build` (`--site`, new `--brand`), `moxdop:seo:plan --asset`, `moxdop:advisor:plan --asset`, `moxdop:website:url-verdicts` (`--website`, new `--brand`), `moxdop:data-pool-audit --asset=*`, `moxdop:reconcile-provider-period --asset` take an id **or** a partial, case-insensitive name (assets also domain / address) like `moxdop:diagnose`. None / several matches stop with exit 2 and the candidate list ("#5 Panorama Ankara, #9 Panorama İzmir"). Before: `(int) "Panorama"` = 0 → "No query results for model [Brand] 0" or a silent no-op / whole-portfolio run.
- **Sorgu hattı on the queue (`RunQueryPipelineJob`).** Root cause of MaxAttemptsExceeded: one job ran every account + all AI calls with `$timeout = 3500` on the default queue while Redis `retry_after` was 900 → the running job was handed to a second worker. Now the job is an orchestrator (120 s, unique per account, pipeline lock) that queues a chain on the heavy queue: `IngestQuerySourcesJob` × ⌈accounts / `moxdop-queries.sources_per_job` (10)⌉ → `AssignQuerySectorsJob` (AI ≤ `sectors_per_job`) → `MatchQueriesJob` → `ClassifyUnmatchedQueriesJob` (≤ `classify_per_job` 120 per job, continues itself up to `classify_per_run`) → `FinishQueryPipelineJob` (summary + lock release). AI steps share one non-blocking lock (concurrent per-account pipelines never classify twice). Idempotent (facts / context fingerprints). Every run records "N yeni sorgu · …" (`QueryPipeline::lastRun`); an empty Search Console ends cleanly with "0 yeni sorgu". Core-query metric refresh = one CASE UPDATE per 500 queries (was one per query). `moxdop:queries:pipeline [--queue] [--json]` prints the summary; the daily schedule uses `--queue`. Clustering (`ClusterQueriesJob`) = one `ClusterServiceJob` per changed service + capped `ResearchQueryClustersJob` (≤ `serp.per_job` paid checks per job; `researchDue` is now capped overall).
- **SEO plan timeouts.** Root cause: `SeoTaskRuleEngine` compared every portfolio query with every page (`anyPageCovers`, 3 000 × 4 591) and every silent page with every page with traffic (`pruneTasks`), re-folding both texts (Str::ascii) each time — minutes of CPU on a 5 000-page site. Now `SeoText::fold/tokens` are memoised (bounded) and `TokenIndex` (inverted token index) answers "covers" / "first overlapping title" from posting lists. Same results; synthetic 5 000 pages × 3 000 queries: 146 s → 3 s. `RunSeoPlanJob` timeout 900 (< retry_after), heavy queue. Doorway title grouping skips `similar_text` when lengths make the threshold impossible.
- **URL karnesi (`RefreshUrlVerdictsJob`).** Root causes: `Cache::lock()->block(30)` (LockTimeoutException ×15), timeout 900 = retry_after 900, and a dispatch storm (projection + SEO plan + weekly). Now: non-blocking lock (`refresh()` answers `busy`), automatic triggers queue ONE debounced refresh per site (unique until processing + `moxdop-url-audit.debounce_seconds` 120 s delay), a trigger arriving while one runs sets a re-run flag (one re-run afterwards), a manual click is released and retried (tries 10, maxExceptions 1, failOnTimeout), heavy queue, `failed()` closes the audit row. Reads stay chunked (profiles 500, HTML 100), writes 200 per statement.
- **Topic map "queued, never built".** Root cause: `BuildTopicMapJob` waited on the default queue behind hour-long jobs (pipeline 3 500 s, duplicates via retry_after) and had no failure handler; builds older than 30 min were ignored but never closed. Now heavy queue, `failed()` closes the build, a lost build (> 30 min queued / running) is closed when a new one is queued, one build per site at a time (non-blocking lock). `BuildBrandDemandJob` also on heavy.
- **Queue topology (`config/queue.php`, `config/horizon.php`).** New `queue.heavy_queue` (`heavy` with Redis, `default` otherwise); Redis / database `retry_after` default 1 800 s; production heavy supervisor 3 processes; heartbeat probes also on the heavy queue. `tests/Feature/Performance/QueueTopologyContractTest` asserts every job's queue (instantiated, config-driven, literal `onQueue`) is consumed by a production Horizon supervisor, every `$timeout` ≤ retry_after − 60, supervisor timeouts < retry_after, Horizon stop window ≥ longest job.
- **Memory.** Website projection adapters streamed snapshot histories (`->cursor()`, link edges with three columns) instead of `->get()` of every snapshot ever stored (OOM at `Connection.php:442`); a projection run left `running` by a dead worker is closed (`ABANDONED`) by the next rebuild. `PageFeatureExtractor` keeps url → id instead of every profile model; the technical health screen streams profiles.
- **Small production errors.** Veri merkezi: checkbox values `true/false` in `picked.*` no longer reach `count()` (normalised lists). Ajans › Zaman: real month bounds (was `YYYY-MM-31` → PostgreSQL "date/time field value out of range" in September). GBP special-hours dates with year 0 ignored. AI cost: OpenAI models (gpt-5 / 5-mini / 5-nano / 4.1 / 4.1-mini / 4.1-nano / 4o / 4o-mini, text-embedding-3-small / large) priced — they were recorded as $0.00 / unknown and never counted against the budget; dated snapshots (`-2025-08-07`) use the alias price; migration `2026_10_26_120000_backfill_unknown_ai_usage_costs` prices earlier NULL-cost rows. Monthly AI budget default $100 (`AI_MONTHLY_BUDGET_USD`, Ayarlar › AI still overrides). Deploy: config / route / event caches are built into a temp file and moved over the live one (`atomic_cache`), no more "require(bootstrap/cache/routes-v7.php): Failed to open stream". Varchar overflow on AI text: covered by the brand-setup `clean()` clamp; other AI writers checked (already clamped).
- **`moxdop:pilot:refresh --brand=<id|ad> [--run] [--status]`** (`PilotRefresh`, `PilotRefreshStepJob`): dry run lists the steps with current state; `--run` queues ONE chain on the heavy queue: brand's query pipeline (its bound accounts + account-less site facts) → clustering → marka talep tablosu → konu haritası (per site) → SEO planı (per site; reads the topic map) → Sayfa Karnesi (per site) → live analysts; progress for `--status`, one run per brand (lock), a failed step stops the chain and frees the locks.
- **State:** CODED + PHPUnit (`tests/Feature/Performance/LargeSitePipelineTest` 5 000-page site: bounded queries / chunked writes / comparison counts; `QueueTopologyContractTest`; `Queries/QueryPipelineQueueTest` incl. 5 000 GSC queries and "0 yeni sorgu"; `Website/UrlVerdictRefreshJobTest`; `ContentStudio/TopicMapQueueTest`; `Operations/ConsoleScopeOptionsTest`, `PilotRefreshCommandTest`, deploy / Agency / Data center / AI cost additions). **No production UAT yet** (needs deploy + `moxdop:pilot:refresh --brand=Panorama --run`). **Open:** GSC query facts are still 0 for Panorama until the collection fixes (parallel work) land — the chain then reports real "N yeni sorgu"; `WebsiteCollectionOrchestrator` "A collection is already active" exception (collection area) not changed here.

## 2026-10-26 — Marka çalışma alanı › Meta sekmesi (Meta analisti)

- **What.** `AnalystRegistry` channel `meta` is live: `App\Services\Analyst\Meta\MetaAnalyst` (+ `MetaFacts`, `MetaPlanExport`), tab `App\Livewire\Operator\Workspace\MetaTab` (Durum · Yapılacaklar · Kanıt), AI route `analyst.meta`, weekly run with the other live channels. A brand may have several Meta ad accounts (one `meta_ads` asset each, real ad-account binding only); money is never summed across currencies (Harcama shows "Hesap bazında").
- **Durum.** Harcama 28g (±% vs previous 28 days), Sonuç 28g (±%; note = sonuç başı maliyet ±%, conversion campaigns only), Kaliteli lead (appointment + sale / marked, Meta form leads, else all sources; ₺ / kaliteli lead), Sıklık 7g (impression-weighted), Piksel / CAPI (problem count), Öğrenmede takılı (active conversion-goal ad sets with 7-day spend ≥ threshold and < 15 results / week).
- **Pack (stored data only; ids).** `acc:<asset>` (28g vs prior: spend, results, cpr, CTR, CPM, frequency, ROAS when purchase value exists) · `trk:<asset>-<hash>` measurement problems (advisor pixel-health issues, conversion spend without result data, no pixel) with the exact fix, `px:<asset>-<source>` pixels / custom conversions (status, days silent) · `lq:meta`, `lq:all`, `leadc:<label>` lead outcomes (ADR-074: marked / unmarked / qualified / junk, cost per lead / per qualified lead) · `cmp:<asset>-<id>` campaigns (objective, optimization goal, result type, objective fit vs lead goal, CBO / ABO, spend share, ±%, results, cpr, CTR, CPM, frequency) · `set:<asset>-<id>` ad sets (learning limited, needed daily budget) · `reg:<asset>-<slug>` regions (`meta_geo_results_daily` with leads, else region breakdown) with in / out of the brand's service areas and spend share · `ad:<asset>-<id>` top 40 ads (28g, CTR / frequency last 7 vs previous 7 days, fatigue from the advisor rule, creative text, video plays / thruplay / 25 % hold) · `cmpl:<asset>-<ad>` ad texts breaking the brand's sector rules (`meta_ad` source: before/after, testimonials, discounts, guarantees…) · `adv:<asset>-<rule>` Meta advisor rule items (fatigue, saturation, learning, spend without results, pixel, placements, landing, change impact) · `plc:` placements · `demo:` age / gender. Instructions: measurement → objective fit → waste (low-quality leads, out-of-area regions, fatigued creatives) → learning / fragmentation → creative refresh → placements / audience; health ad rules.
- **Actions (no Meta writes).** `draft_creatives` (ad → the ad's creative-fatigue advisor item, created from the card when the weekly advisor has none → `AdvisorItemActions::requestDraft` → `DraftMetaAdsCreativeJob`, sector rules in the prompt; result on Meta hesabı › Danışman), `open_campaign` (Meta asset page, campaign / ad set level), `mark_lead_outcomes` (Marka › Leadler), `fix_tracking` (Meta asset › Ölçüm; the fix text is in Kanıt), `export_plan` (downloads `meta-plan-<marka>-<tarih>.csv`: one row per open Meta card — öncelik, hesap, düzey, ad, Meta kimliği, yapılacak, neden, veri — for the operator to apply in Ads Manager). Shared engine: optional `Contracts\DownloadsDecision` (a `run` action returning a file; `runDecisionAction` returns the download), `AbstractChannelAnalyst::complianceText()` hook (Meta drops the technical word "kampanya" before the inducement rule check).
- **No data.** No bound Meta ad account → "Veri yok: Meta reklam hesabı bağlı değil."; no spend in 56 days → "Veri yok: son 56 günde Meta harcaması yok." (run skipped, no AI call).
- **State:** CODED + PHPUnit (`tests/Feature/Analyst/MetaAnalystTest`: pack + Durum from two seeded accounts, fatigue / learning / out-of-area / tracking / compliance / lead-quality facts, no mixed-currency sum, fake AI decisions validated (invented number + write action dropped) / stored / rendered in BrandShow with working links, draft_creatives queues the draft job, export_plan downloads the CSV, no account → Veri yok without AI). **No live UAT, no real AI call.** **Open:** lead forms (form list, questions) are not collected; CAPI / dataset event match quality is not collected (only pixel last-fired / availability); region matching is by city name only; outcome measurement of done cards (shared).

## 2026-10-25 — Step 3: marka çalışma alanı › Google Ads kanalı

- **Google Ads analyst (`Analyst\GoogleAds\GoogleAdsAnalyst`, `GoogleAdsFacts`, `Workspace\GoogleAdsTab`; AI route `analyst.google_ads`, weekly with the other live channels).** Every active, really bound Google Ads asset of the brand separately (28 days vs the 28 before, account timezone); money is never added across currencies.
- **Durum (6).** Harcama 28g (±%; per currency "₺4.200 · $560" when currencies differ), Dönüşüm 28g (±%), CPA 28g (±%) or ROAS when conversion value exists (single currency only; "hesap bazında" otherwise), Boşa harcama 28g (non-converting search terms on the query pipeline's lists: `query_variants.kind` competitor / banned, or a core query marked `irrelevant` for the brand's sector; share of spend), Kayıp gösterim payı (impression-weighted search budget / rank lost IS), Dönüşüm takibi (OK / Sorun: no enabled primary action, 0 conversions in the last 14 days with ≥ 50 clicks after converting before (critical), none in 28 days with ≥ 150 clicks, lead action counted "every", two primaries of the same category, low-intent primary, auto-tagging off, the brand website's `TrackingHealthChecker` alerts).
- **Pack ids.** `trk:<asset>:<code>` / `trk:site:<kind>`, `acct:<asset>`, `camp:<asset>:<campaign>` (type, status, bidding + target CPA / ROAS when collected, daily budget, cost / conv / CPA / ROAS ± previous, IS, lost IS budget / rank, budget_limited), `conv:<asset>:<action>`, `st:<asset>:<sha1-10>` (search terms: list rakip marka / yasaklı / alakasız / hizmet, cost, clicks, conversions, search campaigns, pmax, already excluded), `kwo:<asset>:…` (converting terms not keywords, with their one ad group), `kw:<asset>:<adgroup>:<criterion>` (match, QS, weak components), `ad:<asset>:<adgroup>` (worst RSA strength, final URL, open `weak-ad-strength` advisor item id, draft ready), `lp:<asset>:…` (landing pages + website URL verdict / key events / status / speed), `seg:<asset>:…` (device / province (in_service_area vs brand service areas) / 3-hour), `auc:<asset>:…` (latest auction insights upload), `adv:<id>` (open Google Ads advisor findings), `area:<id>`. Reuses `GoogleAdsAdvisorInputCollector` (window forced to 28 days), `GoogleAdsAdvisorRuleEngine::coveredByNegative`, `GoogleAdsRowScope`.
- **Priority rule.** Instructions: tracking → wasted spend / negatives → lost IS → bidding fit → ads / assets → landing → geo / device / hour / competitor / PMax, health-sector ad rules in context. **Enforced in `validate`**: when tracking is broken (critical / high issue) a `fix_tracking` card is first with priority 1 (rule code adds one from the issue when the AI left it out) and every other card gets priority ≥ 2. Channel checks: add_negatives needs non-converting, not-excluded `st:` refs of the target account (or its advisor negative item); export_editor needs `st:` / `kwo:` / `ad:` refs of the account; draft_ad_copy only on an ad group with an open advisor item.
- **Actions (no campaign / budget / bid writes).** `add_negatives` → link to the tab with `?neg=<card>`: editable list ([exact] from the card's terms, else the account's current candidates) → Admin "Onayla ve Google Ads'e gönder" → `ExternalWriteService::requestNegativeListForDecision` (ADR-064 shared "MoxDOP negatifleri" list, same writer, queued, one write per account at a time, `request_payload.analyst_decision_id`; recent writes listed with "Geri al"). Non-Admin sees "Admin onayı gerekli". `export_editor` → `GET /brands/{brand}/google-ads/editor/{decision}` (`operator.analyst.google-ads.editor`): the card's items as a Google Ads Editor file (campaign negative exact, exact keyword in its ad group, new RSA from the ready advisor AI draft with sector-rule lines dropped; `GoogleAdsEditorExport::rsaRow`, RSA columns appended only when used). `draft_ad_copy` → `AdvisorItemActions::requestDraft` (existing `DraftGoogleAdsAdCopyJob`). `open_campaign` → asset page campaigns tab (`campaign=`), `fix_tracking` → asset measurement tab (site issues → website page), `review_landing_page` → Sayfa Karnesi `url_q` (else asset landing pages tab). Card download buttons skip `wire:navigate`; Kanıt tables show Harcama / Dönüşüm / CPA / Sorun columns.
- **Kanıt.** Hesaplar (per account, own currency), Boşa giden terimler (list, account, cost, already negative), Kampanyalar (cost, conversions, CPA, lost IS budget / rank).
- **Veri yok.** No Ads asset → "Veri yok: markaya bağlı Google Ads hesabı yok."; none bound → "Veri yok: Google Ads hesabı bağlı değil."; no campaign rows in 56 days → "Veri yok: son 56 günde Google Ads verisi yok." (run skipped, no AI call).
- **State:** CODED + PHPUnit (`tests/Feature/Analyst/GoogleAdsAnalystTest`: two accounts TRY + USD, per-currency Durum, single-currency sums / CPA ±%, wasted spend from competitor / banned / irrelevant terms, ids + facts, tracking broken → fix_tracking first, fake AI → validated / dropped / stored / rendered in BrandShow, add_negatives Admin review → queued ADR-064 write + undo, non-Admin refused, draft_ad_copy queues the advisor draft, export_editor file contents, no account → Veri yok without AI call). **No live UAT, no real AI call.** **Open:** bidding strategy / targets only when the campaign snapshot carries them (collector does not request them yet); PMax search-term insights only as the existing `campaign_search_term_view` rows; no GA4 landing-page conversions beyond URL verdicts; outcome measurement of done cards (engine-wide).

## 2026-10-25 — Step 3: marka çalışma alanı, AI analist motoru, Arama sekmesi, sade menü

- **Brand workspace (`/brands/{id}`, `BrandShow`).** Tabs Arama · Harita · Google Ads · Meta (+ Ayarlar at the end). On every channel tab: "Bu hafta yapılacaklar" (top 7 open AI cards across live channels by priority, each with its action and Yapıldı / Ertele / Gereksiz), then the channel component. Harita / Google Ads / Meta render `App\Livewire\Operator\Workspace\{MapsTab,GoogleAdsTab,MetaTab}` only when the class exists, otherwise one line "Hazırlanıyor". **Ayarlar** (`?tab=ayarlar`, old tab ids still work: overview, business, assets, work, reports, files) keeps the former brand page (setup, İş bağlamı, services, assets, work, reports, files) plus small links to Aylık rapor and Tüm varlıklar. Default tab = Arama. Passive / brandless brand: one line `ServiceScope::NOT_SERVED`.
- **Terse components.** `x-workspace.stat` (label, value, ±%, note), `x-workspace.decision-card` (title · one-sentence why · action button (link or `runDecisionAction`) · Yapıldı / Ertele (7 gün) / Gereksiz · Kanıt toggle), `x-workspace.evidence-table` (collapsible `<details>` table), `x-workspace.tab-header` (last run line, "Yeniden analiz et", result line, one-line "Veri yok"). Livewire actions in `App\Livewire\Operator\Workspace\Concerns\HandlesAnalystDecisions` (used by BrandShow and every tab).
- **AI analyst engine (`App\Services\Analyst`).** Contract `Contracts\ChannelAnalyst` (channel, routeKey, buildPack, validate, allowedActions, instructions, presentAction, perform, baseline); `AbstractChannelAnalyst` = shared validation; `AnalystPack` (context, stats = Durum, sections of facts with stable ids, `missing`, `numbers()`, `trimTo(40k tokens)`); `AnalystEngine` (queue one active run per brand × channel → `RunChannelAnalystJob` (heavy queue with Horizon) → pack → skip with the one-line reason when no data / AI off → `ChannelAnalystAgent` (one structured agent, channel instructions injected; prompt `channel-analyst-v1`) on AI route `analyst.<channel>` (budget, failover, usage via `AiRouteResolver` context) → validate → `AnalystDecisionStore::persist` → run stats (tokens, cost via `AiPricing`, kept / dropped with reasons, also logged `analyst.decision_dropped`) → Üretim Arşivi kind `analyst.<channel>`); `AnalystRegistry::CHANNELS` (search live; maps / google_ads / meta reserved: route + tab appear when the class exists); `AnalystWorkspace` (top, forChannel, counts, present, perform / done / snooze / dismiss / reanalyze, lastRun).
- **Validation (drop + reason).** Title ≤ 90, why ≤ 160 chars and one sentence, at least one number in why and every number within tolerance of a pack number (0.5 abs or 5 %; Turkish formatting parsed), evidence refs exist, action type allowed for the channel and `params.target` an existing pack id of an allowed kind, title passes the brand's sector compliance (`BriefCompliance`), no duplicate key.
- **Storage.** `analyst_runs` (brand, channel, status queued / running / done / skipped / failed, trigger, pack hash / tokens, stats, tokens, cost, provider / model, received / kept, dropped, error, times) and `analyst_decisions` (fingerprint = sha256(channel|key), material hash = action type + params; title, why, priority 1–5, impact, effort, evidence refs + evidence snapshot, action type / params (+ `_target` fact), status open / done / dismissed / snoozed / expired, snoozed_until, note, resolved by / at, baseline, outcome, first / last seen). Rerun: open refreshed; done / dismissed stay closed unless the action materially changed; open decisions not proposed again → expired. Done stores a baseline (evidence + channel headline metric, e.g. organic clicks 28g) for a later outcome measurement (**measurement job not built yet**).
- **Schedule.** `moxdop:analyst:weekly` Mon 07:40 Istanbul (after SEO plan / URL verdicts / advisor): every live channel × operational brand, staggered 90 s; `--brand` / `--channel` for one brand. On demand: "Yeniden analiz et".
- **Arama (`Search\SearchAnalyst`, `Search\SearchFacts`, `Workspace\SearchTab`).** Durum: Organik tıklama 28g (±% vs previous 28 days, `gsc_property_daily`), Hizmet × bölge (% of active services × active service areas with a core query of that service × area at position ≤ 10 or a published page naming both), Sorgu kapsama (% of core queries — relevant, non-branded, under an active service — at position ≤ 10), Teknik blokaj (distinct standards failing with severity high on URL verdicts + high site checks), İçerik fırsatı (topic clusters new + strengthen). Pack: services, areas, coverage cells, topic map clusters (verdict, owner, position, missing queries), library clusters with SERP page-type evidence and the brand's impressions, top 150 core queries (GSC + Ads clicks), URL verdicts (problems + top-traffic pages with GA4 key events), failing critical standards, open SEO tasks (candidate facts), inventory + last post date. Actions: open_content_studio_idea (studio deep link `studio_cluster`), prepare_article (idea for the cluster → `ContentStudio::queueWrite` → `WriteContentArticleJob`), prepare_page_update (`ContentStudio::prepareUpdate`), open_fix (Sayfa Karnesi `url_q` / Standartlar / SEO), merge_redirect (Sayfa Karnesi `karar=merge`), set_service_area / map_service (brand edit). Kanıt: Hizmet × bölge matrix (✓ sıra / ✓ / —), top 25 core queries, blocker standards. No website → "Veri yok: markaya bağlı web sitesi yok."; no Search Console binding / data → "Veri yok: Search Console bağlı değil." (run skipped, no AI call).
- **Harita (`Maps\MapsAnalyst`, `Maps\MapsFacts`, `Workspace\MapsTab`) — channel `maps` is live (route `analyst.maps`, weekly run, tab; supersedes "reserved" above for maps).** One pack per brand with per-location facts (all active Business Profile assets with an active binding). Durum: Harita görüntüleme 28g (maps impressions, ±% vs previous 28 days, `gbp_performance_daily`), Arama + yol + web 28g (calls / directions / website clicks, ±%), Puan + yorum sayısı (Google snapshot rating / count, else computed), Yanıtsız yorum, Grid ilk-3 payı (average SoLV of the latest scan per keyword, only with `map_grid_runs` data), Profil standartları geçen/değerlendirilen (gbp_* standards). Pack sections / ids: `loc:<assetId>` (categories, area, phone / website / description length / hours / special hours / services / attributes, photos + last photo days, last post days + next planned post, rating, reviews total / 30d / 90d / previous 90d, unanswered, median reply hours, maps / search views, calls, directions, website clicks), `std:<assetId>:<gbp:id>` (failing / review standards with the value to set: "Ekle: …" missing services, "İşaretle: …" attributes, else the standard's solution), `svc:<offeringId>` (brand service in profile or not + impressions of matching searches), `kw:<assetId>:<hash8>` (non-branded Business Profile searches of the last months matched to a brand service: profilde var / profilde yok / markada hizmet yok), `rev:<reviewId>` (up to 15 unanswered per location), `grid:<runId>` + `comp:<runId>:<n>` (keyword, top-3 %, ARP, ATRP, top competitors with top-3 / top-20 cells), `nap:<siteId>` (Sayfa Karnesi `website:url:gbp_nap_consistency`), `adv:<assetId>:<rule>` (GBP advisor rules as candidate facts). Actions: reply_reviews (run: `ReviewReplyDrafter::queue` for the review or up to 5 newest unanswered of the location → drafts on İşletme Profili › Yorumlar, sent only by Admin, ADR-073), prepare_post (run: `GbpPostDrafter::queue` with the service / search as topic → draft in Gönderiler, operator schedules in the content calendar, Admin approves, ADR-073), fix_profile_field / add_service / upload_photos ("Elle düzenle" links to the asset's Profil sağlığı tab; profile fields are edited on Google by hand), open_grid (`/market/map-rankings?brand=&run=`), fix_nap (website Sayfa Karnesi). Channel check drops Q&A suggestions (retired by Google), keywords in the business name and discount / "en iyi" / guarantee titles. No Business Profile → "Veri yok: İşletme Profili bağlı değil."; bound but nothing collected → "Veri yok: İşletme Profili verisi henüz toplanmadı." (no AI call). **State:** CODED + PHPUnit (`tests/Feature/Analyst/MapsAnalystTest`: pack + Durum on seeded profile / performance / keywords / reviews / posts / photos / standards / grid / NAP, fake AI → 5 kept / 3 dropped (invented number, Q&A, name keyword) → cards render with profile / grid / scorecard links, reply drafts and post draft jobs queued, done baseline, missing-data lines without AI, tab live). **No live UAT, no real AI call.** Open: no direct Google profile field write (by decision); grid data only where the paid scan is switched on.
- **Bugün (`/`, `Demo\Dashboard`).** Replaced: operational brands with their most urgent open card (title · why) and open-card counts per channel; one system-alert line kept. The previous dashboard blocks (today metrics, command-center top list, asset alerts, Today panel) are no longer on the home screen (their routes stay).
- **Menu (`DemoMenu`).** Bugün · Markalar · Müşteriler · Sorgular (› Hizmetler) · Entegrasyonlar (› Keşfedilen varlıklar, WordPress siteleri, Kopya web siteleri, Veri merkezi) · Ayarlar (› Uyum, Etkinlik, AI kalitesi). Removed from the menu only (routes / code unchanged): Komuta merkezi (+ İş listesi, Uyarılar, Yenilemeler), Portföy sağlığı, Dijital varlıklar, Danışman, SEO görevleri, İçerik takvimi, Rakipler (+ Harita sıralaması, Rakip izleme, Backlink), Hizmet Beyni (+ children), Lead kutusu, Potansiyel müşteriler (+ Intent radar, WhatsApp), Aylık rapor (+ children), Ajans işletmesi. Their section tabs disappear with them. Notification bell unchanged.
- **Asset screens.** `x-operator.asset-context` shows one line "Analiz marka ekranında → [Marka]" (website → Arama, Business Profile → Harita, Google Ads, Meta) on the asset pages; no redesign.
- **State:** CODED + PHPUnit (`tests/Feature/Analyst/BrandWorkspaceAnalystTest`: pack + Durum numbers on seeded data, validation drops (invented number, unknown ref, disallowed action, wrong target kind, two sentences, > 160 chars, compliance, no number, duplicate), fingerprint persistence / done / dismissed / material reopen / expire / snooze, fake AI → stored cards → workspace renders cards with working studio / scorecard links and prepare_article dispatching `WriteContentArticleJob`, skip without Search Console, weekly schedule + operational gating, tabs "Hazırlanıyor" + Ayarlar, menu items, Bugün list, asset link) and adapted menu / dashboard / brand-page tests. **No live UAT, no real AI call** (prompt quality, token size on Panorama Ankara, cost per run unverified). **Open:** Harita / Google Ads analysts + tabs (Meta: see 2026-10-26) (deterministic advisors, GBP / Ads / Meta rule engines to be fed into their packs); outcome measurement of done cards; the old Komuta merkezi inbox still exists beside the cards (not merged); Arama pack has no GA4-only landing pages beyond URL verdicts.

## 2026-10-24 — Salt okunur tanı komutu (`moxdop:diagnose`)

- **What.** `php artisan moxdop:diagnose [--brand=<id|ad parçası>] [--asset=<id>] [--days=14] [--format=text|json] [--section=a,b]` prints one pasteable report for the developer: 1 ortam (commit from `storage/app/release.json`, queue / Horizon, scheduler + worker heartbeats, watchdog, backup, failed jobs 24h / 7d grouped by job class + first exception line), 2 sahiplik (customers, operational brands, asset type × bound × operational, passive-customer assets still collecting, brandless / empty brands, merged-but-active assets, duplicate website hosts, resources bound to >1 asset, resource ↔ asset type mismatches), 3 entegrasyonlar (status, auth state, token valid / expired / unknown, last discovery, resources discovered vs bound, connections, WordPress Connector versions), 4 veri toplama (per bound resource: automation state, consecutive failures, last error, latest fact date with **STALE** flag; with `--brand`: per dataset last attempt / success / consecutive failures / error and detail fact tables; without: worst 50), 5 web siteleri (page profiles document / non-document, sitemap URLs, HTML reads, projection, last site collection + steps, connector, SEO plan `input_summary`, topic map, URL verdicts, content studio), 6 sorgular (relevance / method / source counts, belirsiz, top unassigned queries, offerings, service areas), 7 danışmanlar (last plan per channel incl. SEO, open items, operational + asset alerts, command center items by source from `inbox_item_states`), 8 AI (routes, providers configured yes/no, 7-day usage / failures, monthly budget), 9 hatalar (`app_error_groups` 7 days, else `laravel*.log` tail). Problem lines start with `!!`; an **ÖZET** block lists them.
- **Read-only.** SELECT only (aggregates, limits, indexed lookups); on PostgreSQL the session is set `default_transaction_read_only = on` and `statement_timeout = 20s`. The database cache table is read directly (no expiry delete); no job, HTTP / provider call or AI call. Each section is isolated (missing table → "yok", exception → class + message). `DiagnosticMasker` masks tokens (Bearer, key=value, `ya29.`, `EAA…`, JWT, long opaque strings, signed URL params), e-mails and phone numbers; account ids show the last four characters; integration config and credential payloads are never selected.
- **State:** CODED + PHPUnit (`tests/Feature/Operations/DiagnoseCommandTest`: text + JSON, `--brand` scope, no insert / update / delete statements, `Queue::fake` / `Http::fake` nothing sent, planted token / e-mail / phone masked). **Not yet run on production PostgreSQL** (runtime < 60 s on real volumes unverified).
## 2026-10-24 — Web sitesi ve İşletme Profili standartları: AI arama araştırması (kaynaklar 2026-09-28)

- **What.** standards.json (`website-standards-v4`, 107 standards) reconciled with the 2026-09 research (Google "Optimizing for generative AI features" 2026-05-15, AI features doc, OpenAI / Perplexity / Anthropic crawler docs, Bing AI Performance Feb 2026, spam policies, Sağlık Hizmetlerinde Tanıtım ve Bilgilendirme Yönetmeliği RG 12.11.2025/33075, Business Profile guidelines). Every url_* / gbp_* standard has `source_url` + `source_reviewed_at`; texts are one Turkish sentence for the criterion and one for the solution. Editable (on/off, severity) in Kütüphane › Standartlar, which now has an **İşletme Profili** tab (`asset_type = google_business_profile`) and shows the source check date. New optional flag `informational: true` = shown as "Bilgi", never a defect, never the page verdict.
- **New website standards (Sayfa Karnesi, `UrlStandardEvaluator`):** `robots_search_engines` (Googlebot / Bingbot must reach home + service pages; RFC 9309 matching in `RobotsTxtRules`), `robots_ai_search_bots` (OAI-SearchBot, ChatGPT-User, PerplexityBot, Claude-SearchBot, Claude-User), `ai_training_bots` (info: GPTBot, ClaudeBot, Google-Extended, CCBot, Applebot-Extended), `snippet_controls` (nosnippet / max-snippet:0 / data-nosnippet ≥ 50 % of main text), `main_content_raw_html` (main text < 80 words in raw HTML **and** a JS app root → fail; short without JS markers = n/a), `service_content_depth` (health: ≥ 3 of süreç / süre / kimlere uygun / riskler / sonrası headings; all sectors: bottom-k MinHash ≥ 0.6 with another service page), `original_media` (low, info bucket: alt-texted non-stock image in main content), `schema_visible_match` (JSON-LD name / phone / postal code visible; tel: links count), `self_serving_review_markup` (low, info bucket), `tr_health_promotion` (health pack website rules on each read page's text → "Öneri" + Düzelt bucket), `tr_health_disclosure` (site: last-update date, sorumlu hekim / editör, EN pages → sağlık turizmi yetki belgesi), `indexnow` (Connector signed capability `indexnow`; `WordPressConnectorClient::status` now stores `config.capabilities`), `bing_webmaster` (msvalidate.01 meta; absent = unknown), `site_reputation_abuse` (casino / bahis / deneme bonusu / sponsorlu paths or spam words → Öneri), `ai_referral_tracking` (info: GA4 `ga4_source_medium_daily` 90-day sessions from chatgpt.com / perplexity.ai / copilot / gemini / claude.ai; `moxdop-url-audit.ai_referrers`).
- **Changed:** `faq_schema` → optional, informational, low, verdict bucket `info` (never Düzelt / Güçlendir alone); `hreflang_consistency` → x-default required on the home page only (Polylang default); `sitemap_hygiene` wording (lastmod; priority / changefreq ignored); `eeat_updated_date` → high, mentions regulation + editor; `eeat_author`, `medical_business_schema` wording. `PageSignalExtractor` adds main text words, JS root, snippet controls, Bing meta, headings, image counts, MinHash sketch, self-rating, visible-NAP flags, editor / health-tourism / spam words.
- **Myths removed elsewhere:** SEO task `robots-bot-block` checks only search / user-fetch bots (training bots and Google-Extended removed from `moxdop-seo-tasks.ai_visibility.blocked_bots`); `faq-block` task is low, needs read HTML, counts visible question headings (FAQPage optional, no AI-citation claim); competitor comparison no longer suggests FAQPage / HowTo / AggregateRating / Review as schema gaps; `org-same-as` / Komuta merkezi texts neutral.
- **Business Profile standards (`gbp_*`, `GbpStandardEvaluator` pure + `GbpStandardInput` reader):** primary category fit per sector (dental / healthcare / medical_aesthetics / legal), name without district / service words (never recommended; Öneri = suspension risk), services vs the brand's treatments that have a site page (else priority, else all), attributes (≥ 3), description (present, ≤ 750, no URL, health-pack compliant), regular hours + special hours for upcoming Turkish holidays (`App\Support\TurkishPublicHolidays`, 2026–2027 incl. arefe; extend yearly), NAP = the website's Sayfa Karnesi `gbp_nap_consistency`, photo ≤ 90 days, post ≤ 30 days and no offer / indirim post, review velocity + 90-day vs prior-year rating, reply rate ≥ 90 % and median reply ≤ 48 h. Missing data = Veri yok / Uygulanmaz. Shown in İşletme Profili › **Profil sağlığı** (replaces the old overlapping checklist items; additional categories, phone, website, photo count remain), score over evaluated items only; Komuta merkezi "Profil eksikleri" counts only Sorun / Öneri items.
- **GBP Q&A:** never implemented here (API retired 2025-11-03); a test guards that the collector, schedule and GBP rules have no Q&A.
- **State:** CODED + PHPUnit (`Website/UrlStandardEvaluatorTest` 37 url_* standards incl. robots / site / sketch / extractor tests, `Website/UrlVerdictTest`, new `Gbp/GbpStandardsTest`, `Gbp/GbpWorkspaceTabsTest`, `Assets/GbpAssetPageTest`, `SeoTasks/SeoTaskRuleEngineTest`, `Demand/CompetitorPageComparatorTest`, `WordPressConnectorV1Test`, `WebsiteStandardsAssessmentTest`). **No live UAT.** Heuristics (section headings, stock-photo hosts, spam words, name keywords, category fit) need operator review on real brands; holiday table needs yearly extension; `gbp_no_review_incentives` is not automatable (not added).
## 2026-10-24 — Step 2: sahiplik kurgusu, sektör ataması, sorgu hattı

- **Query pipeline (`App\Services\Queries`).** `QueryIngestor` reads stored Search Console / Google Ads / Business Profile facts of **every** discovered account (bound or not; facts without a known account per website) over 90 days (GBP 3 months), incremental per account (facts + context fingerprint in `query_ingest_states`), normalizes each raw query (`QueryNormalizer`) and archives it in `query_variants` with flags; core-kind variants create / reuse the core query (`CoreQueryStore`, folded `core_key`, operator renames via aliases, removed cores never recreated) filed under the account's sector; core metrics re-aggregated. `QueryServiceMatcher`: rules → AI batch fallback (operational only) → operator (manual wins). `QueryClusterer`: AI clusters per service (fallback `ClusterBuilder`). `ClusterPageResearch`: DataForSEO SERP top 10 → hizmet / blog / SSS / karşılaştırma with evidence, 30-day cache. `AssetSectorService`: sector for every account / website (brand-derived, AI batch, manual). `QueryPipeline` orchestrates; `RunQueryPipelineJob` (daily + after each successful account pull), `ClusterQueriesJob` (weekly + on demand). Commands `moxdop:queries:pipeline`, `moxdop:queries:cluster`, `moxdop:ownership:integrity`.
- **Data model.** New: `query_variants`, `query_ingest_states`, `asset_sectors`, `sector_product_brands` (seeded defaults for dental / healthcare / medical aesthetics / beauty). Extended: `search_query_library_items` (+ `core_key`, GSC / Ads / GBP metrics, `variant_count`, `metrics_at`), `search_query_library_sectors` (+ `match_status`, `match_method`, `matched_at`, `ai_checked_at`), `library_query_clusters` (+ `page_decision`, `decision_source`, `serp_evidence`, `research_query`, `research_fingerprint`, `researched_at`). Backfill: core keys, existing service links = matched, old per-account sectors → manual asset sectors.
- **Brand hub / topic map.** `BrandDemandBuilder` now reads the brand's variants from the global store (keyed by core query; own-brand-only queries = branded rows; competitor / banned out; a core marked irrelevant for the brand's sector = alakasız). `TopicMapBuilder` uses the pipeline clusters (name, page type) and clusters only the rest.
- **UI.** Entegrasyonlar › **Keşfedilen varlıklar** (`/integrations/discovered`): counts, marka önerileri (one-click Onayla with customer choice, Düzenle → Toplu ekle), sahiplik sorunları + Güvenli düzelt, every asset with editable sector (AI % / Manuel / Marka). Pazar › **Sorgular** (`/library/search-queries`, replaced): tabs Sorgular (filters sektör / hizmet / eşleşmemiş / küme / kaynak / arama; sorgu, hizmet, küme, gösterim / tık, Ads, varyant (açılır); bulk Hizmete taşı / Alakasız; elle ekle), Kümeler (sayfa türü dropdown, Google ilk 10 kanıtı or "kanıt yok", Şimdi kümele), Rakip marka, Alakasız / yasaklı (geri al + exclusion rules), Ürün markaları. Resource automations keep only collection settings.
- **Removed.** `AutomaticQueryImportService` + jobs + per-account query mapping UI; Brain kinds account_mapping / query_service / service_clusters (+ their agents and tests, rewritten where the page flow is still covered); account-source imports; old Sorgular page, AI candidate panel views.
- **State:** CODED + PHPUnit (`tests/Feature/Queries/*`, updated Demand / Brain / Ownership tests; SQLite). PostgreSQL compact-fact views (`id` NULL, `updated_at` = collected_at) are handled by the fingerprint but not exercised by tests. **No live UAT, no real AI / DataForSEO call.**
- **Not done / open:** grouping proposals are deterministic (no AI naming); Meta / GA4 have no queries (sector only); the AI query-candidate librarian (`SearchDemandLibrarianService`) is no longer reachable from the UI; website title / meta are not yet a sector signal; the new brand workspace screen (later step) should read `query_variants` / core queries directly.

## 2026-10-23 — SEO içerik hattı Faz 5–6: URL karnesi (URL bazında karar) ve SEO temel standartları

- **What.** Website › **Sayfa Karnesi** is now the one per-URL screen: every document URL of the site — page inventory (crawl, sitemap-discovered, WordPress), sitemap-only URLs (`website_sitemap_watch.pages` + `website_url` `source = sitemap`) and measured URLs (Search Console, GA4, Google Ads, open SEO tasks, open site fixes) — gets **exactly one verdict** with a Turkish reason, a concrete solution and an action link where one exists: **Düzelt**, **Birleştir / yönlendir**, **Güçlendir**, **Dizinden çıkar**, **Kontrol et** (names the missing data), **Sorun yok — gerek yok** (lists what was checked). The old metric columns stay (Google clicks ± %, GA4 sessions / key events, main channel, Ads clicks / cost / conversions, index · LCP).
- **Join (`App\Services\Website\UrlAudit\UrlAuditService`).** Crawl / head facts (HTTP, final URL, noindex, canonical, title, H1, word count, schema types), WordPress object (type, status, modified, Polylang language + translations), 28-day GSC clicks / previous / impressions + impression-weighted position and top queries, GA4 + channel mix + Google Ads (via `PageScorecardReader`), URL inspection verdict, lab LCP, sitemap membership, internal inlinks (`website_link_edge`), cluster ownership (`library_cluster_targets` + verified `SearchDemandPageOwnership`), Brain cannibalization (`brain_cannibalizations`, open), open `SiteFixItem`s (open / failed / queued), open SEO tasks, the latest stored **Standartlar** run (page-level fail / review results by URL; site checks shown once) and, for up to `moxdop-url-audit.html_pages` (400) most important pages, stored HTML signals (`MoxDop\Website\Standards\PageSignalExtractor`: author, medical review, visible date, FAQ content, JSON-LD types / dateModified / Organization NAP, hreflang, `<html lang>`, phones). Stored data only; no provider or AI call.
- **Verdict order (`UrlVerdictResolver`).** Birleştir (doorway group member or cannibalizing page, not the kept one) → Dizinden çıkar (test / taslak / kopya page open to Google) → Düzelt (failing page standard, open site fix, 4xx/5xx, valuable page noindex) → Kontrol et (never crawled, or an important page whose HTML was not read) → Dizinden çıkar (thin, no traffic, no inlinks, not a service page; sitemap / orphan advice suppressed) → Güçlendir (position 5–20 with ≥30 impressions, decay, thin but valuable, missing FAQ / Article / Breadcrumb / MedicalProcedure data, weak service inlinks) → Sorun yok. Every finding keeps kural / bulgu / çözüm / severity and an action (Düzeltmeler tab with the fix phase, SEO görevleri, Standartlar, Search Console).
- **Precompute.** `website_url_verdicts` (one row per URL: verdict, severity, priority, reason, solution, metrics, findings, facts) + `website_url_audits` (status, counts, site checks, sources, doorway groups). Refreshed by `RefreshUrlVerdictsJob` after a projection rebuild, after a completed SEO plan, weekly (`moxdop:website:url-verdicts`, Mon 07:10) and by the **Yenile** button (queued `Run`, operation `website_url_verdicts`, visible in Etkinlik). Only operational websites (`ServiceScope`); a passive customer's site shows the service-scope notice and no rows.
- **UI.** Counts per verdict as filter chips, search, 50 per page ordered by priority (verdict, severity, traffic, service / home bonus), site-level checks and doorway / near-duplicate groups once on top, a detail row per URL with the reason, solution, group (kept page + members), every finding with its action, joined facts and the passed / not-applicable checks.
- **Faz 6 standards** (standards.json, `method = url_*`, `evaluated_in = url_audit`, editable / switchable / severity in Kütüphane › Standartlar; skipped by the stored Standartlar run; `MoxDop\Website\Standards\UrlStandardEvaluator`, missing data = `not_applicable` / `unknown`, never fail): `doorway_group` (slug head after removing provinces, the brand's areas and their districts, and modifiers such as merkezi / kliniği / yapan-yerler / diş; ≥3 pages; keep = most GSC clicks → impressions → service page → inlinks → shortest; others 301 / merge; distinct services never grouped), `near_duplicate_title`, `cannibalization`, `eeat_author`, `eeat_medical_review` (health), `eeat_updated_date`, `eeat_trust_pages` (site), `medical_business_schema` (site, health), `medical_procedure_schema` (health), `organization_nap_schema` (site), `faq_schema`, `article_schema`, `breadcrumb_schema`, `hreflang_consistency` (reciprocal, x-default, self in list, canonical self, `<html lang>` and Polylang language vs hreflang, Polylang translations present), `orphan_page`, `service_inlinks`, `content_decay`, `thin_content`, `sitemap_hygiene`, `sitemap_missing`, `index_coverage`, `gbp_nap_consistency` (site; phone / name / postal code vs the brand's single bound Business Profile). YMYL = any enabled sector pack for the brand; health = the health pack.
- **State:** CODED + PHPUnit (`tests/Feature/Website/UrlVerdictTest`, `UrlStandardEvaluatorTest`, updated `Measurement/PageScorecardTest`; SQLite). **No live UAT** (panoramaankara.com doorway group, Polylang sites and the weekly schedule need operator verification). Known gaps: merge / redirect actions link to the Düzeltmeler tab (redirect fix items are still only created for 404s — no one-click "301 these pages" yet); E-E-A-T / FAQ detection is heuristic text / markup matching; pages beyond the HTML limit show "sayfa HTML’i" as missing data.
## 2026-10-23 — SEO içerik hattı Faz 3–4: konu haritası ve İçerik Stüdyosu

- **Topic map (Faz 3).** `App\Services\ContentStudio\TopicMapBuilder` builds one map per website from `BrandQueryHub::rowsFor` (relevant + unclear-with-service; branded / alakasız excluded): groups by offering × intent class, clusters with `ClusterBuilder::clusterSet()` (new public entry point; `build()` for library services unchanged; brand maps use cached embeddings only). Per cluster: Turkish label, service, intent (Bilgi / Ticari / Yerel / Marka), page type (Hizmet sayfası / Blog rehberi / SSS / Karşılaştırma; Lokasyon only from service areas), demand score (site GSC impressions + volume + Ads + GBP), queries with metrics, owner URL (Search Console page with ≥ 35 % of the cluster's impressions, else an inventory page naming every topic word), coverage (karşılanıyor / zayıf / karşılanmıyor), cannibalization (≥ 2 URLs ≥ 20 %), similar existing content, verdict (Gerek yok / Güçlendir / Yeni içerik / Birleştir) with reason, missing queries, sections and FAQ. Tables `topic_map_builds`, `topic_clusters`, `topic_cluster_queries` (version per rebuild). Rebuild: weekly `moxdop:topics:build` (Mon 05:55), after `BuildBrandDemandJob`, "Konu haritasını yenile" (queued `BuildTopicMapJob`). Operator edits (`TopicMapEditor`: rename, skip, merge, split, move query) survive rebuilds.
- **İçerik Stüdyosu (Faz 4).** Website › "İçerik Stüdyosu" tab (`ContentStudioPanel`; link from Marka › İşletme). Konu haritası: cluster cards with one verdict and actions ("Fikir oluştur", "Güncelleme taslağı hazırla" → ADR-070 `content_update` site fix with a brief of missing queries / sections / FAQ, "Sayfa ekranında incele" for merge), query table (move / split), rename, skip, merge. Konu fikirleri: "Eksik konulardan fikir çıkar" (uncovered + weak informational clusters, service-area locations), "Konu üret" (services + count: topic map first, then `ContentIdeaAgent` for the gap, one call per batch, existing titles sent and re-checked), "Fikir ekle", edit / remove. Each idea: title, focus keyword, target queries, page type, target URL (`SiteUrlPattern`), H2/H3 outline, FAQ, 2–3 internal links to real inventory URLs (service page + related posts), similar existing post; every phrase sector-checked; ≥ 0.8 similar to an existing title = not proposed. Yazılar: "Hazırla" / "Seçilenleri hazırla (N)" with cost estimate + confirm → queued `WriteContentArticleJob` (progress bar, poll) → `ArticleWriter` (`ArticleWriterAgent`, route `content.article`: 850–1100 words configurable, H2/H3, list, FAQ, internal links, SEO title ≤ 60, meta ≤ 155, slug, excerpt, categories mapped to the site's WordPress categories, YMYL closing note) → compliance gate, one re-prompt, else "Uyum sorunu var"; quality checks listed. "Diğer dillerde de hazırla" (Polylang > 1 language) → `LocalizeContentArticleJob` per language, linked by translation key. "Seçilenlere tarih ata" (start date, time, N/day, Istanbul time; languages follow). "WordPress’e taslak gönder" (`ContentDraftPublisher`, Admin, one action per article with its languages) and "XML indir" (`operator.website.wxr-export?articles=…`, languages included, `_moxdop_translation_key`). Preview + inline edit (title, SEO title, meta, HTML) re-checks compliance. Table `content_ideas`, `content_articles`.
- **SEO tasks.** `SeoPlanInputCollector` adds `topic_clusters`; when a map exists the rule engine's Create tasks come from uncovered clusters (`evidence.source = topic_map`) and service-area location pages (`service_area`, ≤ 2 per plan, one per service × area, skipped when a page names both) instead of GSC / library buckets; missing-service-page and minimum fallback stay; inventory guard unchanged. Create / Strengthen cards get "Stüdyoda hazırla" (deep link opens and selects the idea). The "Hizmet bölgesi dışındaki aramalar" card (`out-of-area-demand`) is removed.
- **AI / scope.** Routes `content.article`, `content.ideas` registered (budget, failover, usage, quality report); outputs archived (`content.article`, `content.ideas`, `content.localized`). Every entry point checks `ServiceScope`; jobs re-check.
- **State:** CODED + PHPUnit (`tests/Feature/ContentStudio/ContentStudioTest`: clustering by service / intent / owner / coverage / merge, edits survive rebuild, dedupe against existing titles, ideas with real internal links, AI gap ideation, bulk write + re-prompt + needs_fix, Polylang versions, dates, WXR, publish via ContentDraftPublisher + WordPress fake, SEO tasks from clusters + locations + no out-of-area card, ServiceScope, Livewire studio + SEO card link, weekly command + hub hook). **No live UAT** (real hub data, real AI output quality/length, real WordPress import not verified). Open: no cost cap per bulk beyond the estimate + AI budget; categories only from the connector taxonomy snapshot; merge verdict links to the URL screen only (no merge action); SearchDemand AI clusters not shown in the studio.

## 2026-10-22 — SEO içerik hattı Faz 0: sayfa envanteri güvencesi

- **Why.** A site with an empty page inventory (real case: panoramaankara.com, ~774 sitemap URLs, "Sayfa envanteri: 0 sayfa") got "İmplant Tedavisi için hizmet sayfası aç" although `/tedavilerimiz/implant-tedavisi/` exists, `/blog/…` URLs although the site has no /blog/ folder, and "… fiyatları" guides for a health brand. The SEO plan never triggered a crawl, and a non-partial projection rebuild with zero pages deleted every page profile.
- **Inventory guarantee.** `SeoPlanRunner` → `SeoInventoryGuard::ensure()` when the plan has 0 document pages: active collection → nothing new; website collection (with `website_url`) finished within `moxdop-seo-tasks.inventory.recollect_after_hours` (12) and not yet projected → `RebuildWebsiteProjectionJob`; otherwise `CollectWebsiteInventoryJob` (unique per asset) → `WebsiteCollectionOrchestrator::start()` (public families: homepage, robots, sitemap, crawl; ServiceScope gating and the orchestrator's one-active-run rule apply). State is stored in `seo_plans.input_summary.inventory`.
- **Rules on an empty inventory.** No Create tasks, no "missing service page" bucket, no service ↔ page assignment writes; one setup card (`rule_id = inventory-missing`, question type): "Sitenin sayfa listesi henüz yok — tarama başlatıldı; tarama bitince plan kendiliğinden yenilenir." (other texts for rebuilding / empty after crawl / not started). The card goes stale on the next plan with pages.
- **Automatic re-plan.** `RebuildWebsiteProjectionJob` calls `SeoInventoryGuard::afterProjection()`: if the site's latest completed plan was blocked by an empty inventory and profiles now exist, a plan is queued with trigger `inventory_ready`. A blocked `inventory_ready` plan is not repeated (no loop).
- **Sitemap-first coverage.** Sitemap URLs (`website_url`) already become page profiles on rebuild; the rule engine now counts URL-slug-only rows in coverage (`pageTextIndex`) and skips the missing-service-page bucket when any page's title/H1/slug names the service.
- **Prune safety.** `WebsiteProjectionRebuilder` never deletes all profiles of a kind when a non-partial rebuild produced none of that kind (logged `website-projection.prune-skipped-empty-result`); pages that disappeared are still pruned when the rebuild has pages. Brandless / soft-deleted-brand websites are skipped (`rebuild()` returns null, logged) instead of failing the queue.
- **Duplicate merge.** `WebsiteDuplicateMerger::merge()` queues a projection rebuild for the keeper after the commit.
- **URL structure.** `App\Services\SeoTasks\SiteUrlPattern`: guides go under the folder the site's posts use (WordPress `post` type or /blog/, /bloglar/, /makaleler/… prefixes) or at root slugs; new service pages under the dominant service section (/tedavilerimiz/, /hizmetlerimiz/ …) and a matching category folder; location / FAQ at root. /blog/ is never invented.
- **Sector compliance.** The plan input carries the brand's active sector-pack rules (`SectorPackRegistry::rulesForBrand`); the rule engine uses `ComplianceChecker` (source `seo_brief`) to drop forbidden seed queries, choose compliant outline lines / page titles and rephrase the targeted topic ("implant tedavisi fiyatları" → "Rehber yaz: implant tedavisi süreci"); GSC queries stay as evidence. Strengthen checklists only suggest compliant queries for title/H1/meta. Health pack gains `price_emphasis` (fiyat / fiyatları / ücret …; `ai_draft` + `seo_brief` only, live site/ad text not scanned).
- **UI.** SEO panel "Kurulum bekleyen" shows "Sayfa listesi yok" instead of "Hizmet ↔ sayfa eşleşmeleri tamam" while the inventory is empty; the setup card shows "Tarama sürüyor" or a link to the site screen.
- **State:** CODED + PHPUnit (`SeoInventoryGuaranteeTest`, `SeoRuleEngineInventoryComplianceTest`, `SiteUrlPatternTest`, `WebsiteProjectionPruneSafetyTest`, `WebsiteDuplicateMergeTest`). **No live UAT** on panoramaankara.com; the real crawl → rebuild → re-plan chain needs operator verification after deployment. Known gap: if the rebuild finishes while the blocked plan is still running, the re-plan waits for the next plan run.
## 2026-10-22 — SEO içerik hattı Faz 1–2: marka sorgu merkezi (sorgu → hizmet / sektör / markalı / niyet / ilgililik)

- **One query hub per brand** = `brand_demand_queries` (extended, no new store) + `brand_demand_query_assets` (site-specific Search Console metrics per website). `BrandDemandBuilder` merges by `SeoText::fold`: Search Console queries (per website; impression-weighted position from `provider_average_position`), Google Ads search terms, Business Profile keywords, the brand's query portfolio (library / custom queries, latest stored DataForSEO volume, competitor count from `search_demand_competitor_queries`), library source-record volume (only for place-free queries) and stored area SERP keywords (`demand_serp_checks`, best rank). Per row: `sources` + `source_mask`, GSC / Ads / GBP metrics, `search_volume`, `serp_rank`, 28-day trend (last 28 vs prior 28 days, GSC + Ads impressions), `first_observed_on` / `last_observed_on`, library / portfolio links. Stored data only — no provider, AI or paid call (DataForSEO spend untouched).
- **Brand-level service resolver** (`BrandQueryServiceResolver`): rules (offering names, catalog names / aliases, matching expressions, suffix-tolerant, place names ignored) → portfolio services → the Brain's global library mapping (library pivot incl. approved "Sorgu → hizmet" proposals) restricted to the brand's offerings → cached-embedding similarity to each offered service's centroid (`EmbeddingService::cached`, never calls the provider) → "belirsiz". Method (`rule` / `portfolio` / `library` / `embedding` / `operator`) and confidence stored.
- **Joins per row:** sector (offered service → library item → matching catalog phrase), branded (`BrandedQueryMatcher`), intent (`QueryIntent`), relevance: `relevant` (service or branded), `unclear` = belirsiz, `irrelevant` = alakasız (another sector's service phrase / library sector, or an active query-exclusion rule; never deleted). **Queries are single-type:** no per-query service area / in-area / out-of-area; a place-named query is one ordinary row (old `location_status` / `locations` / `brand_service_area_id` cleared by the migration and no longer written). Area SERP now checks only place-free, non-alakasız queries.
- **Operator review:** Marka › İşletme › Sorgu merkezi (`BrandQueryHubPanel`): filters kaynak / hizmet (incl. "Hizmet yok") / ilgililik (belirsiz, alakasız, ilgili, tümü; default hides alakasız) / markalı / site / arama, counts per service and per relevance, 50 per page, bulk "Hizmete ata", "Onayla", "Alakasız işaretle". Operator decisions (`assignment_source` / `relevance_source` = operator, `reviewed_by`) win over every rebuild. "Şimdi yenile" on Talep now runs `BuildBrandDemandJob` in the background.
- **Read API for the clustering → content phase:** `App\Services\Demand\BrandQueryHub::rowsFor(Brand, ?DigitalAsset $site, array $filters, int $limit)` (row shape in PHPDoc; default excludes alakasız), plus `query()`, `serviceCounts()`, `relevanceCounts()`, `assign()`, `markIrrelevant()`, `confirm()`.
- **Gating:** `moxdop:demand:build` (weekly Mon 05:30) and `build()` serve only operational brands (`ServiceScope`); a passive customer's brand is skipped. Sector patterns ignore portfolio / SERP-only and alakasız rows.
- **State:** CODED + PHPUnit (`tests/Feature/Demand/BrandQueryHubTest`, `BrandDemandBuilderTest`, `AreaSerpCheckerTest`; SQLite). PostgreSQL position expression (`metadata->>'provider_average_position'`) not exercised by tests. **No live UAT.**
- **Not done:** (the SEO task engine reads the hub through the topic map since Faz 3–4); Google Ads keyword (criterion) texts are still not imported; embeddings are never generated by the hub (only reused when the Brain already cached them).
## 2026-09-28 — İçerik dağıtımı: uyum kapısı, zengin / çok dilli WordPress taslağı, WXR (ADR-076, Connector 1.5.0)

- **Compliance gate (`App\Services\ContentDelivery\ContentComplianceGate`).** Title, SEO title, meta description, focus keyword, excerpt, slug and body of every AI page / article are checked against the brand's sector-pack rules (source `ai_draft`); every offending phrase of a rule is listed with its field. High / medium hits block `requestContentDraft`, `requestArticleDrafts` and the WXR export (Turkish message with the phrases). Site fixes › Sayfa metni: violations box, "✨ Yeniden yaz (uyumlu)" (PageWriterAgent gets `compliance_fix` with the phrases, prompt v2), "Metni düzenle" (title + HTML, sanitised, re-checked), send / export disabled while blocked. Health pack: new rule `claims_en` (English superlatives, guarantees, painless / comfortable / fast / safe, price, expert…).
- **Rich drafts.** `ArticleDraft` (value object: title, html, reference, slug, excerpt, meta title / description, focus keyword, categories / tags, language, post type, date, schedule, translation key; `fromSiteFixItem`). `WordPressDraftWriter::payload()` builds the /drafts payload (old keys first; empty fields omitted). New-page site fixes now send slug + SEO fields.
- **Plugin 1.5.0.** `/drafts` accepts slug, excerpt, post_date (+ `schedule`, only with the site option "Scheduled drafts", default off), categories / tags by name (created if missing), SEO title / description / focus keyword (Yoast, Rank Math, SEOPress, else own fields), `language` + `translation_of` (Polylang: language set, translation group merged, language-appropriate category terms created and linked). Without Polylang the language fields are ignored. Snapshot `site` and `/status` list Polylang languages; `/status` capabilities add `rich_drafts`, `polylang`, `schedule`. Trash accepts scheduled MoxDOP posts. Tools › MoxDOP Polylang: post-WXR-import pairing by `_moxdop_translation_key` / `_moxdop_language` (dry-run table, admin-only apply, idempotent). `rich_drafts_min_plugin_version` 1.5.0; `connector_version` 1.5.0 (self-update from ≥ 1.4.1).
- **Multi-language publish.** `ContentDraftPublisher::publish(User, DigitalAsset, ArticleDraft|array, array $languages)` → one `article_drafts` action (Admin, queued, recorded): source first, then each translation with `translation_of` = source post id; partial results kept; undo trashes translations then the source. Languages are checked against the site's Polylang languages (`LanguageLinkMap::languages`, from the site snapshot metadata `languages`).
- **AI localization.** `ContentLocalizer::localize(ArticleDraft, string $lang, DigitalAsset)` with `ContentLocalizerAgent` (route `content.localize`, registered in `AiInsightServiceProvider`, usage mapped). Localizes title / slug / meta / body / categories; internal links → known translation (Polylang translations in the content snapshot, else crawled hreflang) or the language home; one re-prompt with violations when the result breaks rules. Synchronous — callers must run it from a job.
- **WXR export.** `WxrExporter::export()` (WXR 1.2, drafts, CDATA, local + GMT dates, category nicename, Yoast + Rank Math postmeta, `_moxdop_translation_key` / `_moxdop_language` / `_moxdop_draft_reference`); `ContentExportService::wxr()` gates it. Download: `GET /assets/website/{site}/wxr-export?items=…` (`operator.website.wxr-export`, Admin only; new-page proposals; "WXR olarak indir" in the panel).
- **State:** CODED + PHPUnit (SQLite, `ContentDeliveryTest`: payload, compliance block + compliant rewrite + edit, English rules, publish order / translation_of / undo / refusals, localizer links + re-prompt, WXR XML with DOM/XPath, download auth, plugin `php -l`). Plugin 1.5.0 reviewed and `php -l` clean only — **not run on a real WordPress / Polylang site**; WXR not yet imported into a real WordPress. **No live UAT.** Open: no UI yet for cluster → article → localize → publish (next phase calls these services); no persistent article store; localization must be wrapped in a queued job by the caller.

## 2026-10-21 — Kopya web sitelerini birleştirme

- **`App\Services\Ownership\WebsiteDuplicateMerger`.** `findGroups()` groups website assets sharing a normalized host (OwnershipGuard rule: www., scheme, path ignored; URL or domain column) with owner, age and data counts (active bindings, pages, collected facts, SEO tasks, site fixes, WordPress connector). Default keeper: active customer's brand → active bindings / paired connector → most data → oldest; the operator can pick another. `plan()` is a dry run; `merge()` runs in one transaction (Admin only).
- **Schema-driven move.** Every table with a foreign key to `digital_assets` or a `digital_asset_id` / `website_asset_id` / `source_digital_asset_id` column moves in chunks. A row that would break a unique key: keeper's row stays, the duplicate's is dropped (rows pointing at it are re-pointed first, up to 3 levels); `ad_budget_status` / `website_sitemap_watch` keep the newer row; `wordpress_site_health` follows the winning connector. Bindings: one per capability (keeper's active wins; a duplicate's active binding replaces a keeper's inactive one; the loser stays on the duplicate, disabled, `closed_reason = merged`). Connectors: the paired, enabled one wins; the loser is disabled on the duplicate. Moved rows' `brand_id` / `customer_id` (FKs) follow the keeper; a brandless keeper takes the duplicate's brand.
- **Duplicate archived, never deleted:** status archived + soft delete + `merged_into_asset_id`. Every merge is logged in `asset_merges` (moved / dropped counts per table, note "merged into #id"). Cross-customer merges need the Admin's confirmation ("Yetki devrini onaylıyorum") and write `ownership_transfers`; moved accounts' mapping is reset like a transfer.
- **Operator UI:** Entegrasyonlar › Kopya web siteleri (`/integrations/website-duplicates`): groups, keeper radio, "Önizle" (what moves / collides), "Birleştir" (Admin; inline yetki devri panel for cross-customer groups), recent merges.
- **CLI:** `moxdop:websites:merge-duplicates` (dry run by default; `--apply` merges same-customer groups only, cross-customer groups are listed for UI confirmation; `--by=` Admin).
- **State:** CODED + PHPUnit (SQLite, `WebsiteDuplicateMergeTest`). PostgreSQL path not exercised by tests (compact fact views are skipped; their fact tables move). **No live UAT.**
## 2026-10-21 — İşletme Profili günlük çalışma alanı, yorum toplama düzeltmesi

- **Why reviews were missing (root causes).** Reviews, media and posts are read from the legacy v4 API (`mybusiness.googleapis.com/v4/accounts/{a}/locations/{l}/…`), which needs the account id. A location discovered through the wildcard route (`accounts/-`) is stored as `locations/{id}` without `parent_external_id`.
  - The collector re-resolved the account on every run through the Account Management API and **never stored it**, so the ADR-073 writes (`GbpWriter::location`) kept failing with "Konumun hesap bilgisi yok" even after collection.
  - In the "Verileri yenile" path (`GoogleBusinessProfileBoundCollector::collect`) a failing account lookup was **not caught** and failed the whole run.
  - When Google refused reviews (v4 needs an approved Business Profile API access project + "Google My Business API" enabled; otherwise HTTP 403 `PERMISSION_DENIED` / `SERVICE_DISABLED`), the reason stayed in run metadata in English; the Yorumlar tab only said "Henüz yorum verisi toplanmadı".
  - GBP content retention purges review rows 30 days after `collected_at`; a location whose collection stops loses its reviews.
- **Fix.** The resolved account is saved on the location (`parent_external_id`); account-lookup failures no longer fail the run and are reported with the v4 datasets. Reviews are collected daily (resource automation, `interval_days` 1) and **incrementally** (`orderBy=updateTime desc`, stop at the newest stored review minus one day), with a full pass every `moxdop-gbp-collector.reviews_full_sync_days` (3) days that also refreshes `collected_at` against the 30-day purge and picks up replies written on Google. `GbpDailyWorkspace::reviewAccess()` turns the last `gbp_reviews` result into Turkish ("Google bu hesap için yorum erişimi vermedi (API onayı gerekli)…", account API off, 401, 404, 429, time budget), shown on Özet and Yorumlar with the raw Google message.
- **Asset page tabs** (`/assets/gbp/{id}`): Özet · Yorumlar · Gönderiler · Performans · Profil sağlığı · Yorum toplama · Danışman.
  - Yorumlar: unanswered first, rating filter (1–2 / 3 / 4–5), "yalnız yanıtsız", age and "48 saati geçti", AI reply draft (existing drafter), own reply, "Google'a gönder" (Admin, ADR-073 `requestReviewReply`), write status / error, "Geri al" (undo), median reply time, link to the open low-rating alert, competitor review comparison when DataForSEO review intel exists.
  - Gönderiler: calendar posts of the profile + posts collected from Google, weekly rhythm hint ("Son gönderi N gün önce; haftada 1 önerilir"), "Yeni gönderi" form (title, text, button, URL, time), AI draft (`GbpPostDrafter` / `GbpPostAgent`, route `gbp.post_draft`, archived as `gbp.post`, queued job) → "Forma aktar"; "Takvime kaydet" (anyone), "Onayla ve zamanla" / "Şimdi yayınla" / "Onayla" (Admin), "Geri al" deletes the post and returns the calendar item to draft. A post Google refuses is marked failed with the reason (page, publisher, write service).
  - Performans: existing metrics with period compare and search keywords.
  - Profil sağlığı: read-only checklist (categories, description, hours, special hours, phone, website, photos + cover/logo, attributes, services) with Turkish to-dos and a link to business.google.com. Nothing is written to the profile.
  - Yorum toplama: `https://search.google.com/local/writereview?placeid=…` with copy button and an inline SVG QR code (chillerlan/php-qrcode, already installed; skipped when absent).
- **Komuta merkezi** (`GbpSource`, source `gbp`): reviews waiting > 48 h (hidden while the low-rating alert is open), no post for 14 days and none planned, reviews Google refuses, profile gaps when the advisor has no open "profile-gaps" item. TopicCatalog topics `gbp:*`.
- **Google access the operator needs:** a Cloud project approved for the Business Profile APIs (access request form), with "Google My Business API" (v4: reviews, replies, posts, media), My Business Account Management, Business Information, Business Profile Performance enabled; the connected Google user must be owner/manager of the location.
- **State:** CODED + PHPUnit (SQLite: `GbpReviewCollectionTest`, `GbpWorkspaceTabsTest`, `GbpCommandCenterTest`). **No live UAT** against a real approved GBP project.
## 2026-10-21 — Çok hesaplı markalar ve marka kurulum durumu

- **One account = one asset, N accounts per brand.** A brand can have several Google Ads / Meta Ads / İşletme Profili accounts; each is its own asset (binding cardinality unchanged: one active account per asset, one asset per account). Discovery already lists every client account under every accessible MCC (customer_client, all levels, login-customer-id kept per account) and every owned / client ad account of every selected Meta Business; collection runs per account.
- **Brand totals count every account once (`BrandMeasurementScope::rows()`).** Central rows (no asset id) now win per account instead of brand-wide, so one account's central rows no longer hide another account's per-asset rows. Used by the monthly report, lead quality spend, customer budget pacing (`CustomerCommercialSummary`) and the cross-channel advisor's channel spend (which previously did not de-duplicate at all).
- **Money is never added across currencies.** `currencies()` / `perAccount()` on the scope. Monthly report: per-account split when a channel has more than one account, currency note, money KPIs empty with a note when currencies are mixed. Budget pacing: state `mixed_currency`, per-account list on the customer card and Portföy sağlığı. Lead quality: spend null when mixed. Cross-channel: search-term costs only from accounts in the first account's currency; budget-shift rule skipped when mixed. Data consistency: new `mixed_currency` issue per brand (Komuta merkezi topic "Reklam hesapları farklı para biriminde").
- **Portföy sağlığı** channel cells cover every account of the channel ("2 hesap · …", worst state, per-account notes) instead of the first bound asset.
- **Hesap ekle (brand page › Dijital varlıklar) and Komuta merkezi coverage (`BrandAccountCandidates`).** Unbound accounts in the brand's MCC / Meta Business / Business Profile account, or whose name matches the brand, are listed with one-click "Bağla" (new asset, Admin, ownership guard re-checked, only listed accounts accepted) and "Önerilenlerin hepsini bağla". "Önerilen" = name match or a container used only by this brand; an agency MCC shared by several customers is listed but never suggested. Suggested ones become one coverage item per brand ("X: N reklam hesabı bağlanmamış", topic `coverage:brand-unbound`).
- **Kurulum durumu (`BrandSetupStatus`, brand overview).** Website / Google Ads / Meta Ads steps from integration to daily use, each done / next / waiting / optional with a one-click action (Otomatik kur, Google / Meta bağlan, Business seç, Hesapları listele, Hesap ekle, Taramayı başlat, SEO planını başlat, Şimdi çek, İncele). Ad channels without accounts or candidates are "Kullanılmıyor" and not counted. The old checklist keeps only brand items (services, matching, areas, competitors).
- **Onboarding glitches fixed.** OAuth success starts account discovery immediately (was: "go find Kaynakları keşfet", a button that does not exist). Selecting a Meta Business lists its ad accounts at once. Google Hesaplar tab lists every account (was cut at 50) with its MCC; bindings list up to 1000 (was 50) on Google and Meta; "Varlığı aç" opens the bound asset. Veri kaynakları no longer offers MCC managers and queues the first SEO plan when Search Console is bound to a website without one. Binding messages say the first collection starts automatically (was "Veri çekimi başlatılmadı", untrue since resource automation). Turkish discovery / crawl messages; Otomatik kur result links back to Kurulum durumu.
- Operator guide: `docs/guides/MARKA_KURULUM_REHBERI.md`.
- **State:** CODED + PHPUnit (SQLite, `MultiAccountBrandTest`, `DataConsistencyCheckerTest`). **No live UAT.** Not done: Meta ad accounts outside any Business (`me/adaccounts`) are still not listed by the canonical discovery; Google Ads accounts reachable only through a nested sub-MCC are grouped under the top accessible manager.
## 2026-10-21 — Açık uyarılar: ne oldu, neden önemli, ne yapmalısın, nereden

- **Every system alert reads as four answers in plain Turkish** (`App\Services\Observability\OperationalAlertExplainer` → `App\Support\Operator\OperatorMessage`): Ne oldu (brand · account / asset, which data), Neden önemli, Ne yapmalısın, Nereden (link + one-click button). Built at read time from the rule key and `observed`, so rows written by older code (English titles, "3 failed CollectionRun(s) in the last 3600s") read the same way.
  - Aggregated alerts (`dataset_stale`, `collection_repeated_failure`, `collection_stuck`) store the affected accounts (`AlertSubjects`: brand, asset, account, source, datasets, last error category; 20 kept) and list up to 5 then "+N". Data is named in plain words (`DatasetLabels`: "Search Console günlük tıklamalar", "Google Ads arama terimleri").
  - Errors map to a Turkish problem + fix (`CollectionErrorExplainer`): auth → "Google bağlantısını yenileyin" + Yeniden bağlan button; permission → "Hesaba erişim yetkisi yok", names the connected Google user who must be granted access; quota → "kota gece sıfırlanınca yarın kendiliğinden devam eder"; rate limit, not found, timeout, provider error, software error (retrying does not help), cancelled, unbound, passive customer.
  - "Hesap güncellemesi durdu · <hesap>": says which account type and brand, that it stopped after 3 failures (and does not retry), the reason from the account's last dataset error, and has a real **Şimdi güncelle** button (bell, Komuta merkezi drawer, Sistem sağlığı). Query-import stops link to the Sorgu kütüphanesi.
  - Links go to the exact place: one asset → its Veri kaynakları page; several → Portföy sağlığı (only problems); credential → reconnect screen; provider / queue / worker alerts → Sistem sağlığı sections. Asset alerts in the Komuta merkezi open the asset page instead of the generic Uyarılar list; high / critical asset-alert pushes carry the asset URL.
- **One bell row per condition.** A condition that comes back reopens its one alert row (`occurrence_count`, `first_opened_at`) and updates its existing notification instead of emitting a new one; it becomes unread again only after `reopen_quiet_hours` (24). The bell shows "3. kez · ilk 24 Eyl", hides resolved conditions, collapses legacy duplicates and counts an alert once in the unread badge; reading one row reads its duplicates.
- **Komuta merkezi:** system items carry the explanation, the affected brand / asset, a `system:<rule>` topic (Hesap verisi güncel değil, Hesap güncellemesi durdu, Veri çekimleri başarısız oluyor, Google / Meta bağlantı izni, …) and a drawer block "Ne oldu / Neden önemli / Ne yapmalısın" with the one-click action.
- Also: bell / notification titles Turkish (was "New finding: …", "Task assigned: …"); Sistem sağlığı shows dataset names and run / auth states in Turkish; `resource-auto.collection_failed` / `reconnect` texts say what happens next; stale GA4 / Search Console asset alerts name the account and the last error with its fix; SEO finding tasks without a specific checklist get concrete steps (h1, alt, redirects, broken links, status, mixed content, viewport, speed, schema, hreflang, Open Graph; default no longer "Bulguyu incele").
- **State:** CODED + PHPUnit (SQLite, `OperatorAlertClarityTest` 9/9, updated `NotificationBellPresentationTest`). **No live UAT;** texts reviewed in tests only. Not deployed.
- **Not done:** Uyarılar (asset alerts) page rows still show title + message (no separate why / action fields); advisor / SEO task titles were spot-checked, not rewritten; Activity feed event names are still English.
## 2026-10-21 — Sunucu hataları (500) taraması ve Otomatik kur kaydetme hatası

- **Otomatik kur onayı artık 500 vermez.** Kök neden: AI'ın önerdiği `business_model` (160 karaktere kadar) `brand_intelligence_contexts.business_model` varchar(64) kolonuna yazılıyordu; PostgreSQL reddediyor ve bu adım try/catch dışında olduğu için tüm onay isteği 500 dönüyordu.
  - `BrandSetupProposal::itemRows()` / `serviceRows()` saklanan öneriyi normalize eder (eksik anahtar, null, dizi olmayan değer, uzun ad, bozuk anahtar kelime).
  - `BrandSetupApplier`: her adım kendi başına; hata "yapılamadı" satırı olur (Türkçe neden; veritabanı hatası ham gösterilmez, günlüğe yazılır). Değerler kolon boyuna kesilir, aynı hizmet iki kez uygulanmaz, katalogda olmayan sektör ve geçersiz web sitesi adresi raporlanır, öneri bir kez uygulanır (çift tık / ikinci sekme), sonuçlar her durumda kaydedilir. Hedef kitle operatör formunun biçiminde (`{name, note}`) yazılır.
  - Sayfa: "Kısmen uygulandı: X uygulandı, Y yapılamadı" / "Hiçbir işlem uygulanamadı" mesajı.
- **Operatör ekranları taraması (`tests/Feature/Smoke/OperatorRouteSmokeTest.php`).** Gerçekçi portföy (aktif/pasif/arşiv müşteri, her varlık tipi bağlı/bağsız, markasız site, toplanmış veri, Danışman, SEO görevleri, uyarılar, içerik takvimi, fatura, lead) üzerinde: her operatör sayfası ve sekmesi, her model parametreli rota çöp id'lerle, her sayfa çöp / dizi sorgu parametreleriyle, her Livewire sayfasının her eylemi eksik/boş id ve uzun metinle. PostgreSQL'in reddedeceği sorgular (id kolonunda sayı olmayan değer, varchar'dan uzun metin — `ColumnLengthGuard` migration'lardan okur) SQLite'ta da yakalanır.
- **Bulunan ve düzeltilen hatalar:** çok büyük id'li rotalar (`/assets/9999…/sources` vb. TypeError) → rota id'leri `[0-9]{1,18}` (404); özel dönem bağlantısında çözülemeyen tarih (`?period=custom&from=…`) → varsayılan 28 gün; silinmiş kayda tıklanan eylemler (ModelNotFound → 404 penceresi) → Türkçe bildirim (`LivewireActionErrors`, düzen sayfasında bildirim kutusu); PostgreSQL'de sayısal olmayan id ile okuma → 404 / bildirim; Ajans taahhüt işaretleme (FK hatası), Beyin öneri türü, Meta işletme seçimi, arama profili sahibi (FK) ve alan uzunlukları, İş bağlamı formu uzunlukları (iş modeli 64, satırlar 255), AI kontrol paneli rota anahtarı; kimlik alanları (`brand`, `assetId`, `prospectId`, `profileId`, `connector`, `provider`, `bindingResourceId`) `#[Locked]`.
- **State:** CODED + PHPUnit (SQLite). PostgreSQL bu oturumda erişilemedi; PG'ye özgü sorunlar `ColumnLengthGuard` ve sayısal olmayan id dedektörüyle SQLite'ta kontrol edildi. **No live UAT.**

## 2026-10-20 — Varlık sahipliği ve yetki devri

- **One ownership rule (`App\Services\Ownership\OwnershipGuard`).** An external account (Google / Meta resource) or a digital asset belongs to one customer at a time.
  - `forResource()` / `forResourceInBrand()`: the account is actively bound to another asset. Another customer = yetki devri; same customer, other asset = move (`sameCustomer`), also confirmed.
  - `forAssetMove()`: the asset belongs to another customer's brand. A brandless asset has no owner.
  - `existingWebsite()`: one website asset per domain (www. / scheme ignored). Enforced on every save of a website asset (`DigitalAsset` saving guard) and on the asset edit form: another brand / customer → "Bu adres zaten X müşterisinin Y varlığında kayıtlı." plus the move / merge suggestion; same brand → "Bu adres bu markada zaten kayıtlı".
- **Transfer = explicit Admin confirmation, recorded.** `OwnershipTransferService` (`transferResource`, `moveAsset`) closes the old binding with `closed_reason = transferred` (kept disabled for history) and writes `ownership_transfers` (from / to customer, brand, asset, user, note, name snapshot).
  - The Google / Meta binding services refuse a foreign-owned account unless the plan carries `transferConfirmed`. The error names the owner: "Bu hesap (…) şu an X müşterisinin Y varlığına bağlı. Devretmek için onaylayın."
  - **Mapping follows the owner.** Another customer: the account's sector / services / query intake are cleared (collection on/off, interval, hour kept), mapping revision + 1, collection due now, pending "Hesap eşleme" proposals stale; Hizmet Beyni proposes a new mapping for the new brand. Same customer: mapping kept, pending proposals re-scoped to the new brand. Logged in `snapshot.mapping` and shown in "Devir geçmişi".
- **Manual flows ask inline** (panel: from → to, consequences, "Yetki devrini onaylıyorum", "Devret"; non-admins see the error only):
  - Veri kaynakları: accounts bound elsewhere are listed under "Başka varlığa bağlı" with their owner; picking one opens the panel. "Devir geçmişi" lists the asset's transfers.
  - Google / Meta integration bind modals.
  - Asset edit: Customer → Brand picker. Same customer's brand moves directly; another customer's brand needs the confirmation.
  - Asset create: a website domain that already exists is not duplicated. Brandless or same customer: "Mevcut siteyi bu markaya taşı". Another customer: yetki devri panel.
  - Entegrasyonlar › Web sitesi: "Markaya ata" on unassigned websites (customer → brand, no confirmation).
- **Automatic flows never transfer.** Otomatik kur and Toplu ekle (`BrandSetupApplier`) skip accounts bound elsewhere and websites whose domain belongs to another brand, and report "N hesap başka bir varlığa bağlı olduğu için atlandı". Prospect conversion does not duplicate an existing website and reports it on the prospect page ("Web sitesi (domain) zaten X müşterisinin Y markasında kayıtlı; yeni markaya eklenmedi…" with a link to the asset) and in the conversion activity. Brain "Hesap eşleme" maps sector / services only and binds nothing.
- Binding service messages are Turkish, including Meta eligibility (`MetaBindingEligibilityPolicy`) and `BindingScopeGuard` errors.
- **State:** CODED + PHPUnit (SQLite, `OwnershipTransferTest`, `OwnershipFollowUpTest` incl. the Meta bind-modal transfer). **No live UAT.**

## 2026-10-19 — Hizmet kapsamı: markasız varlık ve pasif müşteri için iş, uyarı ve harcama yok

- **One rule (`App\Support\ServiceScope`).** An asset is served only when `DigitalAsset::operational()` holds (active asset, attached to a brand, customer active); a brand only when `Brand::operational()` holds (customer active). Id lists are memoised per request / job and reset on every customer, brand or asset save.
- **Background, paid and AI work is gated at selection time and re-checked at handle time.**
  - Collection: `StartCollectionService` refuses non-operational assets; `ExecuteDatasetRunJob` cancels queued datasets of an asset that became passive; `DueCollectionQueryService` never lists their bindings; recurring collection / intelligence / report-delivery schedules produce no occurrence. Resource automation keeps its `customer_passive` / `unbound` gate.
  - Async operations (`AsyncOperationService::queue`): no diagnosis, crawl, SEO intelligence (DataForSEO), public discovery or competitor page collection; queued runs end as "Hizmet kapsamı dışında".
  - DataForSEO: `DataForSeoTaskQueue::post` posts nothing for a passive brand; map grid, reviews, backlinks, area SERP, competitor compare / watch and search-demand SERP enrichment refuse or skip.
  - AI: SEO / advisor plans, advisor copy drafts, GBP review replies, site-fix AI, on-click insights, monthly report commentary, AI visibility, brand setup, search-demand AI analyses and Brain proposals about assets refuse or skip.
  - Live verification checks only resources bound to operational assets (token checks always run); outcome measurement, quality-score history, content-calendar publishing and backlink checks skip them.
- **Nothing is shown for them.** Komuta merkezi (every reader, extra source, dashboard `top()` / `summary()`), Uyarılar, notification bell, advisor weekly digest, SEO / Danışman portfolio panels, İçerik takvimi, Ajans karnesi, Hizmet Beyni öneri / onay lists, Uyum, rakip / sorgu brand pickers. Items are hidden, not deleted, and come back on reactivation. A passive brand stays reachable by direct link.
- **Exceptions.** Agency-level items (system, integration token, agency leads) stay. An overdue invoice of a passive customer stays in the Komuta merkezi; drafts, follow-ups and commitments of passive customers raise no reminder. Invoices stay listed in Ajans işletmesi.
- **Reactivation** from any screen makes paused collection due immediately (model listener); the activity tiers backfill the gap.
- **State:** CODED + PHPUnit (SQLite, `ServiceScopeGateTest`, `PassiveCustomerGateTest`). **No live UAT.**

## 2026-10-18 — Tek veri durumu, etkinliğe göre veri çekimi, konu → varlık gelen kutusu

- **One data-status language (`DataStatusReader`).** Every asset page (website, GA4, Search Console, Google Ads, Meta, Business Profile) and Portföy sağlığı read one reader and show one "Veri durumu" strip.
  - States: Bağlı değil / İlk veri yükleniyor / Güncel / Gecikmiş · N gün / Erişim sorunu / Pasif.
  - The last data date comes from the fact tables, never from legacy Evidence.
  - The website overview KPIs read Data Pool totals. The false "henüz GA4 / Search Console verisi yok" banner is gone.
  - The legacy English Finding/Recommendation blocks on overviews were replaced by "Açık işler", which links to the Komuta merkezi `?asset=`.
- **Activity-aware collection.** Each account has a tier computed from its stored facts: Ads spend, GA4 sessions or Search Console clicks.
  - **active:** activity in the last 7 days. Collected daily in full.
  - **idle:** no activity for 7–30 days. One light pass per week.
  - **dormant:** no activity for 30+ days, or operator-paused. One cheap 7-day check per week.
  - When activity resumes the account returns to active immediately and the gap is backfilled.
  - Campaign / ad / creative structure is re-collected only when Google Ads `change_status` or Meta `updated_time` reports a change (weekly safety net).
  - Initial load is 13 months. Daily re-fetch: 3 days (GA4, Google Ads, Meta), 4 days (Search Console); Google Ads also gets a 30-day restatement at most weekly.
  - "Duraklatıldı (müşteri kararı)" toggle on Google Ads / Meta asset pages (and in the Komuta merkezi budget items). It clears itself when spend reappears.
  - Savings are shown on Sistem sağlığı (`collection_activity`).
- **Komuta merkezi as a topic → assets inbox.**
  - Layout: topics on the left under Acil / Bu hafta / Fırsatlar / Uzun süredir devam eden; on the right the affected assets with bulk actions and a detail drawer.
  - Filters: area (Reklam / SEO / Site / Müşteri & ajans / Sistem), brand and `?asset=`.
  - Danışman and SEO görevleri open the same inbox pre-filtered. The old full screens are at `/ads-advisor/detayli` and `/seo-tasks/detayli`.
  - **Alert aging:** an unchanged item moves to "Uzun süredir devam eden" after 10 days (`config/moxdop-command-center.php`). Critical topics never age. An item resurfaces when it changes or comes back.
  - Budget and spend items of dormant or paused accounts are hidden.
- **State:** CODED + PHPUnit (SQLite). The new tests were not run on PostgreSQL (server not reachable in this session). **No live UAT.**

## 2026-10-17 — Güven katmanı: CI, deploy kapısı, canlı doğrulama, veri tutarlılığı, sade menü, değer döngüsü

- **CI on push** (`.github/workflows/moxdop-ci.yml`): the full PHPUnit suite runs on every push to `chatgpt/search-demand-foundation`.
  - The SQLite job is the gate: 2236+ tests, 0 failures.
  - A PostgreSQL 16 job also runs but does not block (`continue-on-error`). It went from 171 failures to 37 known PostgreSQL-only failures:
    - rollback tests after the one-way compact-fact migration;
    - tests that leak rows because they don't use RefreshDatabase;
    - backup tests written for SQLite;
    - a few not yet investigated.
- **PostgreSQL bugs fixed in the app:**
  - GA4 pool reads (unquoted camelCase columns);
  - stored discovery inventory reading a column that doesn't exist;
  - report snapshot `SET TRANSACTION` issued inside a transaction.
- **Deploy gate:** `moxdop:preflight` stops `deploy/staging/deploy.sh` before maintenance mode and migrations. It checks env keys, the DB, Redis, and that config, routes and views compile.
  - `storage/app/release.json` records the deployed SHA; Sistem sağlığı shows it and grouped errors carry it.
  - The script hands `storage/` and `bootstrap/cache/` back to the web user (`MOXDOP_WEB_USER`, default www-data) before `artisan up` and on every exit. Root-owned compiled views made every page answer 500 on 2026-10-03 (`touch(): Utime failed`). Artisan commands run after the script go through `sudo -u www-data`.
- **Observability** (Sistem sağlığı):
  - Horizon queue wait alarm `queue_wait_high` (300 / 900 / 1800 s; redis only).
  - Grouped application errors (`app_error_groups`).
  - Live verification results.
- **Turkish by default:** `APP_LOCALE` defaults to `tr`; tests pin `en`.
- **Live verification** (`moxdop:verify:live`, daily 06:20): one read-only call per integration and bound account (Google token plus GA4 / GSC / Ads / Business Profile, Meta, DataForSEO free endpoint, WordPress status).
  - Results are kept in `live_checks` for 30 days. There is a "Şimdi doğrula" button, and failures appear in the Komuta merkezi as "Canlı doğrulama".
- **Data consistency** (`moxdop:verify:data`, daily 07:25) flags four things:
  - missing days;
  - Google Ads spend while GA4 shows no google / cpc sessions (tagging broken);
  - Ads vs GA4 paid key-event mismatch above 50%;
  - account currency differing from the invoice currency.
  - Findings appear in the Komuta merkezi as "Veri şüpheli" and close by themselves.
  - Known limit: a campaign paused for a few days looks like a missing day.
- **Collection gaps closed:**
  - Website link-edge / crawl-issue / html / content-stats / cms datasets now have freshness and contract rows.
  - The Meta planner plans campaign, ad set and creative snapshots as separate runs.
  - Collection dependencies link to every run of a family.
- **Simpler menu (W7):** the sidebar went from 42 entries to 18. Related screens are tabs on their parent entry (`OperatorMenu::sectionTabs`).
- **Google Ads Editor export:** from the advisor's bulk bar. UTF-16 TSV covering negative keywords (phrase / exact), exact keywords, budget +20% and campaign pause.
  - Items that cannot be mapped are listed as "Elle yapılacak". Exported items show "dışa aktarıldı".
  - MoxDOP itself still makes no campaign, budget or status write.
  - **One real Editor import is needed** to confirm the column names.
- **Lead outcomes (ADR-074):** `/brands/{brand}/leads` takes a file import or manual entry and marks each lead's outcome.
  - No contact PII is stored.
  - A "Lead kalitesi" block shows qualified rate and cost per qualified lead.
  - The Komuta merkezi lists unmarked leads once per brand, and the monthly report gets a lead quality section.
- **Client approval link (ADR-075):** İçerik takvimi › "Müşteri onayına gönder" creates a signed 14-day `/onay/{id}` page.
  - The client gives one answer; it never publishes anything. The answer appears in the Komuta merkezi.
- **AI kalitesi** (`/settings/ai-quality`, Admin): produced / accepted / edited / rejected and cost for each AI source and prompt version. A source is flagged below 30% acceptance on 20 or more decided items.
- **System map:** `php artisan moxdop:system-map` writes `docs/SYSTEM_MAP.md` from the code. **Live UAT checklist:** `docs/qa/LIVE_UAT_CHECKLIST.md`.
- **State:** CODED + PHPUnit (SQLite; the new tests also pass on PostgreSQL). **No live UAT** — work through the checklist on staging.

## 2026-10-16 — Tek kişilik portföy işletimi: Komuta merkezi, Portföy sağlığı, otomatik keşif, rapor kuyruğu, İşletme Profili yazması (ADR-073), ajans işletmesi, sadeleştirme

- **Komuta merkezi** (`/command-center`, İş menüsü, Ana sayfadan hemen sonra)
  - One list for every source: alerts, advisor, SEO tasks, site fixes (one row per site), Beyin proposals, compliance, leads, system alerts, approvals, coverage gaps, content calendar, follow-ups, invoices, commitments and legacy tasks.
  - Items are ranked by impact: severity base, plus money and clicks, or the source's own priority score.
  - Duplicates are removed: an alert or site fix that covers an advisor or SEO rule hides that rule's row.
  - Actions (done / snooze / dismiss) run on the item's own source. Sources without their own snooze use `inbox_snoozes`.
  - Applying or drafting a new-page site fix closes its SEO task.
  - The dashboard's "Önce bunlar" shows the top 8 (at most 2 per brand).
- **Portföy sağlığı** (`/portfolio/health`)
  - A brand × channel matrix: ok / warn / bad / missing, with stale data after 4 days.
  - Also shows coverage gaps, unbound accounts, open work per brand, and a budget pacing table.
- **Otomatik keşif ve kapsama**
  - `moxdop:integrations:discover` runs every day at 05:10 (Istanbul).
  - The Command Center lists reconnect needed (one click to the authorize route), lost access, unbound accounts and brands without Search Console.
  - An Otomatik kur run that stays pending for more than 15 minutes is marked failed, and the button re-enables.
- **Raporlar**
  - Rapor kuyruğu (`/reports/queue`): prepare missing monthly reports, publish selected, and send selected in the background. A send error is shown on the row.
  - Ajans karnesi (`/reports/scorecard`): what was achieved this month across all brands.
- **İşletme Profili yazması (ADR-073)**
  - Review reply and local post, each Admin-approved and undoable.
  - İçerik takvimi (`/content`): planned posts. Approved posts whose time has come are published every 10 minutes.
  - Google Ads campaign pause and budget changes are **not** built; a safety check blocked this write.
- **Ajans işletmesi** (`/agency`)
  - Tabs: Kârlılık (fee against logged time × hourly cost), Tahsilat (`agency_invoices`, monthly drafts on day 1), Taahhütler (monthly deliverables and marks), Zaman, İletişim (customer interactions and follow-ups).
  - Customer detail shows interactions and open invoices.
- **Sadeleştirme ve sağlamlık**
  - The dashboard now has today, alerts and "Önce bunlar" only; the mode toggle and legacy blocks are gone.
  - Page titles and the Search Console / Analytics / task list labels are Turkish.
  - Slower polling on integration pages. The advisor board loads only the latest plan per asset.
  - Long AI and analysis jobs run on the `heavy` Horizon queue (redis only).
  - The duplicate report delivery schedule was removed.
  - An account-wide DataForSEO monthly cap applies (`DATAFORSEO_GLOBAL_MONTHLY_USD`, default 100). It is shown on Maliyetler.
- **State:** CODED + PHPUnit.
  - Tests: `Work/CommandCenterTest`, `Work/CoverageSourceTest`, `Portfolio/PortfolioHealthTest`, `Reports/ReportQueueAndScorecardTest`, `ExternalWrites/GbpWritesTest`, `Agency/AgencyOperationsTest`, `DataForSeoCostGuardTest` (global cap).
  - **No live UAT.** The Business Profile writes need a real location and Admin approval on staging.
- **Test suite repaired:** the full PHPUnit suite is green on SQLite (2207 tests, 0 failures; PostgreSQL-only tests skip).
  - Bug fixes found while repairing it:
    - GA4 `purchaseRevenue` is accepted in property-daily requests.
    - The legacy Meta metadata collector no longer uses the blocked `id IN` filter.
    - Migration rollbacks work on SQLite, and the brandless-website migration works on a populated SQLite database.
  - Every feature test blocks stray HTTP calls.
  - Known gaps recorded by tests:
    - The website link-edge / crawl-issue datasets have no freshness policy or data contract.
    - The planner creates only the Meta campaign snapshot (not adset / creative).
    - Fixed: the GA4 / GSC connector header now shows "Bağlantı kapalı" when the Google integration is not active.

## 2026-10-15 — Veri merkezi, yalın ve kaldığı yerden devam eden tarama, markasız site, Otomatik kur v4

- **Veri merkezi** (`/data-center`, Sistem menüsü)
  - Per source (account or website) it shows data sets, rows, date range, last collection, which brand the source feeds, and whether the source is unbound (collection stopped).
  - Admin can pick data sets and delete them in the background. Queries, search terms and keywords are protected and cannot be deleted.
  - Compact PostgreSQL facts are deleted from their fact tables. Raw payload files are deleted from disk.
- **Website crawl**
  - Media, feed, tag/author/pagination, embed, page-builder template, cart and tracking-parameter URLs never enter the crawl.
  - Sitemap indexes skip media, tag and author sitemaps.
  - The WordPress connector no longer stores builder templates or non-image media. Image alt text is kept for the alt-text fix.
  - A full crawl skips pages unchanged since their last fetch (WordPress `modified_at` or sitemap `lastmod`). Unknown dates re-check weekly, and every page is re-checked at least monthly.
  - Link counts use each page's own latest fetch.
- **Sites before brands**
  - Integrations › Website › "+ Web sitesi ekle" creates a website with no brand; the host cannot be added twice.
  - Marka ekle lists unassigned sites. Picking one, or typing its host, moves it to the brand and opens Otomatik kur.
- **Otomatik kur v4**
  - Published WordPress page titles, with their parent pages, are the strongest service signal. Up to 20 services are returned.
  - Without AI, page titles that are not generic pages are proposed.
  - İş bağlamı is proposed, and approval fills only its empty fields.
- **Alerts**
  - Account-level coverage from central collection now counts for bound assets, so fresh accounts are no longer reported as "güncellenemiyor".
  - The failed-collection alert text is Turkish.
- **Cleanup:** the legacy clusters, visibility map, SERP enrichment and change tracking pages were removed; their URLs redirect.
- **State:** CODED + PHPUnit.
  - Tests: `DataCenter/DataCenterTest`, `Portfolio/UnassignedWebsiteTest`, `Collection/WebsiteProductionCollectorTest` (crawl filter plus unchanged skip), `BrandSetup/BrandSetupAssistantTest` (WordPress pages plus İş bağlamı) and `DataPool/DataFreshnessIncrementalCollectionTest` (account-level coverage).
  - Also run on PostgreSQL: data center and unassigned site.
  - **No live UAT.**

## 2026-10-14 — Hizmet Beyni Faz 6: uyum freni, taslak istemlerinde sektör kuralları, yasal kapı (ADR-072)

**State:** CODED + PHPUnit. Test: `Brain/BrainBrakeTest` 3/3. The legal gate is off by default and waits for a legal opinion.
- **`ComplianceBrake`:** every Brain recommendation passes through it before it reaches the operator.
  - **Text rules:** the brand's sector-pack rules check the title and detail; high and medium severity hits block the recommendation.
  - **Types never recommended to a sector:**
    - health: showing prices, price / urgency / social-proof Meta angles
    - finance: the urgency angle
    - food supplement: the result / benefit angle
  - **Legal gate** (`moxdop-brain.legal.health_paid_ads_gate`, default 0): when on, health brands get paid-ads growth recommendations (new ad group, new Meta angle) only if an operator recorded eligibility. Recorded under Ayarlar → Sektör paketleri; bases: first month (valid 1 month), health tourism abroad, legal opinion.
  - A blocked recommendation stays with status **"Frenlendi"** and its reason; it is not silently lost.
- **Google Ads and Meta AI copy drafts** now carry the brand's sector rules in `compliance_rules` (label, message, forbidden expressions), and the agents are told they are binding. Previously the rules only checked the finished draft; that check stays.
- Sector packs screen: new "Hizmet Beyni freni" section (blocked types and legal gate status) plus the eligibility list for health brands.

## 2026-10-14 — Hizmet Beyni Faz 5: yöntem motoru (hipotez → kanıtlanmış), fark-içinde-fark ölçüm, boşluk önerileri

**State:** CODED + PHPUnit. Test: `Brain/BrainMethodsTest` 2/2. Real portfolio: methods appear only once the thresholds are met.
- **`MethodEngine`:** inside a cohort (service × page type), compares the top pages (score ≥ 66th percentile) with the bottom pages (≤ 33rd).
  - A feature becomes a **hypothesis** when all of these hold:
    - at least `min_pages` pages (30) and `min_brands` brands (8) are in the cohort;
    - at least 60% of the top pages have the feature;
    - top minus bottom is at least 0.3;
    - at least 3 different successful brands have it.
  - The bar for a numeric feature is the median of the top pages.
  - Only counts are stored, never another brand's name (ADR-066).
- **`OutcomeMeasurer`:** a recommendation marked "Yapıldı" is measured at 28 and 56 days against **controls**: the same topic on other brands' sites that did not get the recommendation (difference in differences).
  - Website metric: the cluster's Search Console clicks.
  - Google Ads metric: the ad group's conversions, against the account's other ad groups.
- **`MethodValidator`:**
  - With at least `min_treated` (5) measured cases: mean effect > 0 and a positive share ≥ 60% → **validated**; mean effect ≤ 0 → **retired**.
  - Retired methods are not recommended again.
- **`GapRecommender` (website):** raises
  - pages that do not exist (main / landing / support);
  - merging pages that split a topic;
  - method gaps (basis: observed or proven);
  - sub-questions the AI checklist found unanswered.
- **`MetaAngleRecommender`:** a message angle becomes a hypothesis when at least 3 brands ran it and its median cost per result is at least 20% lower than the service median. Brands running the service without that angle get "bu açıyı test edin".
- Thresholds are in `config/moxdop-brain.php` and can be edited under Ayarlar → Yöntem Kütüphanesi ("Hizmet Beyni").
- **Yöntemler** screen (`/brain/methods`): status, evidence and measured effect. The operator can close or reopen a method; closing also closes its open recommendations.
- Weekly order: success → outcome measurement → method validation → method discovery → recommendations.

## 2026-10-14 — Hizmet Beyni Faz 4: sayfa özellikleri, normalize başarı puanı, kohortlar

**State:** CODED + PHPUnit. Test: `Brain/BrainSuccessTest` 2/2. Not tested with real stored HTML/GA4 or the AI checklist (fixtures only).
- **Başarı (`brain_success_snapshots`, monthly, weekly refresh):** covers each brand's page for each cluster over the last 90 days.
  - Visibility is the share of search demand when volumes are known; otherwise it is impressions.
  - **Click-through against expected:** CTR divided by the typical CTR at that position.
  - Clicks and conversion rate use **empirical Bayes shrinkage** toward the cohort average: 200 impressions for CTR and 50 sessions for conversion rate. Thin-data pages are pulled toward the average.
  - Visits and conversions come from the GA4 landing page.
- **Score (0–100):** the average percentile on those measures within the cohort. The cohort is **service × page type × market tier** (metro / regional / unknown); a cohort smaller than 3 falls back to service × page type.
- **Sayfa özellikleri (`brain_page_features`), measured from the stored crawl HTML:**
  - word count, H2 count, FAQ, schema, medical schema, price and place mentions, internal links, images;
  - **cluster coverage:** the share of the cluster's queries whose words appear on the page.
- **AI checklist:** "Sayfa özellikleri (AI okur)", on click. It returns 11 fixed yes/no checks (answer first, question headings, process, duration/recovery, risks, candidacy, expert shown, date, sources, next step, location) and the missing subtopics.
  - It only reads again when the content hash changes.
  - It is measurement, so nothing needs approval.
- The Hizmet haritası has a new "Sayfalar ve başarı" table. The cluster table shows a score badge per brand.

## 2026-10-14 — Hizmet Beyni Faz 3: kalıcı hizmet zinciri (küme ↔ reklam grubu ↔ sayfa, Meta reklamı ↔ hizmet), Beyin önerileri

**State:** CODED + PHPUnit. Test: `Brain/BrainChainTest` 2/2. Real Ads/Meta account UAT: not done.
- **Google Ads (`brain_ad_group_clusters`, weekly):** each ad group's cluster share is computed from its keywords and from the search terms it actually matched, weighted by impressions. Also stored: final URL, the page that owns the cluster, whether they match, weighted Quality Score, and cost and conversions.
- Recommendations to `brain_recommendations` (Quality Score rule: one cluster → one ad group → one page):
  - **split ad group:** two or more clusters each have ≥25% of the ad group's impressions.
  - **final URL:** the ad goes to a page other than the one that owns its cluster.
  - **paying for another topic:** the ad group pays for search terms of a cluster another ad group owns; negative keywords route them.
  - **missing cluster:** a buying cluster of an advertised service has no ad group.
- **Meta (`brain_meta_ads`):**
  - Each ad's spend, results and format (video / image / carousel) are stored.
  - An ad is filed under a service directly (rule) when a service name or matching expression appears exactly once in the ad name, ad set name, campaign name or creative text.
  - The remaining ads, and every ad's message angle (price, trust, result, problem, social proof, education, urgency), are prepared by AI (`brain.creative_classifier`) as a proposal. Approval is required.
  - An approved choice is not overwritten by a later rule run.
- `RecommendationWriter`:
  - A recommendation that is no longer detected closes as "resolved".
  - A dismissed one is not raised again.
  - One marked done is raised again only if it is still detected after 28 days.
- **Beyin önerileri** screen (`/brain/recommendations`): filters; bulk "Yapıldı" and "Kapat"; evidence detail. Recommendations are ordered by basis (proven → observed → rule), then by impact.
- **Hizmet haritası** now shows Google Ads ad groups (cluster fit, landing page, Quality Score) and, for the service, Meta ads broken down by message angle (spend, results, cost per result).

## 2026-10-14 — Hizmet Beyni Faz 2: sayfa boyutunda kümeler, küme → sayfa, yamyamlaşma

**State:** CODED + PHPUnit. Test: `Brain/BrainClusteringTest` 3/3. Real portfolio UAT: not done.
- `ClusterBuilder` splits a service's queries into page-sized clusters, where one cluster is what one page can answer. Place names are removed first.
- Similarity combines the evidence that exists:
  - SERP overlap from stored DataForSEO top-10 results: 4 or more shared URLs means the same page.
  - Embeddings.
  - Portfolio Search Console: our own site ranks the same URL for both queries.
  - Word stems, which always work, including without AI.
- Method:
  - Leader clustering by demand, threshold 0.5.
  - Existing clusters (manual or approved earlier) are kept as seeds; only queries without a cluster are placed.
  - A merge pass joins new clusters that turn out to be one topic.
- Page type: one **main page**, **landing** (a separate sales page), **support** (a separate article) or **FAQ** (small questions that go on the main page).
- Intent uses Turkish rules. The question particle "mı" is informational; the "X mı Y mı" pattern is comparison.
- AI (`brain.cluster_labels`) only names the clusters and cannot open a second main page.
- **Küme → sayfa:** the URL that gets the most of the cluster's Search Console impressions. A proposal is made only when that URL has at least 40% of the impressions and the cluster has at least 20 impressions. No AI.
- **Yamyamlaşma:** two of our own URLs split one cluster.
  - Flagged when the second URL gets at least 25% of the impressions, both average positions are 20 or better, and the cluster has at least 50 impressions.
  - Stored in `brain_cannibalizations`. The operator can mark a case "Bilinçli" and it is not raised again.
- Screen: **Hizmet Beyni → Hizmet haritası** (`/brain/services`). It shows each cluster's type and intent, each brand's page per cluster (or "sayfa yok"), and pages that split a topic.
- Weekly `moxdop:brain:refresh` (stored data, no AI).

## 2026-10-14 — Hizmet Beyni Faz 1: onay kuyruğu, hesap eşleme, sorgu ataması, eşleme ifadesi önerisi, toplu işlem

**State:** CODED + PHPUnit. Tests: `Brain/BrainProposalsTest` 4/4, `Brain/BulkReviewTest` 1/1. Real AI/embedding UAT: not done (fakes only).
- `brain_proposals` holds one review queue for everything the system or AI prepares. Screen: **Hizmet Beyni → Onay kuyruğu** (`/brain/proposals`).
  - The "AI ile hazırla" button starts a background job. Operators approve in bulk, including "güveni ≥ %X olanların hepsi". Rejected proposals are not offered again.
  - Nothing changes without approval.
- **Hesap eşleme:** AI reads each Google account's (Ads / Search Console / GBP) most frequent queries and proposes a sector and services.
  - **Confidence is computed by the system:** the share of query impressions that the chosen services' matching expressions cover. The model's own opinion is not used.
  - Approval goes through `ResourceAutomationService::save`, which turns on query intake.
  - The button is in Veri kaynakları → Sorgular.
- **Sorgu → hizmet** works in 4 tiers:
  1. A single hit on a matching expression is filed directly.
  2. Embeddings (`brain_embeddings` cache) compare the query with each service centroid; a clear winner is proposed with its margin as confidence.
  3. AI handles the unclear queries and also labels intent. When AI and the similarity model disagree, confidence is 0.45.
  4. The operator approves. Approved queries become examples for the service. When AI finds no service, the query is remembered and not asked again.
- **Eşleme ifadesi önerisi (no AI):** a phrase found in ≥3 approved queries of a service, with precision ≥ 0.85 and catching ≥2 queries the current expressions miss.
- **Danışman and SEO tasks:** bulk "Yapıldı" / "30 gün ertele" / "Atla".
- New AI routes: `brain.embeddings` (OpenAI text-embedding-3-small → Gemini), `brain.account_mapping` and `brain.query_classifier` (classification chain). Embedding cost is recorded in `ai_usage_records`.

## 2026-10-13 — Müşteri / Marka toplu seç + sil (Admin; veriler korunur, veri çekimi durur)

**State:** CODED + PHPUnit (SQLite ve PostgreSQL). Test: `PortfolioBulkDeleteTest` 4/4.
- Müşteriler ve Markalar listelerinde (Admin) satır seçme kutucukları, tümünü seç ve bir onay diyaloğuyla "Seçilenleri sil".
- `PortfolioDeletionService` silinen müşteriyi (markaları ve dijital varlıklarıyla) ya da markayı (dijital varlıklarıyla; müşteri kalır) **arşivler** (soft delete, `deleted_at`). Kayıtlar listelerden ve ekranlardan kalkar.
- **Toplanan veri silinmez.** Rapor, hedef, teklif ve toplanmış analitik satırları yerinde kalır.
- **Veri çekimi durur:** Silinen varlıkların aktif hesap bağlantıları `disabled` yapılır (`closed_reason = portföyden silindi`). Bağlı olmayan hesap toplanmaz (`ResourceAutomationService::portfolioGate`).
- **Veri çekimi devam eder:** Aynı hesap tekrar bir markanın varlığına bağlanınca çekim kendiliğinden sürer. Toplanan veri hesaba göre anahtarlandığı için geçmiş yeniden görünür.
- Her varlık tek transaction içinde işlenir. Başarısız olan "atlandı" olarak raporlanır.
- Yalnız Admin. Her işlem güvenlik denetim günlüğüne (SecuritySettingChanged) ad/id listesiyle yazılır.

## 2026-10-13 — Meta video kreatif kalitesi (hook / thruplay / completion)

**State:** CODED + PHPUnit. Test: `MetaVideoQualityTest` 1/1.
- Kreatif sekmesinde her video kreatifi için, hâlihazırda toplanan `meta_video_engagement_daily` verisinden: **hook oranı** (3 sn izleme ÷ gösterim), **thruplay oranı** (thruplay ÷ 3 sn izleme), **tamamlama** (%100 ÷ 3 sn izleme).
- 200+ 3-sn izlemesi olup hook < %15 olan kreatife **"zayıf hook"** uyarısı (açılışı yenile). Yeni API çağrısı yok; salt okunur.

## 2026-10-12 — Satış hattı sağlamlaştırma: Lead kutusu, Prospects, Niyet radarı, WhatsApp

**State:** CODED + PHPUnit (SQLite). Reviewed by four focused read-only passes; the fixes below are the confirmed bugs.
- Tests: `LeadInboxTest` 5/5, `ProspectConversionBatchBTest`, `FreeRadarMatcherTest`, `KvkkAndBackupTest`, `DataRetentionTest`, `WhatsAppContactLinkTest`.
- Known pre-existing failure unrelated to this change: `IntentRadarBatchBTest::test_operator_pages_require_auth_and_list_search_profiles`.

**Lead kutusu (agency lead inbox)**
- Fixed: a genuine same-day lead no longer merges into (and hides inside) a spam row; the phone lookup excludes spam rows.
- Fixed: a merge now backfills missing name/company/email/utm and sends the phone notification the UI promises (was silent).
- Fixed: a malformed e-mail with no phone is treated as contactless → spam (was a contactless "new" lead).
- Fixed: Meta Lead Ads redeliveries are idempotent via `external_id` (`meta_leadgen:<id>`); a status change can no longer move a `converted` lead backward and orphan its prospect link.
- Added: owner assignment + "Bana atananlar" filter; a first-response time badge ("X saattir bekliyor" / "X sa içinde yanıtlandı"); the Açık/Hepsi tab counters; converting a lead links to an open prospect with the same phone/e-mail instead of duplicating.

**Prospects**
- Fixed: converting a prospect to a customer now sets status = Won (pipeline stage kept in step with conversion).
- Fixed: the index no longer loads the whole prospect table twice per render (memoized).

**Niyet radarı (free intent radar)**
- Fixed: create-prospect-from-signal locks the signal row (no duplicate prospect under concurrent clicks).
- Fixed: signal dedupe preloads existing signals once per run instead of a query per page (N+1).
- Fixed: buyer-intent detector no longer trips on seller/review posts — "tavsiye"/"öneri" match only in question form; review phrases ("tavsiye ederim", "referanslarımız") are negative signals.

**WhatsApp**
- Fixed (data-corruption): KVKK retention wrote the plaintext redaction marker into the ENCRYPTED body column, which then threw on read and broke the inbox. It now writes the marker as ciphertext and flags rows with `redacted_at` (idempotent without comparing an encrypted column).
- Fixed: the follow-up form is prefilled from the selected conversation's prospect, so saving no longer nulls the untouched date/step; link + follow-up fields reset on conversation switch (no stale value saved to the wrong conversation).

**WhatsApp additions:** a 24-hour reply-window indicator (from the last inbound time) and a KVKK opt-out flag set when a contact sends a STOP/DUR-type message — both display-only (MoxDOP never sends). Still deferred: unread badge, intent-radar contact enrichment, a paid-DataForSEO spend cap (that adapter is unused/dead today).

## 2026-10-12 — Reklam bütçesi bitti uyarıları + Meta ülke/şehir sonuçları + AI "hizmet × bölge × kitle"

**State:** CODED + PHPUnit (SQLite and PostgreSQL).
- Tests: `AdBudgetWatchTest` 4/4, `MetaGeoResultsTest` 3/3, `AssetAlertScannerTest`, `AiInsightsTest`.
- Not deployed. Not tried against live Google Ads / Meta accounts: API answers are mocked in tests.

**Budget watch**
- `moxdop:ads:budget-watch` runs every 2 hours (`CheckAdBudgetJob` → `AdBudgetWatch`, read-only). The latest state goes to `ad_budget_status`, then the asset's alerts are rescanned at once.
- Google Ads reads: customer status, today's cost against each enabled campaign's daily budget, and the approved account budget (spend limit, amount served, end date).
- Meta reads: `account_status`, `spend_cap` / `amount_spent`, the prepaid balance (`funding_source_details`, best effort), today's spend, and ads that are `DISAPPROVED` / `WITH_ISSUES`.
- Alerts (`AssetAlertScanner::budgetAlerts`):
  - critical: `budget_account_blocked`, `budget_exhausted` (limit full, budget ended, or prepaid balance 0), and `budget_no_spend_today` (after 14:00 account time, when there is a spending baseline).
  - high: `budget_low` (less than 3 days of average spend left), `budget_campaign_capped` (daily budget used up before 20:00), and `ads_disapproved`.
- High and critical alerts send the existing push notification. A state older than 6 hours is ignored.
- Known limits:
  - Google Ads does not expose a card or prepaid balance. For those accounts only the zero-spend-today and account-status checks apply.
  - The Meta prepaid balance is parsed from the display string.

**Meta country + city results**
- Table `meta_geo_results_daily` holds rows per ad × day × country and per ad × day × region. Each row has spend, impressions and clicks, plus leads, purchases, purchase value and messages (canonical action aliases, counted once).
- Collection:
  - `moxdop:meta:geo-results` runs daily at 05:41 and collects the last 3 days (30 days on the first run).
  - "Veriyi getir" / "Yenile" on the Kitle & Dağıtım tab collects the last 90 days.
- A region gets its country only when the ad delivered in a single country that day. Otherwise it is shown under "Birden fazla ülke".
- On the Kitle & Dağıtım tab the new table lists countries; clicking a country opens its cities, each with cost per result.

**AI insight `meta.geo_results`** (small "✨ AI" button on the same table)
- Sends campaign / ad set / ad names × country × city with results for the last 90 days, plus ad set targeting (age, gender, interests, custom audiences, targeted cities).
- Answers "hizmet · şehir · kitle — sonuç, sonuç başı maliyet" with the tags İyi çalışıyor / Boşa harcıyor / Denenmeli.
- Runs on click only and is kept in the Üretim Arşivi.

## 2026-10-11 — Toplu ekle, menü ve bildirim düzeltmeleri

**State:** CODED + PHPUnit (`DiscoverAndGroupTest` 7/7, `NotificationBellPresentationTest` 2/2). Not deployed.
- **Toplu ekle:**
  - Instagram / Facebook / linktr.ee / Google Maps gibi paylaşılan adresler artık "web sitesi" sayılmıyor.
  - Bir reklam hesabı yalnız bağlı olduğu işletmenin adı markaya benziyorsa gruba öneriliyor ama işaretsiz geliyor, gerekçesiyle birlikte.
  - Marka adı temizleniyor: "- GA4", "Reklam Hesabı", URL ve sondaki tire atılıyor. "A | B" başlığında alan adına en yakın parça seçiliyor. Yalnız rakamdan oluşan hesap adları marka adı olmuyor.
  - Arama, filtre (web adresi olan / olmayan / mevcut markaya ait) ve 20'şer gösterim eklendi.
  - Etiket "Bu site zaten “X” markasında" oldu.
- **Menü:** Her maddenin kendi ikonu var; önceden 15 madde 5 ikonu paylaşıyordu.
- **Bildirimler:**
  - Sistem uyarıları Türkçe; aynı başlık iki kez yazılmıyor.
  - Altta uyarı özeti ve saat var; başlığa tıklayınca Uyarılar sayfası açılıyor.
  - "Prompt27" gibi iç adlar kaldırıldı.

## 2026-10-11 — Connector 1.4.1: tek tık eklenti güncelleme, siteden anında değişiklik (ADR-071)

**State:** CODED + PHPUnit (SQLite and PostgreSQL).
- Tests: `WordPressManagementTest` 6/6, `WordPressConnectorV1Test` 14/14, `SiteFixesTest` 7/7, `SiteChangePropagationTest` 4/4, `ExternalWritesTest`.
- Not deployed. Not tried on a real WordPress site: plugin 1.4.1 PHP was reviewed only (php -l clean), with no WordPress runtime test.

- **Tek tık eklenti güncelleme:** Entegrasyonlar › WordPress siteleri.
  - A newer version shows a "yeni: 1.4.x" badge, an "Eklentiyi güncelle" button and a "Tümünü güncelle" banner (Admin only).
  - The plugin downloads the ZIP from a 15-minute signed link, checks its SHA-256, installs over itself and stays active.
  - Only newer versions are installed, and only from the paired MoxDOP host.
  - Sites on 1.4.0 or older are shown "bir kez elle yükle"; later versions are one click.
- **Anında değişiklik:**
  - The plugin sends activity right after a save; the 5-minute cron is the fallback.
  - Reconciliation runs every minute: a small change batch starts ~1 minute after the event and does not wait behind full inventories.
- **Düzeltme sonrası doğrulama:**
  - Applied fixes trigger a targeted recrawl of those pages only.
  - Each applied item then shows "Sitede doğrulandı" or "Sitede hâlâ görünüyor" (a cache, the theme or another SEO plugin overriding it).
- **Arama motorları:**
  - The site sends IndexNow (Bing/Yandex) for changed published pages and fixed pages. It is on by default and off while search engines are discouraged.
  - Changed pages go first in Search Console URL inspection, with a daily inspection of pages changed 1–3 days ago (read-only).
- **Connector'ı olmayan siteler:** sitemap `lastmod` is checked hourly and only changed or new pages are crawled. The first check only records a baseline.
- **Operator steps:**
  1. Once per site: install the 1.4.1 ZIP by hand (Entegrasyonlar › WordPress › download).
  2. From then on, use "Eklentiyi güncelle" / "Tümünü güncelle".

## 2026-10-11 — Web sitesi düzeltmeleri: siteye onaylı yazma (ADR-070, Faz 1–3)

**State:** CODED + PHPUnit.
- `SiteFixesTest` passes 6/6 on SQLite and PostgreSQL:
  - rules find problems from stored data;
  - AI values are accepted, and a redirect target that is not a real page is refused;
  - Admin applies and undoes (a member gets 403);
  - page text goes to a draft copy first and goes live only after a second approval (dangerous HTML removed);
  - an internal-link anchor must already be in the page text;
  - plugin 1.4 is required.
- `ExternalWritesTest` and `WordPressManagementTest` still pass.
- Not deployed. Not tried on a real WordPress site: plugin 1.4.0 PHP code was reviewed only (php -l clean), with no WordPress runtime test.

- **Where:** Web sitesi › **Düzeltmeler** tab.
  1. "Sorunları bul": rules read the latest crawl and connector snapshot.
  2. "Değerleri AI ile öner" and "İç bağlantı öner".
  3. The operator reviews and edits each value (before → after).
  4. An Admin applies the selected fixes with "Seçilenleri siteye uygula".
  5. "Siteye yapılan değişiklikler" lists each write with a "Geri al" button.
- **Phase 1:** SEO title (empty, too long or short, duplicated), meta description (empty, too long or short), image alt text, LocalBusiness schema on the home page (from GBP facts only).
- **Phase 2:**
  - 301 redirect for 404/410 addresses (the target must be a published page);
  - accidental noindex;
  - canonical pointing elsewhere;
  - internal link (the anchor is already in the text).
- **Phase 3:**
  - A thin page (<250 words): AI writes a new version, it is sent as a WordPress **draft copy** (the live page is unchanged), and a second approval ("Yayına al") replaces the live page. WordPress keeps the old version and the change can be undone.
  - New page from an SEO brief: AI writes the full text and it is sent as a draft.
- **WordPress Connector 1.4.0:**
  - "SEO fixes" and "Content updates" options, off by default.
  - A change log that stores previous values; undo does not overwrite a value changed after MoxDOP.
  - The plugin's own title / description / canonical / noindex output when no SEO plugin is present, plus redirects and JSON-LD output.
- **Fix:** 1.3.0 returned health, one-click login and update responses unsigned, and MoxDOP rejects those, so the ADR-068 features did not work on real sites. 1.4.0 signs them; the minimum version for management features is 1.4.0.
- **Operator steps:**
  1. Download the new plugin (1.4.0) from Entegrasyonlar › WordPress and update it on the sites.
  2. In WordPress › Ayarlar › MoxDOP Connector, turn on "SEO fixes" / "Content updates".

## 2026-10-11 — Tıkla-çalıştır AI içgörüleri (8 yeni yer)

**State:** CODED + PHPUnit. Not deployed; answer quality is not yet reviewed on real data.
- `AiInsightsTest`, on SQLite and PostgreSQL:
  - every context builds from local data;
  - an alert cause is written on click and archived;
  - a lead's name, phone and e-mail are never sent;
  - a page can act only on its own subjects.

- **Existing AI features:** every place that already used AI was checked, and each one has a button.
- **Shared frame:**
  - `AiInsightService` + `InsightDefinition` + one `InsightAgent` base, with output summary + items (title, detail, tag).
  - `WriteAiInsightJob` and the `<x-operator.ai-insight>` block.
  - Answers go to the production archive (versioned, 👍/👎).
  - Each insight has its own AI route in the AI Control Plane, with budget and cost recording.
  - Nothing runs without a click.
- **New insights:**
  1. **Danışman › open item › "Neden önemli, ne yapmalı?"** Steps are tagged now / this week / check later / risk.
  2. **Google Ads › Arama talebi › "Alakasız arama terimlerini bul".** Negative candidates come from the brand's services and areas; converting terms and existing negatives are excluded.
  3. **Rakip izleme › Yorumlar and İşletme Profili › Yorumlar › "Yorum temaları".** Complaint / competitor ahead / strength. The brand's own GBP reviews are used when review intel has none.
  4. **Uyarılar › "Olası neden".** Uses 42 days of GA4, Search Console, Google Ads and Meta daily series, uptime failures, chart notes and other alerts.
  5. **Google Ads › Açılış sayfaları › "Reklam ve açılış sayfası uyumu".** Uses landing page cost, crawled title / H1 / description, ad texts and keyword quality signals.
  6. **Müşteri › Genel bakış › "Görüşme öncesi özet".** Uses health, budget, open work, alerts, the last report and renewals.
  7. **Satış › Lead kutusu › "Puanla".** Hot / warm / cold, questions to ask and a first message. Contact details are not sent.
  8. **Web sitesi › Teknik sağlık › "Geliştirici için iş listesi".** Findings are merged into prioritised tasks.
- New model `AgencyLead`, for the existing `agency_leads` table.

## 2026-10-11 — Eksik özellik taraması: 9 madde

**State:** CODED + PHPUnit. Not yet deployed or checked on staging.
- Tests: `GbpPartialCollectionTest`, `GoogleAdsGeoTest`, `Ga4ConversionSourcesTest`, `GoogleAdsSegmentAndConflictRulesTest`, `MonthlyReportV2Test`, `CustomerCommercialCardTest`, `ErrorAlertReporterTest`, `AssetAlertScannerTest` (GA4 drop), `WebsiteProductionCollectorTest` (CrUX field data), `GenericCompactStorageTest` (PostgreSQL), `GoogleAdsReadableNamesTest`, `MetaOutcomesTest`.

1. **İşletme Profili:**
   - A run that delivered the location and daily performance counts as a success even if other datasets are missing.
   - The missing datasets and Google's own error reason are shown, with a hint.
2. **Local performance:**
   - Google Ads province/district table: new `google_ads_geo_daily` family, with place names resolved through `google_ads_geo_names`.
   - Meta province/region breakdown.
   - GA4 regions, plus "Dönüşümler nereden geldi?" (key events by channel / campaign / landing page).
3. **Danışman (Google Ads):**
   - New rules: device / province / 3-hour block / age / gender waste (`segment-bid-adjustment`), and negative keyword conflicts (`negative-keyword-conflict`).
   - Negative conflicts include shared lists written under ADR-064.
   - The performance tab shows age and gender.
4. **Monthly report:**
   - Prepared on the 1st at 07:00 (Istanbul) for active brands, with AI commentary by default.
   - A "Müşteriye e-postala" button sends it to the customer's e-mail and contacts. The send is recorded (`emailed_at`, `emailed_to`).
5. **Customer card:**
   - Monthly fee and Google / Meta budgets.
   - Spend this month vs budget, with a month-end projection (over / under / on track).
   - Daily health score history (`customer_health_history`).
6. **Operations:**
   - Admin 2FA is required (config `require_admin_2fa`).
   - The audit warns when no remote backup disk is set.
   - `deploy.sh` installs the watchdog cron when it can.
   - Application errors are sent as push notifications, de-duplicated per 6 hours (`ErrorAlertReporter`).
7. **Website:**
   - Alerts for a week-over-week drop in GA4 sessions or conversions (`ga4_drop` thresholds).
   - Real-user Core Web Vitals (CrUX LCP / INP / CLS from PageSpeed field data, page or site scope) in Teknik sağlık.
8. **Compact storage for GA4, Meta and Google Ads:**
   - `moxdop:db:compact` now also converts 23 daily fact tables (config `moxdop-compact-facts.generic`).
   - The layout is read from the live table: text / json become dictionary ids, and only the natural-key primary key is kept.
   - Row count and one metric total are verified before the old table is dropped.
   - Unchanged rows are not rewritten.
   - Retention rolls up compact views too.
   - `Schema::hasTable` now sees views on PostgreSQL. Before, readers guarded by it skipped compact GSC tables.
9. **Hidden data made visible:**
   - Performance Max assets, Shopping products and videos are listed by text / title instead of ids. PMax asset text needs the next collection.
   - The Meta overview has a "Sonuçlar (Meta bildirimi)" block: leads and cost per lead, purchase value and ROAS, average daily reach and frequency. These are Meta's attribution, not CRM-verified.
   - The Business Profile page links to map rankings and review / competitor analysis.
   - **Not done — GBP Q&A:** Google retired the Business Profile Q&A API, so there is no source.

**Operator steps:**
- Run `php artisan moxdop:db:compact` to see the plan, then run it with `--execute`.
- Set `MOXDOP_BACKUP_REMOTE_DISK`.
- Admins without 2FA are sent to their profile to set it up.

## 2026-10-10 — Toplu ekle, bağlanan hesabın hemen toplanması, Danışman veri penceresi

**State:** CODED + PHPUnit.
- `DiscoverAndGroupTest`: bulk create skips blank groups, adds cities as service areas, and the service proposal is queued after the crawl.
- `ResourceAutomationRecoveryTest`: an unbound account resumes on the next tick after binding.
- `GoogleAdsAdvisorRunTest`: old central rows do not hide recent per-asset rows.
- `SystemAuditCommandTest`: every asset tab opens with bound accounts.

- **Toplu ekle (`/customers/discover`, "Müşteriler › Toplu ekle"):**
  - Unbound accounts are grouped by domain and name.
  - The customer name starts blank, so only groups the owner fills are created, all with one "Doldurulanları oluştur" button.
  - An optional "Hizmet verdiği şehirler" field adds TR service areas.
  - When the site crawl finishes, "Otomatik kur" builds the service / sector proposal by itself from the shared service pool, so existing services are reused, not recreated. The owner approves it on the brand's setup page.
- **Collection after binding:** an account parked as `unbound` or `customer_passive` starts on the next minute once it is bound to an active asset. Before, it waited a full interval (about a day).
- **Danışman (Google Ads):**
  - The "central vs per-asset rows" choice is now made within the review window, as on the asset pages. One old central row no longer makes the advisor read "no data".
  - A Google Ads, Meta or Business Profile account whose last review said "no data" or "not bound" is reviewed again right after its collection succeeds.
- **Audit:** each application error group shows where it broke (file:line) and the last time it happened; up to 25 groups are listed.

## 2026-10-10 — Staging sonucu ve durmuş hesapların geri alınması

**State:** CODED + PHPUnit (`ResourceAutomationRecoveryTest`: a geo empty-dimension failure is re-armed; an unrelated error is not).

- **Staging (7577ac6):**
  - Database 60 GB → 11 GB; disk 52 GB free (65 %).
  - `moxdop:db:compact` verified row counts and click totals for every table.
- **Recovery at deploy:** accounts stopped by any "missing natural key […]" write failure are put back in the collection queue at deploy. This covers GA4 geo, page, ecommerce and technology, not only landing page. They stopped as `request_requires_fix` because of the empty-dimension rejection that is now fixed.
- **Audit:** log and dataset error groups now show their last occurrence ("son dd.mm HH:MM"), so errors fixed by an earlier deploy can be told apart from live ones. Dataset messages that differ only by a record number are grouped together.

## 2026-10-10 — Sıkı veri depolama (Search Console), disk koruması, boş boyut değerleri

**State:** CODED + PHPUnit.
- `GscProductionCollectorTest` passes 18/18 on local PostgreSQL through the compact path: write, idempotent replay, device and country grain.
- `StorageGuardTest` and `EmptyDimensionNaturalKeyTest` pass.
- `moxdop:db:compact --execute` was run on local Postgres: counts and click totals matched and the size dropped about 4× (0.08 → 0.02 GB).
- Not yet run on staging.

- **Cause of the size:** each Search Console row stored `site_url`, query and page as full text plus ~10 constant provenance columns, with 6 indexes (two of them unique on the same text). That is about 1 KB on disk for ~100 bytes of information.
- **Compact storage (PostgreSQL):**
  - Each distinct text (site, search type, query, page, country, device, appearance) is stored once in `fact_dims` and referenced by an integer id.
  - Facts sit in narrow `gsc_f_*` tables, partitioned by month, with a single primary key and only the columns that vary per row: clicks, impressions, position, asset, run and collection time.
  - The old table name is a view with the old columns, so reading code does not change.
  - The writer detects the view and writes to the compact table instead (`CompactFactStore`). Mapping lives in `config/moxdop-compact-facts.php`.
  - SQLite (tests) keeps the old tables.
- **`moxdop:db:compact`** (plan by default; `--execute` converts):
  - It converts one table at a time, smallest first. It copies partition by partition, then takes a short lock to copy the rows written meanwhile, rename the old table and create the view.
  - It compares row counts and click totals, then drops the old table. If they differ, or with `--keep-legacy`, it keeps the old table as `<name>__legacy`.
  - It checks free disk before each table.
  - The migration converts empty tables at once.
- **Country back:** the query × country and page × country families are collected again, since they are small in compact form. The device crosses stay off.
- **Disk guard (`StorageGuard`):**
  - Below 6% or 3 GB free, collection jobs wait 10 minutes without spending an attempt, so the database never fills and stops.
  - Below 15% the watchdog sends a phone alert.
  - Thresholds come from `MOXDOP_DISK_*`.
- **Empty dimension values:** an empty text dimension in the natural key (GA4 unknown region / city / category, Meta breakdowns) is stored as `(empty)` instead of failing the whole batch. Previously GA4 geo, ecommerce and technology batches failed with "missing natural key". Landing page keeps its explicit empty-string allowance.

## 2026-10-09 — Search Console ambarı küçültme (`moxdop:db:slim`)

**State:** CODED + PHPUnit (`GscProductionCollectorTest` checks the compact row metadata). `moxdop:db:slim` was run on local Postgres: it emptied the 4 cross tables and compacted an old-format partition (0.15 GB → 0.08 GB, position kept). Not yet run on staging.

- **Staging measurement:** 60 GB database, ~51 GB of it Search Console tables. `moxdop:db:reclaim` found only 1.7 GB of bloat, so the size is real data.
- **Stopped collection:** the four cross-dimension families (page/query × device/country) are no longer collected (`moxdop-gsc-collector.disabled_families`, env `MOXDOP_GSC_DISABLED_FAMILIES`).
  - They held 23.4 GB and fed only a "top 30" block on the Search Console page. That block is now hidden when empty.
  - Device and country alone are still collected.
  - They were removed from the retention gold list.
- **Compact rows:** Search Analytics rows now store only `{"provider_average_position": …}` as metadata. The other ~10 keys (search type, data state, aggregation, completeness, collector version, CTR) were identical on every row. They are provenance of the dataset run, and CTR = clicks / impressions.
- **`moxdop:db:slim`** (plan by default; `--execute` applies it):
  - It empties the tables of disabled families (`TRUNCATE`) and removes their coverage records.
  - It rewrites old-format rows to the compact metadata, one table or monthly partition at a time, then runs `VACUUM FULL` on it. Each step checks free disk first.

## 2026-10-09 — Veritabanı şişkinliği (56 GB) — üretim durduruldu, geri kazanım komutu

**State:** CODED + PHPUnit. `DataPool/PostgresNoopUpsertTest` runs on PostgreSQL only: it passes with the fix and fails without it. `moxdop:db:reclaim` was run on local Postgres against an artificially bloated table (153 MB → 40 MB). Not yet run on staging.

- **Cause:** raw provider payloads are files on disk, not in the database. The database grew because `PostgresWarehouseWriter` rewrote every re-collected row with `ON CONFLICT DO UPDATE`, even when nothing had changed. Each rewrite leaves a dead row version; autovacuum makes that space reusable inside the file but never returns it to the disk.
- **Fix:** the upsert now updates only when a value column changed: `WHERE (target values)::text IS DISTINCT FROM (EXCLUDED values)::text`. Provenance columns (`last_collected_at`, run ids, fingerprint, `updated_at`) alone do not count as a change. Unchanged rows keep their `last_collected_at`; newly inserted days keep the table-level `MAX(last_collected_at)` watermarks fresh.
- **`moxdop:db:reclaim`** (plan by default; `--execute` applies it):
  - It compares each leaf table or partition's file size with the size its live rows need (row count × average width from the planner statistics), then rebuilds the bloated ones with `VACUUM (FULL, ANALYZE)`, one partition at a time, largest gain first.
  - It skips a table when free disk would drop below `--reserve-gb`.
  - Each rebuild locks that one table while it runs.
- **Audit:** `moxdop:audit` lists the 20 largest tables with index size, live and dead rows, the last vacuum, and the WAL size.

## 2026-10-09 — Staging denetimi (`moxdop:audit`) düzeltmeleri

**State:** CODED + PHPUnit (`Unit/SafeTimezoneTest`, `Observability/ObservabilityOperationsTest`, `Operations/SystemAuditCommandTest`). These fixes come from the first staging audit.

- **Legacy time zone name:** some Meta ad accounts report `Turkey`, which PHP 8.5 rejects. This crashed Meta's Kreatifler tab and the advisor plan job.
  - `App\Support\Time\SafeTimezone` maps legacy names to canonical ones (`Turkey` → `Europe/Istanbul`), and falls back to UTC for unknown names.
  - It is applied in the Meta / Google Ads / GA4 binding resolvers, the collection planners, the Google Ads collectors and the Meta date slicer.
- **Operational alert re-open:**
  - `semantic_key` is unique across all states, so an alert whose condition came back failed on insert. `moxdop:ops:evaluate-alerts` crashed every 5 minutes (57 times a day), and stale alerts such as "Expected workers unavailable" never resolved.
  - The resolved row is now reopened.
- **WordPress sites page:** `/integrations/wordpress-sites` returned 404 because `/integrations/{provider}` caught it first. The provider parameter is now limited to the supported AI providers.
- **Deploy smoke:** `smoke.sh` called `http://127.0.0.1`, which nginx answers with its default site (404). It now calls `APP_URL` and pins the host to 127.0.0.1 (`--resolve`). `storage:link` runs only when the link is missing.
- **Audit report:**
  - alerts and account problems are grouped with counts;
  - dataset run errors and Business Profile run errors from the last 7 days are listed;
  - the largest tables and storage folders are listed;
  - asset URLs without an id are no longer opened.

## 2026-10-09 — Sunucu denetim komutu (`moxdop:audit`)

**State:** CODED + PHPUnit (`tests/Feature/Operations/SystemAuditCommandTest`). It ran cleanly on local Postgres (163 pages, 0 errors). Not yet run on staging.

- **System checks:** `moxdop:audit` checks:
  - version and commit;
  - APP_DEBUG;
  - queue connection and backlog;
  - pending migrations;
  - failed jobs in the last 24 hours, grouped;
  - the Sistem Sağlığı read (scheduler, watchdog, workers, operational alerts, integrations, stale accounts, plugins, backup, 2FA);
  - disk;
  - the most frequent errors in the last 24 hours of `laravel*.log`.
- **Page sweep:** it then opens every operator GET page from inside the app as one user, with real data:
  - pages without parameters, and every page tab;
  - `--per-type` assets of every type on their asset pages;
  - brand, customer and prospect detail pages.
- **Read-only:**
  - each request runs in a transaction that is rolled back;
  - jobs, mail and notifications are faked;
  - outside HTTP calls are blocked and reported (`--allow-http` lets them through);
  - downloads, OAuth and connection routes are skipped;
  - 2FA enforcement is off only inside the audit process.
- **Report:** the same error on many pages prints once, with the source file and line (compiled Blade is mapped back to the template). The report is also saved to `storage/logs/audit-*.txt`.
- **Bug found by the first run:** Kütüphane › Marka sorgu portföyü crashed when a brand was selected. The `brands.offerings` text column shadowed the `offerings` relation; the page now reads the relation explicitly.

## 2026-10-09 — Faz 14: Strateji boşlukları

**State:** CODED + PHPUnit (`TrackingHealthCheckerTest`, `ComplianceAuditTest`, `Gbp/ReviewReplyDraftTest`, `Sales/LeadSourcesTest`, `KvkkAndBackupTest`, `Integrations/IntegrationE2E3Test`, `Unit/RobustAnomalyTest`, `Advisor/GoogleAdsAdvisorRuleEngineTest`, `Brain/CrossBudgetSeasonRulesTest`, `GoogleAds/AuctionInsightsUploadTest`). Postgres: migrations and the backup → restore round trip pass. **No live UAT.** Meta Lead Ads needs the `MOXDOP_META_LEADGEN_*` env and a webhook subscription in the Meta app.

- **Tracking:**
  - `TrackingTagDetector` sees Consent Mode and common CMPs.
  - `TrackingHealthChecker` adds three checks: `consent_mode_not_seen`, `website_conversions_dropped` (7 days vs 28 days) and `conversions_double_counted`.
- **Google Ads ad text:**
  - RSA headlines and descriptions are collected (`ad_group_ad.ad.responsive_search_ad.*` → metadata).
  - `ComplianceAuditor` scans them as source `google_ads_ad`.
  - New draft sector packs: legal, finance, real estate, education, food supplement.
- **GBP review reply drafts:**
  - Drafts are made only on click (`gbp.review_reply` route, `DraftReviewReplyJob`) and use liked examples from Üretim Arşivi.
  - A cost estimate is shown before the draft (`AiCostEstimator`), along with the median reply time.
  - Reviewer names are never sent.
  - Nothing is posted to Google; the operator copies the text.
- **Backups:** `moxdop:backup:restore {file?} --latest --force` verifies the file, takes a safety backup, then restores (sqlite / pgsql / mysql). pg_dump uses `--clean --if-exists`.
- **Lead sources:**
  - `/api/meta/leadgen` handles GET verify and signed POST requests. Leads land in the agency lead inbox as `meta_lead_ad`.
  - A WhatsApp number that matches no customer or prospect lands there as `whatsapp`.
- **Collection:**
  - `resource_automations.preferred_hour` sets the preferred collection hour (the next run keeps the interval and moves to that hour).
  - Sistem Sağlığı shows per-dataset coverage.
  - The costs screen shows DataForSEO brand caps.
  - Manual-only sources are labelled.
- **Advisor:**
  - `daily-anomaly` (Google Ads account totals): a spike is |robust z| ≥ 3.5 (median / MAD) and ≥ 50 % change against 28 days. A drift is the last 3 days all ≥ 1.4× the EWMA of the days before.
  - Cross `budget-shift`: over 28 days, both Google Ads and Meta must have spend ≥ 1000 and ≥ 10 counted conversions (Meta only through the conversion dictionary). The rule fires when the cheaper channel's CPA ≤ 0.6× the other's, and suggests a 15 % shift test.
  - Cross `season-ahead`: last year's GSC web clicks for the coming 60 days are ≥ 1.3× the 60 days before, with at least 200 clicks.
  - All three rules are listed in Yöntem Kütüphanesi.
- **Auction Insights:**
  - The Google Ads API does not provide auction insights, so the operator uploads the CSV exported from Google Ads. The upload is available on the Google Ads account (tab "Açık artırma") and on Rakip izleme (tab "Google Ads açık artırma").
  - Turkish and English headers are both read, as are comma, semicolon and tab separators and UTF-16 files.
  - A value shown as "< 10%" is kept as "<%10".
  - Re-uploading the same period replaces the earlier upload; the screen shows the change against the previous upload and marks new competitors.
- **Still open:**
  - date-range backfill (needs collection lifecycle planner changes);
  - the Perfex lists and the 45 health rules (the Perfex files are not in the repo);
  - Meta discovery still runs in the page request;
  - bulk binding;
  - about 110 older baseline test failures.

## 2026-10-07 — Faz 13: Entegrasyon denetimi E2/E3'te kalanlar

**State:** CODED + PHPUnit (`tests/Feature/Integrations/IntegrationE2E3Test`; updated `GoogleResourceDiscoveryTest`, `IntegrationOnboardingInfrastructureTest`, `GlobalAgencyOperatingLayerTest`, `OperatorGoogleIntegrationConfigurationTest`, `GoogleInitialBackfillOrchestratorTest`). **No live UAT:** the watchdog cron line has not been installed on staging yet.

- Outside watchdog (`moxdop:ops:watchdog`): its own cron line (`deploy/staging/cron.example`), not the Laravel scheduler. It checks three things and pushes a critical phone notification when one fails:
  - a stale or missing scheduler heartbeat (over 15 minutes),
  - queue heartbeats older than 20 minutes,
  - a `jobs` backlog older than 30 minutes when the default queue is the database.

  Sistem Sağlığı shows whether the watchdog runs.
- `deploy.sh`:
  - stops when `QUEUE_CONNECTION` is empty or `sync`,
  - warns when it is not `redis` (Horizon only works redis),
  - warns when the watchdog cron line is missing.
- Operational alerts reach the operator:
  - the home screen shows a "Sistem uyarısı" banner with critical / warning counts and links to Sistem Sağlığı;
  - Veri Kaynakları shows each bound account's automatic collection state (on / off / stopped, last success, reason).
- GA4 and Search Console get their own `ga4_stale` / `gsc_stale` alerts per bound account (interval + 3 days). A fresh website crawl no longer hides them.
- Google account discovery runs in the background (`DiscoverProviderResourcesJob`) from the Google page and Veri Kaynakları. Nothing runs inside the page request; the result is shown when ready.
- Single hub:
  - Entegrasyonlar opens with "Bağlantı sağlığı": reconnects needed, authorizations expiring within 7 days, stopped / stale accounts, silent / outdated WordPress plugins, critical system alerts. Problems are listed red-first, each with one action.
  - Groq, OpenRouter and WhatsApp cards were added. The Groq/OpenRouter card path had never run and had a type bug, now fixed.
  - Discovered public profiles moved to the bottom.
  - The Sistem Sağlığı accounts table has Turkish states and types, the error explanation, and one action per row (Yeniden bağlan / Varlığa bağla / Şimdi çek).
- Google page:
  - Turkish, with the same four-step bar as Meta (Uygulama → Yetki → Hesaplar → Veri, shared partial `integrations/partials/setup-steps`).
  - Tabs are Genel Bakış · Hesaplar · Ayarlar · Geçmiş. The duplicate "Connectors" tab was removed; old links land on Genel Bakış.
- DataForSEO and AI provider pages are in Turkish.
- Fewer duplicate buttons: the website Teknik sağlık and Altyapı tabs no longer have their own "refresh" (the website header button covers all tabs).
- Account lists: Google and Meta unbound account lists and brand pickers show up to 1000 (was 100), with a search box.
- Dead code:
  - Removed `GoogleProviderResourceDiscovery` and `MetaProviderResourceDiscovery` (nothing resolved them).
  - `/integrations/connectors/meta-ads` redirects to the Meta page instead of a permanent "not configured" page.
- Not done:
  - The collection lifecycle (`CollectionSchedule`, `ExecuteCollectionLifecycleService`) was kept: the audit called it unused, but `ResourceAutomationService` and `CollectLiveBoundDataService` still call the lifecycle service. `MetaResourceDiscoveryService` was kept too, because it has its own tests.
  - Meta discovery (businesses / ad accounts) still runs in the page request.
  - Bulk binding and server-side pagination are not built (the lists are filtered in the browser).
  - Collect buttons still exist on the Google / Meta pages and the Google Ads connector: provider-wide initial backfill plus per-account buttons.

## 2026-10-07 — Faz 12: Entegrasyon denetimi E1'de kalanlar

**State:** CODED + PHPUnit (`tests/Feature/Integrations/IntegrationE1FixesTest`, `tests/Feature/WordPressConnectorV1Test`). A code check on 2026-10-07 found that several E1 items marked done under Faz 4 were still open. This phase closes them. **No live UAT.**

- Google / Meta OAuth: the "connected" / "failed" result is now shown on the operator page (flash message). It used to be sent as a Filament notification the operator UI never displayed.
- Bind modal (Google and Meta): the brand is no longer pre-selected (it used to be the alphabetically first brand). `BrandMatchSuggester` suggests the brand whose name or site domain shares the most words with the account name; the operator has to pick it.
- Meta collection: a failed or partial run no longer counts as "completed". The step is done only when `collection_state = completed`. History shows failed runs as errors, and the labels are in Turkish.
- Google Ads connector header: shows the real authorization state (connected / not authorized / reconnect needed / expired) instead of a fixed "Bağlı".
- Asset › Veri Kaynakları: the WordPress row reflects the paired MoxDOP connector ("Hazır" / "eklenti kurulmalı" / "WordPress değil") instead of a fixed "sonraki aşamada".
- Automation panel: opening it no longer inserts `resource_automations` rows (the every-minute tick already discovers accounts). Settings, run now, resume, close and recheck are hidden for non-admins.
- Google "Markaya bağla…", Meta "Markaya Bağla" and the AI control plane (route and budget) forms are shown only to admins; the server-side 403 checks stay.
- WordPress: `paired_at` (ISO-8601) is compared in the DB datetime format. Before this, a same-day inventory never matched and a needless full inventory was started. The connector signing test was also fixed (it re-registered `Http::fake`, which never took effect). `WordPressConnectorV1Test` is now fully green.
- Plugin readme: now 1.3.0. It no longer claims to be read-only and lists the three remote actions (drafts, one-click login, approved updates) and their switches.
- WhatsApp:
  - The Embedded Signup config id moved to `WHATSAPP_SIGNUP_CONFIG_ID`; it stays stored per integration once saved.
  - The default business context no longer contains a person's name or prices.
  - The reply agent uses the agency name from settings.
- Not done here:
  - The test suite still has about 110 older failing tests, mostly Meta/GA4 collector, DataPool and report delivery tests written against retired data families. They are listed in the baseline and unchanged by this phase.
  - The E2/E3/E4 remainder is Faz 13–14.

## 2026-10-06 — Faz 11: Sade menünün tamamlanması + güvenlik

**State:** CODED + PHPUnit (`tests/Feature/Work/AlertsPageTest`, `tests/Feature/Portfolio/BrandFilesTabTest`, `tests/Feature/Operations/KvkkAndBackupTest`, `tests/Feature/Advisor/AdvisorFaz6Test`; menu and brand tabs locked in `PanelDesignFreezeTest`). Full Feature + Unit suites compared with the baseline. The migration runs on PostgreSQL 16, and a real pg_dump backup passed the new read-back check there. **No live UAT:** 2FA enforcement has not been switched on in staging, and alert snoozing has not been used on real alerts.

- Uyarılar (İşler menüsü, `/alerts`): every open asset alert in the portfolio, most severe first. Severity counts, filters by brand and severity, snoozed and closed-in-30-days views, and a link to each asset.
  - An alert can be snoozed for 1, 7 or 30 days. Snoozed alerts are hidden on Bugün and the dashboard until the date.
  - An alert that closes and is detected again starts unsnoozed. Closing stays automatic.
- İş listesi › "Önerilen (Danışman + SEO)": open SEO Görevleri and advisor items from every channel in one priority order, at most 10 per brand. This is the roadmap's single work list next to the operator's own tasks.
- Marka › Dosyalar sekmesi: files attached to the brand. The "Dosya yükle" button opens Dosyalar with the brand scope.
  - Uploads made from a brand, customer or asset link are now attached to that record. Before this, uploads never set `brand_id`.
  - Otomatik kur links to Açık Web Keşfi, filtered by the brand.
- Güvenlik:
  - Sistem Sağlığı lists active admins without two-factor authentication.
  - `MOXDOP_REQUIRE_ADMIN_2FA=true` limits such admins to the Profile page until they enable 2FA. It is off by default.
  - Every nightly backup is read back before it counts as a success: the whole gzip stream, the SQLite header, or the pg_dump / mysqldump completion footer. A file that fails the check is deleted and the run is recorded as failed, which sends a push notification.
- Not done:
  - The work list's suggested view is read-only. Done / skip still happen on SEO Görevleri and Danışman.
  - Alerts cannot be closed by hand.
  - Customer-level files have no tab of their own; the customer page link opens Dosyalar filtered.
  - There is still no restore command.

## 2026-10-05 — Faz 10: Sade menü + gözden kaçanlar

**State:** CODED + PHPUnit (`tests/Feature/Reports/ChartAnnotationsTest`, `tests/Feature/Portfolio/CustomerHealthScoreTest`, `tests/Feature/Intel/AiVisibilityTest`, `tests/Feature/Operations/KvkkAndBackupTest`; menu locks in `PanelDesignFreezeTest`, `GlobalAgencyOperatingLayerTest`). Full Feature + Unit suites compared with the baseline; the new migrations run on PostgreSQL 16. **No live UAT:** no real backup was restored, no AI visibility probe ran against a real model, health scores were not checked against the owner's view of the portfolio.

- Grafik notları (Raporlar menüsü, `/reports/annotations`): dated events per brand (campaign, site change, other) or agency-wide (Google algorithm update, regulation / platform rule) with note and source; they appear as markers on monthly report charts and are listed under them.
- Müşteri sağlığı puanı (0–100, daily `moxdop:customers:health` 07:10): penalties for conversion / traffic drop (last 28 days vs previous 28), no contact for 30 days, renewals due, unpaid collections, critical / high alerts (thresholds in `moxdop-assistant.health`). Badge on the customer list; Bugün › "kime ne yazmalı" puts the risk band first.
- AI görünürlüğü (Pazar menüsü, `/market/ai-visibility`): per brand up to N questions a customer would ask; on click each is sent to the configured `intel.ai_visibility_probe` route (queued), the answer is checked for the brand's name / site and for competitors; history per question. Never automatic.
- KVKK (Ayarlar › KVKK, admin): per active customer the data processing agreement date, note and whether health data is processed; optional WhatsApp message text retention (≥ 30 days; `moxdop:whatsapp:retention` 03:50 blanks older texts, conversation and times stay).
- Sistem yedeği (`moxdop:backup` 03:30): compressed pg_dump / mysqldump / SQLite copy in `storage/app/backups`, last 14 kept, optional copy to a filesystem disk (`MOXDOP_BACKUP_REMOTE_DISK`), every run recorded; failure → phone notification; Sistem Sağlığı shows the last successful backup (stale after 26 h).
- Sade menü (roadmap target): Bugün · Portföy (Müşteriler, Markalar, Varlıklar) · İşler (İş listesi, Danışman, SEO Görevleri, Yenilemeler) · Pazar (Sorgular, Hizmetler, Rakipler, Harita sıralaması, Backlink, Rakip izleme, AI görünürlüğü) · Satış (Lead kutusu, Potansiyel müşteriler, Niyet Radarı, WhatsApp) · Raporlar (Aylık rapor, Grafik notları, Üretim Arşivi, Uyum, Aktivite) · Sistem (Entegrasyonlar, WordPress siteleri, Ayarlar). Fırsatlar, Bulgular, Öneriler, Açık Web Keşfi, Dosyalar left the sidebar (Ayarlar › Operasyon links them); Sorgu kümeleri is a button on Sorgular; Arka plan işleri stays under Ayarlar. All routes unchanged.
- Not done: no restore command or restore test (restore is manual `gunzip | psql`); the remote copy needs a configured disk; KVKK page is a register only (no document upload, no consent texts); separate "Uyarılar" page from the roadmap is not built (alerts stay on Bugün and asset pages); AI visibility uses one model route (no per-engine comparison of ChatGPT / Gemini / Perplexity).

## 2026-10-05 — Faz 9: Aylık rapor v2 + WordPress eklentisi v2

**State:** CODED + PHPUnit (`tests/Feature/Reports/MonthlyReportV2Test`, `tests/Feature/Integrations/WordPressManagementTest`). Full Feature + Unit suites compared with the baseline; both migrations run on PostgreSQL 16 and the report builder (incl. camelCase GA4 columns) was exercised on PostgreSQL. **No live UAT:** no real brand month was reviewed against Looker, no AI commentary on real numbers, no real WordPress site with plugin 1.3.0 (login link, update) was tried.

- Aylık rapor (İşler menüsü, `/reports/monthly`): per brand and month — Search Console (clicks, impressions, CTR), GA4 (sessions, users, engaged sessions), Google Ads (cost, clicks, impressions, conversions, CPA), Meta (spend, impressions, clicks, CPC), Business Profile (views, calls, directions, website clicks, messages) — each vs the previous month and the same month last year, with a daily SVG chart (this month vs last); brand conversions from the conversion dictionary; local visibility (map grid SoLV, Google rating / new reviews); work done in the month with measured before/after; next items; rule-based highlights. Central rows win over legacy per-asset copies; a channel without data is shown as missing. Numbers are frozen per report (`monthly_reports`) and can be refreshed.
- AI commentary only on click (queued, `reports.monthly_commentary` route, archived in Üretim Arşivi), editable (summary, wins, watch, next month) plus the operator's note. Publish → signed client link (60 days, published reports only, `noindex`), print / save as PDF from the browser; operator preview.
- WordPress Connector 1.3.0 (ADR-068): `GET /health` (WordPress/PHP versions, pending core / plugin / theme updates, Site Health counts), `POST /login-link` (single-use 60-second link for the user the site admin picked; off by default), `POST /updates` (one WordPress-offered update per request; off by default). MoxDOP: daily health read (`moxdop:wordpress:health` 06:20), Entegrasyonlar › WordPress siteleri (versions, pending updates, Site Health, update list), admin-only audited "WP paneline gir", admin-approved queued updates recorded in `external_write_actions` (`update_apply`, not undoable), health re-read after each update.
- Not done: the old Client Value Story PDF / scheduled delivery still exist separately (report v2 is not emailed automatically); report v2 PDF is browser print (no DomPDF artifact); no Looker parity check per brand; WordPress updates have no automatic backup or rollback; plugin auto-update of the connector itself is not implemented (download 1.3.0 from Site bağlayıcıları).

## 2026-10-04 — Faz 8: Rakip/yorum istihbaratı + ajans satışı

**State:** CODED + PHPUnit (`tests/Feature/Intel/MapGridTest`, `KmlExperimentTest`, `BacklinkEngineTest`, `ReviewAndCompetitorWatchTest`, `ProspectAuditTest`, `tests/Feature/Sales/LeadInboxTest`). Full Feature + Unit suites compared with the baseline; the 7 migrations run on PostgreSQL 16 and the new readers (grid metrics, spend, experiment, review comparison/themes, backlink queries, competitor watch, costs) were exercised on PostgreSQL. **No live UAT:** no real DataForSEO maps/reviews/backlinks response, Nominatim answer, competitor site or website form post was observed; DataForSEO prices in config are estimates until the first real invoices (real cost per task is recorded).

- DataForSEO standard queue (`dataforseo_tasks`, `moxdop:intel:collect` every 5 min): paid tasks are posted in batches with their cost; results are read for free and handed to the feature. One per-brand monthly USD cap (`brand_intel_settings.monthly_usd`) covers grid + reviews + backlinks; Maliyetler shows "DataForSEO · pazar istihbaratı".
- Harita sıralaması (Pazar menüsü, `/market/map-rankings`): N×N grid (3/5/7/9, km spacing) around the Business Profile pin or a set centre, top 20 per point, our rank by place id / CID / site host / phone; ARP, ATRP, SoLV (top 3), change vs the previous scan, heat grid + OpenStreetMap map, businesses most often in the top 3. Up to 5 keywords per brand, scheduled every N days (`moxdop:intel:grid` 04:30) when switched on.
- KML deneyi: service areas geocoded from OpenStreetMap Nominatim (background, 1/s); KML with the profile pin + one pin per area, informative description (address, phone, site; sector compliance check), pin cap; download only. "Haritayı yayınladım" records the date; grid ATRP / SoLV 42 days before vs after per keyword.
- Backlink fırsatları (`/market/backlinks`): summaries for the brand and approved competitors, referring domains (new 45 days, lost 90 days), opportunities linking to ≥ 2 competitors but not to us (rank ≥ 50, spam ≤ 30), Turkish directory / citation list by sector (free), outreach status new → contacted → waiting → live / lost / rejected with page URL, contact and note; weekly check that the promised link is on the page. Monthly refresh when switched on (`moxdop:intel:backlinks` 04:50).
- Rakip izleme (`/market/competitor-watch`): Google reviews of the brand and the top grid competitors (+ manual CID) — rating and 90-day change, count, reviews in 30 / 90 days, 90-day average, owner response rate, recurring words / phrases in negative reviews and recent examples; reviewer names are not stored (`moxdop:intel:reviews` 05:05, every 14 days when on). Weekly public snapshot of approved competitors' home page and sitemap: new / removed pages, changed title / H1 / description (`moxdop:intel:competitors` Mon 05:25, free). Meta Ad Library links per brand / competitor (page id or name search).
- Dış denetim (potansiyel müşteri › Dış denetim): 14 public website checks with a score, optional one Google Maps search (rank, rating, top 3; agency cap 5 USD/month), printable report page.
- Lead kutusu (Satış menüsü, `/leads`): the agency's own site form posts to `/api/leads/{token}` (hashed, rotatable token, throttled, honeypot); same phone within 24 h merged; push notification; one-click conversion to a prospect (follow-up tomorrow); manual entry.
- Not done: map grid points use DataForSEO's coordinate search (no own proxy / device emulation); KML experiment effect is unmeasured; review themes are word counts (no AI summary); competitor watch reads only the home page and sitemap (no content diff); Meta Ad Library has no data import; lead inbox has no email notification or auto-reply; prospect audit has no PDF artifact (browser print).

## 2026-10-03 — Faz 7: Beyin (Yöntem Kütüphanesi, doğrulama, sonuca dayalı öncelik, tutarlılık, Ads analitiği, sektör örüntüleri)

**State:** CODED + PHPUnit (`tests/Feature/Brain/MethodLibraryAndVerificationTest`, `CrossConsistencyRulesTest`, `SectorPatternsTest`, `tests/Feature/Advisor/GoogleAdsAdvisorRuleEngineTest`, `GoogleAdsAdvisorRunTest`). Full Feature + Unit suites compared with the baseline; the migration runs on PostgreSQL 16 and the new readers (sector patterns, sector rule stats, Quality Score recorder/history, cross consistency) were exercised on PostgreSQL. No live UAT: no real plan, Quality Score change or sector data was observed.

- Yöntem Kütüphanesi (Ayarlar › Yöntem Kütüphanesi, `/settings/methods`): every numeric threshold and word list of the advisor, SEO tasks, alerts and demand configs is shown with its file default and can be overridden by an admin (`method_settings`, next plan); rules can be switched off per scope (advisor / SEO); each rule shows done / measured / improved / came back.
- Verification: "Yapıldı" immediately queues a rules-only plan (no AI) for that asset. An item no longer detected becomes "Doğrulandı"; still detected within 7 days → "Hâlâ görünüyor"; after 7 days → reopened as "Geri geldi" (`reopened_count`). "30 gün ertele" hides an item/task; it comes back only if the problem is still there when the date passes. Data is collected daily, so the first check may still show the old state.
- Outcome-based priority: outcomes are measured at 28 and 56 days; once a rule has ≥ 5 measured outcomes its priority is multiplied by 0.8–1.2 by the share that improved — using the brand's own sector when that sector has enough outcomes (ADR-066), else agency-wide.
- Cross-asset consistency (Kanallar arası danışman): Business Profile website missing / pointing to another host, profile phone not on the home/contact page (NAP), Google Ads landing hosts and Meta destinations outside the brand's site (WhatsApp / Messenger / Instagram / Maps allowed, editable). Replaces the old Filament-only `Analyze*ConsistencyJob` path noted in ADR-065 (those jobs stay unused).
- Google Ads analytics: `ngram-waste` (2–3 word phrases recurring across cheap non-converting terms, phrase-match paste list, outcome measured like negatives), daily Quality Score history (`google_ads_quality_score_history`, `moxdop:google-ads:record-quality-scores` 05:40, 400 days) + `quality-score-drop` (≥ 2 points vs ≥ 28 days ago, components that worsened), `performance-anomaly` (per campaign, last 7 vs previous 28 days: CPC up ≥ 30 %, CTR down ≥ 30 %, conversion rate down ≥ 40 %, minimum clicks), `landing-keyword-mismatch` (no keyword word in the crawled landing title / H1 / description / URL, Turkish suffixes tolerated).
- Sector patterns (Ayarlar › Sektör örüntüleri, ADR-066): for sectors with ≥ 2 active brands — recurring unbranded demand queries, repeating advisor problems, rule results in the sector vs agency-wide, and a brand's gaps (queries ≥ 2 other brands track). Aggregates only; never in client reports.
- Not done: Quality Score drop needs 28+ days of recorded history before it can fire; anomaly detection is campaign-level only (no ad group / device / hour); n-gram lists are paste-only (the ADR-064 writer still accepts only `negative-keywords`); sector is the brand's primary `sector` field only; landing ↔ keyword fit uses crawled head text, not body content.

## 2026-10-02 — Faz 6: Asistan (Bugün, hatırlatıcı, telefon bildirimi, takvim, yenilemeler, uptime, WhatsApp ↔ müşteri)

**State:** CODED + PHPUnit (`tests/Feature/Assistant/UptimeAndPushTest`, `RenewalsTest`, `TodayRemindersCalendarTest`, `WhatsAppContactLinkTest`). Full Feature + Unit suites compared with the baseline; the migration runs on PostgreSQL 16 and the new readers were exercised on PostgreSQL. No live UAT: no real ntfy / Telegram message, RDAP answer, site outage or Google Calendar subscription was observed.

- Telefon bildirimleri (Ayarlar › Telefon bildirimleri, admin): ntfy topic (+ optional token) and/or Telegram bot + chat id (secrets encrypted), minimum severity, test button, send log (`push_notifications`, 90 days). Pushed: site down / back up, newly opened high/critical asset alerts, renewals at 30/14/7/1 days, due reminders (always). The owner's own channel — not a write to a client account.
- Uptime: every 5 minutes one queued check per operational website (safe public fetcher); 2 consecutive failures → `site_down` critical alert + push; recovery resolves + push with outage length. `uptime_checks` kept 30 days; `uptime_states` holds the current state.
- Yenilemeler (`/renewals`, İşler menüsü): domain and SSL rows are created for every active website; domain expiry + registrar from public RDAP (weekly), SSL expiry from the collected certificate (free auto-renew issuers marked); hosting / other added by hand; a manual date is never overwritten. Cost, customer charge, collection state (faturalanmadı / faturalandı / ödendi / ücret alınmıyor), "Yenilendi" (+1 year). Daily `moxdop:renewals:daily` (06:05) + website alerts `renewal_due_*` (≤30 days; auto-renewing ≤7).
- Hatırlatıcılar: one-off / weekly / monthly / yearly, optional customer; pushed when due (every minute, once); repeating ones move forward when done.
- Google Takvim: read-only iCalendar feed per user (`/calendar/{token}.ics`, secret token, regenerable) with reminders, renewals and assigned task due dates. No write to Google (no ADR needed).
- Bugün panel (top of the home screen): sites down, today's reminders + quick add, "Kime ne yazmalı" (WhatsApp conversations whose last message is incoming, prospect follow-ups due, customers with critical/high alerts or 30+ days of WhatsApp silence), renewals within 30 days, calendar link.
- WhatsApp ↔ müşteri/aday: conversations are linked by phone (customer primary phone, customer contacts, prospect phone; last 10 digits) on creation and hourly; operator links are kept. Inbox shows the link, links by hand, creates a prospect from an unknown contact (follow-up tomorrow) and saves `next_follow_up_on` / `next_step`.
- Not done: messages are still never sent from MoxDOP (copy only); the reply AI does not yet receive the linked customer's context; no customer health score; no invoice / payment integration (collection state is manual); tasks still have no reminder time (only due date); uptime checks the home page only (no keyword / SSL handshake check).

## 2026-10-01 — Faz 5: sektör paketleri, sağlık kuralları, uyum denetçisi, Üretim Arşivi

**State:** CODED + PHPUnit (`tests/Feature/Archive/ProductionArchiveTest`, `tests/Feature/Compliance/ComplianceAuditTest`). Full Feature + Unit suites compared with the baseline; new migrations run on PostgreSQL 16 and the audit / compliance page exercised on PostgreSQL. No live UAT. **The health rules are a draft starter set, not legal text** — the RG 12.11.2025 / 33075 change still needs a lawyer's review; rules are edited on screen.

- Üretim Arşivi (`ai_productions`, gold, never deleted; `/archive`, İşler menüsü): advisor Google Ads / Meta / Business Profile drafts, AI SEO briefs, WhatsApp reply suggestions and brand setup proposals are archived when they land on their work item (model hooks; drafters unchanged). Same content is stored once per item; regenerating adds a version. Versions are marked Yeni / Kullandım / Yayınlandı / Kullanılmadı and rated 👍/👎; filter by type, status, brand, work item. Requesting a draft first restores a fresh (≤ 14 days) archived draft instead of a new AI call; "Yeniden hazırla" on a ready draft still calls AI.
- Sector packs (`config/moxdop-sector-packs.php`, `SectorPack` classes): a pack applies to brands whose sectors (`Brand::sectorCodes()`) match; can be switched off. Health pack (healthcare, dental, medical_aesthetics) rules: superlatives, result guarantees, inducements (discount / campaign / free check-up / gift), patient testimonials, before/after, comparison, Meta ad sets targeting under 18, and an off-by-default required-phrase rule (institution / licence). Matching is Turkish-folded and suffix-tolerant; symbol patterns (%100) match raw text. Ayarlar › Sektör paketleri edits phrases, advice, severity, on/off and adds rules; edits are kept (`origin=operator`).
- Uyum denetçisi: daily `moxdop:compliance:scan` (06:45, active customers, stored data only) checks advisor AI drafts, AI SEO briefs, live Meta ad title/body and ad set targeting, stored website pages (visible text, ≤ 150 latest pages per site) and Business Profile profile/posts/services. Findings (`compliance_findings`) keep the phrase and an excerpt (GBP content is purged after 30 days), resolve when the content no longer matches, stay dismissed with a note. Uyum page (İşler menüsü) with "Şimdi tara". AI drafts in Danışman and AI SEO briefs show a live "Uyum: N sorun" badge.
- Fix: page text extraction joins text nodes with spaces (competitor page word counts in Faz 2b were slightly low).
- Not done: Google Ads ad text (RSA headlines/descriptions) is not collected, so live Google Ads text is not audited; Meta `object_story_spec` texts and images are not checked; Perfex "yasaklı reklam ifadeleri" list is not in the repo (only the draft list above); no other sector pack yet; archive has no link to `ai_usage_records` cost per version.

## 2026-09-30 — Faz 4: entegrasyonlar (hatalar/güvenlik, kendi kendini onaran akış, tek görünüm, maliyet, eklenti sürümü)

**State:** CODED + PHPUnit (`tests/Feature/Integrations/IntegrationSelfHealingTest`, `SystemHealthAndCostsTest`). Full Feature + Unit suites compared with the baseline; new read queries exercised on PostgreSQL 16. No live UAT (no real OAuth reconnect or token expiry observed).

- E1: Meta `reauth_required` / `permission_required` and dead-token credential states now open the `credential_reconnect_required` alert (before, only Google states did). File log channels (`single`, `daily`) mask sensitive context keys and bearer / OAuth / Meta tokens in messages (`App\Logging\RedactSecretsTap`). `/ops/health-snapshot` is admin-only. Successful OAuth writes `INTEGRATION_RECONNECTED` to the security audit log.
- E2: `credential_expiring` warning 7 days (`MOXDOP_OPS_CREDENTIAL_EXPIRY_WARNING_DAYS`) before a Google refresh token (testing-mode apps) or a Meta long-lived token expires. A successful Google/Meta OAuth makes the accounts stopped for "reconnect" due immediately. Daily `moxdop:resources:retry-stopped` (05:10) retries accounts stopped by repeated failures (after 20 h) and resumes "reconnect" stops whose integration and account are usable again; contract errors, cancellations and portfolio gates are left alone. Queue heartbeat probe job on `default` and `collection` every 5 minutes; DB collection workers write a heartbeat once a minute. `deploy.sh` runs `smoke.sh` and `schedule:list` after `up` (warnings, no rollback).
- E3: Ayarlar › Sistem Sağlığı (`/settings/system-health`, linked from Integrations and Ayarlar › Operasyon): scheduler, workers, open system alerts, each integration's status and authorization expiry, every account's collection state, data-through date and freshness (stopped and stale first), WordPress plugin versions; admins can retry stopped collections. The Integrations hub WordPress card reports paired / outdated / silent sites instead of a fixed "setup required".
- E4: Ayarlar › Maliyetler (`/settings/costs`, admin): six months of recorded AI spend per provider and DataForSEO spend (area SERP checks, demand enrichment, prospect radar) with the AI monthly budget. WordPress connector plugin older than `moxdop-wordpress.connector_version` shows on the website integration page and raises a low `wordpress_plugin_outdated` website alert.
- Not done: arbitrary date-range backfill from the operator UI (existing initial backfills and GA4 restatement remain); `MOXDOP_OPS_EXPECTED_SUPERVISORS` must still be set on the server for the worker-missing alert (suggested `queue:default,queue:collection,db-collector`); connect/bind flows are still per provider (no shared connector interface); no integration-level view/manage permission split (admin vs others only).

## 2026-09-29 — Faz 3: ölçüm temeli (dönüşüm sözlüğü, takip sağlığı, markalı ayrım, Sayfa Karnesi)

**State:** CODED + PHPUnit (`tests/Feature/Measurement/BrandConversionDictionaryTest`, `TrackingHealthCheckerTest`, `PageScorecardTest`, `tests/Feature/Demand/BrandedSplitReaderTest`, `Ga4ProductionCollectorTest::landing_page_by_channel_is_stored_per_page_and_channel`). Full Feature + Unit suites compared with the baseline; new migrations run on PostgreSQL 16 and the new read queries were exercised on PostgreSQL. No live UAT; provider data is seeded in tests.

- Brand conversion dictionary (`brand_conversion_sources`): daily `moxdop:measurement:refresh` (06:15, active customers) discovers the brand's GA4 key events (configured + reported), Google Ads conversion actions (not removed), Meta action types that are conversions (one entity level per brand) and Business Profile actions (calls, messages, bookings, directions, website clicks). Each gets a suggested type (form, phone call, WhatsApp/message, appointment, booking, purchase, qualified lead, other) and a "counts" default that avoids double counting: GA4 counts the website; Ads website-tag and GA4-import actions, Meta pixel events and Meta aggregates (`lead`, `purchase`, `omni_*`, `*_total`) are listed but not counted; Ads call/lead-form actions, Meta on-Meta leads/messages and Business Profile calls/messages/bookings count. Operator changes are kept (`origin=operator`). Brand › İşletme › Dönüşümler shows the 30-day total vs the previous 30 days, per type, per signal, with the homepage tags.
- Tracking health (daily alert scan, website assets): stored homepage HTML tag detection (GTM, GA4 `G-`, Google Ads `AW-`, Meta pixel) → "Ana sayfada ölçüm etiketi yok" (no GTM and no gtag) and "GA4 kimliği bağlı mülkle eşleşmiyor" (gtag without GTM, none of the bound property's measurement IDs); on the brand's first website: "GA4 veri almıyor" (collection ran in the last 2 days, no sessions for 3+ days, prior ≥ 20/day), "Web sitesi dönüşümleri durdu" (counted GA4 key events 0 for 3 days with sessions, prior ≥ 1/day), "Dönüşüm ölçülmüyor" (≥ 300 sessions / 30 days, nothing counted). Thresholds in `config/moxdop-alerts.php` `tracking`. GTM API is not used.
- Branded vs non-branded (Brand › Talep): six months of Search Console clicks and Google Ads cost/conversions split by the branded-query test (`BrandedQueryMatcher`: full brand name or domain root; shared with the demand builder). Search Console hides rare queries, so shares are of reported queries.
- GA4 landing page × channel: new central family `GA4_RF_LANDING_CHANNEL_DAILY` → `ga4_landing_channel_daily` (sessions, engaged sessions, active users, optional engagement rate / key events), 1-day slices; fills on the next GA4 collection.
- Sayfa Karnesi (website › Sayfa Karnesi tab): one row per page for the last 28 days — Google clicks (vs previous 28), GA4 sessions and key events, main channel and channel mix, Google Ads landing clicks/cost/conversions (tracking parameters stripped, other hosts ignored), indexing verdict, lab LCP, open SEO tasks; flags "Google dizininde değil", "Google tıkları düşüyor", "Ziyaret var, dönüşüm yok", "Reklam tıklıyor, dönüşmüyor", "Yavaş açılıyor". Missing sources show "—", never zero. Top 50 by traffic + search.
- Not done: the monthly report still has no conversions section (dictionary totals are ready to feed it); `google_ads_conversion_business_mappings` (Ads measurement panel stages) is not yet merged into the dictionary; Meta pixel IDs are detected but not compared with Meta's reported pixel; tag detection reads only the stored homepage (tags injected by GTM are not visible).

## 2026-09-28 — Faz 2b: talep hattı (sorgu → hizmet/bölge → bölge SERP → rakip sayfa → SEO görevi)

**State:** CODED + PHPUnit (`tests/Unit/SeoTasks/SeoTextMatchesPhraseTest`, `tests/Feature/Demand/BrandDemandBuilderTest`, `AreaSerpCheckerTest`, `CompetitorPageComparatorTest`). Full Feature + Unit suites compared with the baseline; new migrations run on PostgreSQL 16 locally. No live UAT; DataForSEO SERP location/organic responses are faked in tests.

- Matching: one Turkish fold (`SeoText::fold`; `LocationOptions::fold` delegates) and suffix-tolerant phrase matching (`SeoText::matchesPhrase`: implantı, fiyatları, estetiği; words < 4 letters exact) used by the service keyword matcher and the SEO task query matcher.
- Brand demand table (`brand_demand_queries`, gold, never deleted): weekly `moxdop:demand:build` (Mon 05:30, active customers) merges the brand's own Search Console queries, Google Ads search terms (cost/clicks/conversions) and Business Profile keywords (90 days / 3 months); assigns the most specific matching service, the service area named in the query (district before city), out-of-area and branded flags (full brand name / domain root), and a value score (`config/moxdop-demand.php`). Operator assignments are kept; unseen queries keep their row with zero window metrics. Brand › İşletme › Talep shows per-service demand and lets the owner assign unmatched queries.
- Area SERP (paid, opt-in per brand, admin): weekly `moxdop:demand:serp` (Mon 05:45) checks each service's top 3 non-branded, in-area queries at the brand's service-area locations (DataForSEO free TR location directory → `brand_service_areas.dataforseo_location_code`, fallback website market / Türkiye); same keyword + location reused for 28 days across brands; the brand's monthly USD cap (default 2) is never exceeded. Domains in the top 10 for ≥ 2 keywords become pending competitors (`area_serp` source). Brand › Talep shows our rank per keyword and area plus the top 3 domains; "Şimdi kontrol et" runs in the background.
- Competitor comparison (free): weekly `moxdop:demand:compare` (Mon 06:00) fetches our service page (ServicePageAssignment, else the ranking URL) and up to 4 top-5 outranking pages (approved competitors first, rejected ones never), measures words, H2, FAQ, schema types, price and place mentions, stores gaps in `demand_service_comparisons`; SEO Görevleri adds one `competitor-gap` task per service (high when not in the top 10).
- Not done: Google Ads keyword (criterion) texts are not imported (search terms are); GBP keywords are not yet in the automatic query-library import (they are in the brand demand table); the legacy Search Demand pages (clusters, enrichment, ownership, competitive intelligence) are untouched and should be retired or folded into Talep in the menu phase; SERP device is desktop only.

## 2026-09-27 (c) — Faz 2: portföy (aktif/pasif, Keşfet ve Grupla, tek rakip listesi)

**State:** CODED + PHPUnit (`tests/Feature/PassiveCustomerGateTest` switch, `tests/Feature/Portfolio/DiscoverAndGroupTest`, `tests/Feature/Portfolio/BrandCompetitorsTest`). Full Feature + Unit suites compared with the baseline. No live UAT.

- Customer list: active/passive switch per row (archived stays a badge). Passive stops all automatic flows (Faz 0 gate); reactivation makes accounts paused with `customer_passive` due immediately.
- "Keşfet ve Grupla" (`/customers/discover`, admin only, button on the customer list): unbound, bindable Google/Meta accounts grouped by domain (Search Console, GA4 cached web stream, Business Profile website) and by name (Google Ads, Meta; name score ≥ 0.8), manager accounts excluded. Groups matching an existing website asset bind to that brand. One click creates customer (or picks an existing one) + brand and applies the accounts through the "Otomatik kur" applier (website asset, GA4/SC on the website, new Ads/Meta/GBP assets); a site crawl is queued so "Otomatik kur" can propose services. No provider calls while grouping.
- One competitor list per brand: Brand › İşletme › Rakipler shows the competitor library (`search_demand_competitors`) with approve / "Rakip değil"; one-click suggestions from the brand card text, business-context known competitors and DataForSEO competitor domains; "Arama sonuçlarından bul" imports stored SERP results (no new provider call). Rejected competitors are never suggested again. Setup checklist gains an optional "Rakipler" item (≥ 3 approved).
- Faz 1 follow-ups: legacy WordPress application-password probe removed; `sample-module` removed via composer (old registry rows stay hidden); cross-asset consistency checks noted for Faz 7.

## 2026-09-27 (b) — Faz 1: temizlik + veri saklama

**State:** CODED + PHPUnit (`tests/Feature/Retention/DataRetentionTest`, `RecurringAutomationEngineProductionTest` retired kind, `MoxDopUiFoundationTest` admin resources; removed features' tests deleted). Full Feature + Unit suites compared with the baseline. No live UAT. About 67k lines removed.

- Removed (no operator consumer): agency-brain stack (IntelligenceEvaluation, SectorLearning, BrandExperiences, BusinessOutcomes, Assistant, IntelligenceMemory, IntelligenceRetrieval); team workflows (Client Requests, Approvals, QA, Playbooks, Recurring Reviews — Work/Tasks are task-only now); Instagram page (`/assets/instagram` redirects to the asset list; old rows still readable); dead AI (DiscoveryInference, IntentRadarService/IntentClassification, WebsiteFindingInsightAgent, GBP/GA4/GSC guidance keys); zero-reference demo fixtures; unrouted Domain/Hosting components.
- Filament `/admin` is technical tooling only (ADR-065): Runs, Modules, dashboard widgets, login/profile/MFA. Module AI guidance (Website/Google Ads/Meta Ads `src/Ai`, guidance jobs, skills) removed; click-only AI stays in Advisor drafts, SEO Görevleri, brand setup, WhatsApp, prospects.
- Monthly report no longer has a business-outcome section (the producer was removed); it still renders.
- Guarded drop migrations (`2026_09_27_100100_drop_team_workflow_tables`, `2026_09_27_120000_drop_agency_brain_tables`) drop a table only if it is empty; tables with rows (e.g. seeded playbooks) stay for review.
- Recurring occurrences of a retired schedule kind finish as Skipped (`KIND_RETIRED`) instead of failing.
- Retention (`moxdop:data:retention`, daily 04:10, `config/moxdop-retention.php`): raw payloads / HTML copies after 90 days (each page's latest HTML kept), telemetry windows (30–180 days, unprocessed WhatsApp receipts kept), daily performance older than 25 months rolled into `performance_monthly_rollups` (sums; ratios impression/session-weighted) then deleted. Gold daily tables (GSC query tables, Ads search terms/keywords) are never rolled up. DataForSEO keyword snapshots and GA4 event tables keep collecting (gold / Faz 3 inputs).
- Not done / follow-ups: cross-asset consistency jobs (7 `Analyze*ConsistencyJob`) have no trigger since Filament ViewDigitalAsset was removed; old WordPress application-password connection is Filament-only and orphaned; `sample-module` needs `composer update` to remove; Opportunities/Recommendations/Findings pages fold into the one work list in the menu phase.

## 2026-09-27 — Faz 0: AI tıkla, pasif müşteri kapısı, GBP saklama, yetki, 2FA

**State:** CODED + PHPUnit (`tests/Feature/SeoTasks/SeoPlanRunTest`, `tests/Feature/PassiveCustomerGateTest`, `tests/Feature/OperatorTwoFactorLoginTest`, `tests/Feature/OperatorAssetDataSourcesGuardsTest`). Full Feature + Unit suites compared with the baseline. No live UAT.

- SEO plan AI is opt-in: scheduled, bulk, brand-setup and "Planı yenile" runs are rules-only (`llm_summary.skipped_reason = not_requested`); the new "✨ Briefleri AI ile hazırla" button (`SeoTasksPanel::refreshPlanWithAi`, trigger `manual_ai`) runs site understanding + brief enrichment. A rules-only run never overwrites an earlier AI brief/title/checklist (evidence and score still refresh).
- WhatsApp automatic suggestions default off in the settings form (dispatcher already defaulted off); suggestions on click.
- Passive gate: `DigitalAsset::operational()` / `isOperational()` = asset active and customer active. Applied to scheduled SEO plans, advisor plans, daily alert scan, WordPress reconcile, account collection (`ResourceAutomationService::portfolioGate`: `customer_passive` when every bound asset is passive, `unbound` when no binding and no query-library sector) and automatic query import (passive only). Manual clicks on a passive customer's pages are not blocked.
- Scheduled SEO queue rotates least-recently-planned first (per-tick limit no longer always takes the first ids).
- GBP retention scheduled daily 04:40 (`moxdop:gbp:purge-expired`): reviews/media/posts/profile snapshots older than 30 days are deleted; `gbp_performance_daily` and `gbp_search_keywords_monthly` are no longer purged (owner: keyword/query data kept).
- Admin-only: AI route save, resource automation save/run/resume/close/recheck, Data Sources bind/unbind (controls hidden for team members).
- 2FA (authenticator app, TOTP) for `/login` and Filament `/admin` with one secret (`users.app_authentication_secret` encrypted, hashed recovery codes; Filament `AppAuthentication`, no new dependency). Setup/turn off/regenerate codes on Profil; login challenge `/two-factor-challenge` (5-minute pending login, code reuse rejected, throttled). Optional per user, not enforced.
- Not done: passive gate for report deliveries and recurring automations (`moxdop:dispatch-due-automations`), enforcing 2FA for admins.

## 2026-09-26 — Dijital varlık sayfaları Faz D: eksik analizler + uyarılar

**State:** CODED + PHPUnit (`tests/Feature/Alerts/AssetAlertScannerTest`, `tests/Feature/GoogleAds/GoogleAdsCampaignAnalyticsTest`, `tests/Feature/MetaAds/MetaAdsCampaignExplorerTest`, `tests/Feature/Website/WebsiteHealthScoreTest`). Full Feature + Unit suites: no new failures vs baseline. No live UAT; everything reads collected tables (no provider calls on render).

- Alerts (`asset_alerts`, `moxdop:alerts:scan` daily 06:30, `config/moxdop-alerts.php`, `MOXDOP_ALERTS_ENABLED`): Google Ads/Meta spend spike (≥2× 7-day avg) and delivery stop, Google Ads conversions stopped (3 days spend + 0 conv after ≥1/day), Search Console clicks −40% week over week, stale bound data (>72h), unanswered ≤2★ reviews (7 days). Upsert by key, auto-resolve. Shown in the asset frame, dashboard card and weekly digest. No push/SMS.
- Google Ads: campaign Δ% vs previous period (cost/clicks/conv/CPA), lost IS budget/rank (impression-weighted), sortable; monthly pacing (MTD, projected month-end, ±10% band, shared budgets counted once); CSV for campaigns and search terms. Conversions by action per campaign skipped: collected grain is account-level.
- Meta: campaign → ad set → ad drill-down with breadcrumb (URL state), impressions/CPM/results/cost per result by objective, sorting; creative fatigue (first vs last 7 active days: CTR −30% with rising or ≥1.8 daily frequency); CSV of the current list.
- Website: Site Sağlığı score 0–100 (weighted by share of pages with crawl issues per severity; formula in `WebsiteHealthScoreService`), trend of last 6 crawls, issue groups with affected URLs and "new since last crawl", 4xx/5xx, redirect chains, broken internal links, orphan pages, duplicate titles/descriptions (not scored); CSV.

## 2026-09-25 (d) — Dijital varlık sayfaları Faz C: ortak çerçeve, tek bağlama yolu, GA4/GSC modeli

**State:** CODED + PHPUnit (`tests/Feature/Assets/AssetContextTest`: frame on all seven asset page types + Data Sources + edit; GA4/GSC as website sources in the estate matrix and the legacy GA4 page hint; `CanonicalPortfolioRuntimeTest` creatable types). Full Feature + Unit suites compared with the baseline. No live UAT.

- Shared frame (`<x-operator.asset-context>`) above every asset page, Data Sources and the edit form: Müşteri › Marka › Varlık breadcrumb, switcher to the brand's other assets (with freshness dots) + "Varlık ekle", bound accounts, freshness/last data, "Veri Kaynakları" and "Düzenle". Duplicate sources/edit/brand buttons removed from channel headers.
- One binding path: Data Sources, Integrations pages and Brand setup already used `Confirm*ResourceBindingService`; the Filament website GA4/GSC action (direct row create) now uses it too.
- GA4 / Search Console are Website sources: not offered when creating assets; the estate matrix counts a website binding and links to the website tab; existing standalone GA4/GSC pages show a "web sitesinde aç" hint when the website has the same source bound.
- Intentionally kept: account-centric binding on Integrations pages (same service); Filament `/admin` performance/intelligence relation managers (technical panel, not operator product; removal would be a separate cleanup); per-channel period controls (GBP uses 28/90/180 days).

## 2026-09-25 (c) — Dijital varlık sayfaları Faz B: temizlik

**State:** CODED + PHPUnit (website/Google Ads/Meta/Instagram page tests, redirects for retired Meta pages, weighted impression share, KPI deltas, Turkish labels). Full Feature + Unit suites compared with the baseline. No live UAT.

- Website page 12 → 9 tabs (Genel Bakış, SEO Görevleri, Search Console, Google Analytics, Sayfalar & İçerik, Site Sağlığı, Standartlar, Altyapı & WordPress, Veri Kaynakları); old tab URLs mapped; overview lists up to 10 findings/recommendations; no raw JSON / English fallbacks; labels in `lang/*/operator_website.php`.
- Google Ads: Optimization tab folded into Danışman (Google recommendations below the advisor panel); overview shows each count once ("Dikkat gerektirenler"); KPI deltas vs previous period with Turkish text and dates; statuses Aktif/Duraklatıldı/Kaldırıldı; PMax/Shopping/Video headers Turkish; Scenario Planner placeholder removed; impression share impressions-weighted; dead tab views and component state removed.
- Meta: 9 legacy pages (campaigns/adsets/ads/creatives/breakdowns/insights + details) are redirects to the workspace tabs (route names kept); their Livewire classes/views deleted; dead filter/drawer state removed; YoY comparison not offered (reads compare with previous period); Bütçe column; whole-number results; jargon (destination_type, canonical lead action, raw freshness codes) replaced.
- Instagram: single honest page — "analitik bağlı değil" + collected profile fields when present; not offered when creating new assets.
- Shared: Data Sources/runtime wording without provider/binding/collection; discover buttons Admin-only; "Kamu Keşif" → "Açık Web Keşfi"; connector page links use the shared asset route map; asset list filter labels localized; unused GBP map script removed.
- Still open (Faz C/D): shared asset frame, one binding flow, GA4/GSC model, Filament duplicate performance views, measurement TR/EN twin views, `GoogleAdsSearchRecoveryCollectionService` (no callers).

## 2026-09-25 (b) — Dijital varlık sayfaları Faz A: kırık ve yanlış çalışan yerler

**State:** CODED + PHPUnit (`tests/Feature/Assets/*`: GBP page from collected gbp_* rows incl. partial run, retired tabs; asset list real status + edit form + Data Sources render; Google Ads landing tab + no provider call on Search render; Meta no auto-pick + recorded recommendations + run analysis). Full Feature + Unit suites compared with the baseline. Live UAT not run.

- Veri Kaynakları (`/assets/{id}/sources`) no longer 500s (mixed one-line / block `@php` in the Blade); breadcrumb links back to the asset; statuses in Turkish.
- Operator asset edit page `/assets/{id}/edit` (name, status, website fields, SEO market as DataForSEO codes); brand/type fixed. "Varlığı düzenle" menus now open it (was the create form). Create continues to Data Sources.
- Business Profile page reads `gbp_location_snapshots` + performance/keywords/reviews/media/posts/attributes/services by the bound resource; partial runs count. Tabs: Özet · Performans & Aramalar (28/90/180 days vs previous) · Yorumlar (distribution, reply rate, unanswered, latest) · Profil (completeness checklist) · Danışman. Visibility/Competitors/Operations placeholders removed (old links redirect); dead demo tab views deleted.
- Asset list: connection, freshness (fresh ≤72h / stale / not collected / not connected / no data source), last update and open work (advisor + SEO tasks) from bindings and runs; "Veri sorunları" filter works.
- Google Ads: "Açılış Sayfaları" tab reachable (panel existed but was mapped to Overview); Search tab no longer calls the Google Ads API while rendering (`MOXDOP_GADS_SEARCH_LIVE_FALLBACK`, default off); connector Accounts tab no longer also renders the activity monitor.
- Meta: `/assets/meta` without an id goes to the Meta-filtered asset list (no auto-picked account); İçgörüler tab lists recorded findings, advisor recommendations, tasks and measured outcomes; header "Analizi çalıştır".
- Not in this slice (Faz B–D): dead demo code cleanup, tab consolidation, English/jargon cleanup, shared asset frame, GA4/GSC model decision, new analytics (pacing, drill-down, alerts).

## 2026-09-25 — Faz 7: Admin onaylı harici yazma (ADR-064)

**State:** CODED + PHPUnit (`tests/Feature/ExternalWrites/ExternalWritesTest`: paste parsing, Admin-only UI + 403 for team members, Google Ads mutate sequence limited to shared list services, undo removes exactly the added criteria, kill switch, WordPress signed draft create/trash, old plugin refused, plugin source never publishes). Full Feature + Unit suites: no new failures. Live UAT not run — needs a real Google Ads account with an approved developer token (≥ Basic access) and the connector plugin updated to 1.2.0 on each site.

- Danışman → Google Ads "Negatif anahtar kelime listesi": Admin sees "Google Ads'e ekle…", edits the list, confirms; terms go to "MoxDOP negatifleri" shared list (existing ones skipped), list attached to enabled Search campaigns; item closes; status line + "Geri al".
- SEO Görevleri → brief: Admin "WordPress'e taslak gönder" → title + H2 skeleton + queries/links as comments, `post` (guide/faq) or `page` (service/location), never published; "Taslağı aç" link + "Geri al" (trash, only while still a draft).
- Connector plugin 1.2.0 (`connectors/wordpress/moxdop-connector`): draft endpoints; `status.capabilities = ['drafts']`; site owner can disable.
- Known limits: undo does not detach the shared list from campaigns or delete the (empty) list; WordPress draft is a skeleton, the writer fills the text.

## 2026-09-24 (d) — Advisor Faz 6: tek iş listesi, kanallar arası, ölçülen etki

**State:** CODED + PHPUnit (`tests/Feature/Advisor/AdvisorFaz6Test`: merged ranking + per-brand cap, dashboard card, brand block, cross-channel rules, SEO outcome measurement window + GSC clicks, report section text, unbound negative list, digest off/forced). Full Feature + Unit suites: no new failures. Live UAT not run.

- Dashboard: "Bu haftanın en önemli 5 işi" (SEO Görevleri + all advisor channels, max 2 per brand).
- Brand overview: "Danışman" block — one line per channel (Web / SEO, Google Ads, Meta Ads, İşletme Profili, Kanallar arası) with open/urgent counts and last run, then the brand's top 5.
- Cross-channel (`cross_channel`, on the website asset, weekly with the advisor): Google Ads search terms with ≥2 conversions and no site page / not in organic top 10; brand search paid while organic avg position ≤1.5 (a 2-week test suggestion); Business Profile searches (≥40 impressions) with no site page.
- `moxdop:advisor:measure` (Mon 07:30): done items 28 days later — negative lists (spend on listed terms before/after) and SEO tasks with a target page (GSC clicks before/after, 3-day lag); others recorded as done without a metric.
- Client report (value story, PDF, share view, composer): "Yapılanlar ve gözlenen etkisi"; wording is observation only, attribution/causation flags stay false.
- `moxdop:advisor:digest` (Mon 08:00): internal email of the top jobs to active admins; off unless `ADVISOR_DIGEST_ENABLED=true`.
- Not built: down-weighting rule types without measured effect (needs history).

## 2026-09-24 (c) — Advisor Faz 5: İşletme Profili danışmanı

**State:** CODED + PHPUnit (`tests/Feature/Advisor/GbpAdvisorRunTest`: all rules end-to-end, brand-search exclusion, keyword↔service matching, silence without binding/media, draft validation, profile page with advisor tab for a bound profile). Full Feature + Unit suites: no new failures; 2 previously failing GBP workspace tests now pass. Live UAT not run.

- Menu page renamed "Danışman" (Google Ads, Meta Ads, İşletme Profili); profile page gets a "Danışman" tab.
- Fixed: bound Business Profile page crashed (`GbpLocationBoundCollector::EVIDENCE_TYPE` was undefined).
- Rules (config `moxdop-advisor.gbp`): profile shown closed (critical); profile gaps (description missing/<250 chars, no extra categories, no hours, no phone, website missing/broken, empty service list, missing cover/logo, Google-updated info, unset attributes); search keywords whose service is not on the profile or not a brand service (3 months, ≥30 impressions, brand searches excluded); priority brand services missing from the profile; 28-day interaction drop ≥30%; rating trend (last 90 days vs prior year, −0.3); photo freshness only when the profile is used; UTM on the website link (paste-ready URL).
- AI: "Taslak hazırla" on profile gaps → 2 descriptions ≤750 chars + service descriptions; no links/phones.
- Known gaps: structured services carry only ids; review replies out of scope; ratings on automated runs come from `gbp_reviews` only.

## 2026-09-24 (b) — Advisor Faz 4: Meta Ads danışmanı

**State:** CODED + PHPUnit (`tests/Feature/Advisor/MetaAdsAdvisorRunTest`: collector result resolution, all rules end-to-end, self-closing, panel channel filter, draft queue/limits; Meta frozen tab list updated). Full Feature + Unit suites: no new failures. Live UAT not run.

- Same `/ads-advisor` page (channel switch: Tüm kanallar / Google Ads / Meta Ads) and a "Danışman" tab on the Meta account; weekly run covers both channels.
- Rules (config `moxdop-advisor.meta_ads`): creative fatigue (daily avg frequency ≥1.8 and link CTR −25% week over week), audience saturation (campaign daily avg frequency ≥2.5), low-result ad sets likely stuck in learning (<15 results/week, needed daily budget), conversion campaigns without results, pixel/conversion source health (unavailable, silent >3 days, ad set optimizing to unknown pixel, archived custom conversion), expensive placement/device/hour (account breakdown, click based), landing page crawl issues, cost-per-result jump after a change event.
- AI: "Taslak hazırla" on creative-fatigue → 3 concepts, primary texts ≤250, headlines ≤40, descriptions ≤30; copy-paste only.
- Known gaps: learning stage, weekly reach/frequency, audience size, per-campaign breakdowns and results by placement are not collected.

## 2026-09-24 — Advisor Faz 3: Google Ads danışmanı

**State:** CODED + PHPUnit (`tests/Feature/Advisor/*`: rule engine, end-to-end run/resolve/panel, quality-score collection guard, ad-copy limits). Full Feature suite: no new failures. Live UAT not run; quality score appears only after the next Google Ads collection.

- `/ads-advisor` (menu "Reklam Danışmanı") + Google Ads account tab "Danışman"; weekly `moxdop:advisor:plan --scheduled` (Mon 07:00), manual "Tüm hesapları incele" / per-account "İncele".
- Rules (config `moxdop-advisor.google_ads`): negative keyword list (existing negatives, brand and service terms excluded; single-word phrase negatives; paste-ready), converting terms not yet keywords, budget-limited profitable / zero-conversion campaigns, measurement (no primary, primary no signal, low-intent primary, lead MANY_PER_CLICK, auto-tagging off, Ads vs GA4 google/cpc), landing pages (website crawl status/redirect/noindex + Ads speed/mobile scores), weak RSA ad strength, account asset library gaps, filtered Google recommendations (budget/broad/bidding nudges excluded), low quality score, CPA jump after a change event.
- AI: "Taslak hazırla" on weak-ad-strength items → one budget-guarded call, headlines ≤30 / descriptions ≤90 enforced; copy-paste only.
- Known gaps: asset coverage is account-level (no per-campaign link); search-term match type and RSA text are not collected; outcome measurement 28 days after "Yapıldı" stores the baseline only (reporting in Faz 6).

## 2026-09-23 (i) — SEO Görevleri: page redesign and visible plan rebuild

**State:** CODED + PHPUnit (`SeoPlanRunTest` global panel assertions). Full Feature suite: no new failures. Live UAT not run.

- /seo-tasks opens with a "Haftalık plan" bar: schedule, last build, live "N site için plan kuruluyor" (5 s polling while plans run), primary "Tüm planları yenile" and a 3-step "Planı yenile ne yapar?" explainer.
- Site table lists every active website (customer filter applied) with plan state (Kuyrukta / Kuruluyor / Başarısız + error / last build), content progress, critical fixes, a per-row "Yenile" (`refreshSite`) and row click = site filter.
- Task list: type tabs with counts and a one-line meaning, filters in one toolbar (+ "Filtreleri temizle"), ranked cards with a type colour accent, clamped reason, explicit "Brief ve adımlar" toggle. Setup cards grouped under "Önce bunları yanıtla".

## 2026-09-23 (h) — SEO Görevleri: false positives from the first Faz 2 review

**State:** CODED + PHPUnit (`SeoTaskRuleEngineTest` regression test). Full Feature suite: no new failures. Live UAT not run.

- Pages whose canonical points to another URL are no longer "indexable": parameter URLs (`?pg_client=`, `?elementor_snippet=`…) canonicalized to their clean page no longer inflate canonical/H1/meta/thin counts. `canonical-conflict` only fires for clean (no query string) URLs.
- `thin-content` ignores 0-word pages (text not extracted); when ≥95% of ≥20 pages are thin, the task becomes a low-severity "verify measurement first" card, not a template task.
- Site understanding no longer adopts article titles ("… Nedir?", "… Nasıl …", guide) as inferred services.

## 2026-09-23 (g) — Advisor Faz 2: web depth rules

**State:** CODED + PHPUnit (`SeoTaskRuleEngineTest` depth tests, `SeoDepthInputCollectorTest`, `SeoUrlInspectionQueueTest`, panel render test). Full Feature suite: no new failures. Live UAT not run.

- Collector reads already collected, previously unused data: `gsc_page_daily` (28/28-day and 90-day windows, history length), latest `gsc_url_inspection_snapshot`, latest `gsc_sitemap_snapshot`, latest-crawl `website_link_edge` graph, `website_performance_measurement`, the brand's single connected `gbp_location_snapshots`; stored HTML adds the first paragraph after H1 and `tel:` numbers.
- 11 rules (see `docs/product/SEO_TASKS.md` → Faz 2), all silent without their data; pruning is one decision card per site.
- `SeoUrlInspectionQueue`: each plan run sends important pages without a fresh (<14 days) inspection to GSC URL inspection (≤20, one run per site per ISO week, system trigger). First time URL inspection is used in production flow.
- GPTBot added to AI crawler checks; AI visibility quota 3 → 4.
- Known gaps: PageSpeed still measures one URL per site (service pages unmeasured); pruning cannot see publish dates, so a brand-new page can appear (the card says to keep it); Person/author detection only covers pages whose HTML was read (≤150).

## 2026-09-23 (f) — Matching expressions, GBP keyword import, brand/customer screens

**State:** CODED + PHPUnit (`tests/Feature/Portfolio/BrandWorkspaceTest.php`, `tests/Feature/SearchDemand/GbpLibraryImportTest.php`, `BrandSetupAssistantTest`). Full Feature suite: no new failures versus the previous head (147 pre-existing failures unchanged). Live UAT not run.

- "Otomatik kur" proposes location-free, service-specific matching expressions and appends them to the catalog service (`ServiceKeywordService::append`, generic words rejected centrally), so later imports auto-assign.
- Query import source `google_business_profile` reads already-collected `gbp_search_keywords_monthly` (month-based range).
- Brand page rebuilt (`App\Livewire\Operator\Portfolio\BrandShow`, `BrandWorkspaceReadService`): 5 tabs, setup checklist, real connection state and last sync; legacy `Demo\Portfolio\BrandShow` (session-shaped, fixture sections) removed. Reports moved into `InteractsWithBrandReports`.
- `OperatorPortfolioPresenter` derives connection state and `connected_assets` from active bindings (was hardcoded "not configured").
- Customer page: 3 tabs, brands with setup state, contacts inline; contact role now survives edit.
- Brand create takes an optional website and opens "Otomatik kur" with the proposal started. The `/setup` portfolio wizard (placeholder account steps) was removed with owner approval (route, component, view, session helpers, lang keys, wizard-only tests); `/setup` now returns 404.
- Playwright specs `tests/e2e/02, 04, 05, 09, 10` and report scripts updated to the new screens (stable hooks: tablist `Marka`, `[data-asset-row]`, customer `⋯` menu). Not executed here: the harness is pinned to `/workspace` and branch `cursor/production-readiness-audit-ea01`.

## 2026-09-23 (e) — Location-free services/keywords, out-of-area demand

**State:** CODED + PHPUnit (`BrandSetupAssistantTest`, `SeoTaskRuleEngineTest`). Triggered by the first live "Otomatik kur" run (busranurozger.com, İstanbul brand): aliases such as "jinekomasti ankara" and Ankara queries.

- `LocationOptions::describe/withinAreas/classify`: location readings (country/city/district) judged against the brand's service areas.
- "Otomatik kur": service names and aliases are stripped of locations; each service gets location-free, non-branded Search Console keywords; on approval they are stored in the query library (sector → service → query, source `search_console`) and inherited into the brand's query portfolio. The page reports out-of-area locations (or asks for service areas when none exist).
- SEO Görevleri: out-of-area queries are excluded from content buckets; one `out-of-area-demand` question card per site ("Hizmet verdiği yerlere ekle" / "Hizmet vermiyorum").
- Known gap: district names are matched from the national list; a district that is also an ordinary word can be misread.

## 2026-09-23 (d) — Advisor Faz 1: AI cost control, free providers, brand "Otomatik kur"

**State:** CODED + PHPUnit (`tests/Feature/AiControl/AiCostControlTest.php`, `tests/Feature/BrandSetup/BrandSetupAssistantTest.php`). Live UAT with real Groq/OpenRouter keys, real Google discovery (GA4 data streams) and a real brand approval not run. Roadmap: `docs/product/ADVISOR_ROADMAP.md`.

- Groq and OpenRouter API-key providers under Integrations → AI providers (connection check; OpenRouter check endpoint unverified live).
- Every `laravel/ai` call is recorded in `ai_usage_records` (tokens, estimated USD via `config/moxdop-ai-pricing.php`). Monthly budget (default 25 USD, editable on AI Control Plane): when exhausted only known-zero-price models run; plans continue with rule results.
- AI routes carry a client-data flag; free/third-party tiers are rejected on client-data routes. Default steps: analysis Sonnet 5, classification Haiku 4.5, public data Groq, Gemini as fallback.
- Brand "Otomatik kur" (`/brands/{brand}/setup`): queued proposal (website asset, GSC by domain, GA4 by data-stream URL, GBP by website/name, Ads/Meta by name, services via one AI call on route `brand_setup.assistant`), single admin approval applies selected items through existing binding/catalog/offering services. No external writes.
- Known gaps: pre-existing failures in `OperatorAssetDataSourcesGuardsTest` (blade syntax in `asset-data-sources.blade.php`) and `ModuleBoundaryArchitectureTest` allowlist are unrelated and unchanged.

## 2026-09-23 (c) — SEO Görevleri: first real-data review fixes and page redesign

**State:** CODED + PHPUnit (`tests/Feature/SeoTasks/*`, 15 tests / 143 assertions). Triggered by the first staging run: "missing title" listed 2,226 URLs on moximu.com (robots.txt, sitemaps, feeds) and 22 per-service question cards flooded the list.

- Non-HTML URLs are excluded from the inventory; head/H1 facts are judged only when observed (missing ≠ not collected). Fix lists are ordered by search traffic and template-level issues are flagged.
- Service ↔ page matching rescored (title/H1, URL, GSC share, article penalty, margin rule); only starred services are asked, in one grouped card per site; no "create service page" while candidates wait.
- Brand services that do not fit a website switch that website to site understanding (reason `brand_services_not_on_site`).
- `/seo-tasks` redesigned: KPI strip, per-site overview, setup cards, Turkish labels, impact/effort, brief copy, Turkish pagination.
- Existing staging rows from the first run become `stale` automatically on the next plan run (task keys changed).

## 2026-09-23 (b) — SEO Görevleri: stored HTML rules and site understanding

**State:** CODED + PHPUnit (`tests/Feature/SeoTasks/*`, 12 tests / 121 assertions). Live UAT with real stored HTML, real Anthropic calls and real no-service Brands not run.

- Stored HTML is read through the Website module (`StoredPageReader::html()`, new; `read()` unchanged) via the allowlisted adapter `App\Services\SeoTasks\SeoStoredHtmlReader`. New rules: duplicate H1, missing image alt on service pages, Organization schema without `sameAs`. HTML-based H1 check replaces the profile heuristic when HTML exists.
- Brands without services: `SeoSiteUnderstanding` infers services from stored pages + GSC + GA4 (AI route `seo_tasks.site_understanding`, 28-day reuse, rule-based page-topic fallback). Inferred services are plan-local; operator adopts them from the website SEO tab ("Markaya ekle").
- Anthropic key: existing `/integrations/anthropic` page is the entry point; no new integration field was needed. LLM calls now use a 120 s timeout instead of the 20 s provider default.

## 2026-09-23 — SEO Görevleri (staging branch)

**State:** CODED + PHPUnit (`tests/Feature/SeoTasks/*`, 9 tests / 90 assertions). Live operator UAT with real GSC/WordPress data, real Anthropic call and the weekly scheduler on staging are **not** run. Spec: `docs/product/SEO_TASKS.md`.

- New tables `seo_plans`, `seo_tasks`, `service_page_assignments`; `brand_offerings.is_priority` (backfilled from `priority_rank`).
- Engine `App\Services\SeoTasks\*` (collector → rules → optional single LLM call → diff writer), job `RunSeoPlanJob`, command `moxdop:seo:plan`, weekly schedule `seo-tasks-weekly-plan`, AI route `seo_tasks.content_planner` (Anthropic primary, OpenAI fallback).
- Operator UI: `/seo-tasks` (menu item), website asset tab `SEO Görevleri`, brand offering ★ toggle.
- Guarantee: at least 4 `create` tasks per site per run whenever the brand has offerings (GSC-backed first, library/fallback briefs otherwise, marked as such).
- Not touched: Task / Finding / Recommendation tables, clustering, collection, WordPress plugin.
- Known gaps: duplicate H1 and image alt rules need HTML reads (not produced); `sameAs` content not verified; queue/Horizon behaviour of the 600 s job only exercised with the sync driver.

## Account admission starvation and GA4 empty landing values — 2026-09-12

Operator supplied post-deploy GA4/Ads HTML: automatic job #86 is active, but Ads shows 56
eligible accounts, two with historical facts and zero active Ads transfers. GA4 exposes a
PERSISTENCE rejection for an empty `landingPage`. HTML alone does not reveal all scheduler,
queue or account rows, so live root-cause closure is not claimed.

Fixed source-level blockers: account admission now mirrors the existing dedicated Google Ads
worker and shared non-Ads worker, with two active/planning accounts per lane (four total by
default). Each lane selects its own due candidates, so a GA4/GSC backlog cannot exhaust Ads
admission or hide Ads behind the per-tick limit. Active/planning IDs are deduplicated. Provider
worker concurrency, quotas, pauses, authentication checks and MCC exclusion remain authoritative.

GA4 landingPage empty strings remain exact provider values, distinct from `(not set)` and `/`.
The effective storage overlay explicitly allows empty text only for the landingPage dimension
in landing-page daily/event-landing daily datasets. Null/missing scope keys remain invalid.
Malformed dimension positions/types fail normalization, with no checkpoint advance.

The existing automation settings/status panel is now visible on Ads, GA4 and GSC connector
pages. Staging deployment invokes due admissions and selectively rearms enabled automations
stopped by this exact historical landing-key rejection. Scheduled ticks do not blanket-reset
attention states; paused/revoked accounts are excluded from the repair.

Verification: regression tests cover both directions of worker-lane isolation, exact failure
recovery without unpausing accounts, empty landing preservation and strict missing keys.
PHP/Pint remain unavailable; targeted PHPUnit/Pint commands could not execute. `git diff --check`
and `bash -n deploy/staging/deploy.sh` pass. Live staging/provider/UAT acceptance remains pending.

## Collection visibility and interrupted workers — 2026-09-12

Staging branch `chatgpt/search-demand-foundation`: the header lists global active jobs with
site/account, manual/automatic origin, queue/retry/stalled state, completed dataset count and
last dataset activity. It no longer presents an equal-weight aggregate as a crawl percentage.
The detail panel links to existing background operations. Successful website console counters
are explicitly dataset completion, not URL coverage. Fresh running work takes precedence over
old queued dependents when classifying stalled work.

Scheduled collection recovery now reclaims running datasets only after both activity and the
execution lease expire (at least 30 minutes). Checkpoints and completed datasets are retained;
three resumes at an unchanged checkpoint are allowed, then the dataset fails visibly. Failed
prerequisites settle their queued dependents; terminal children reconcile unfinished parents.
An executor returning after its lease was replaced cannot advance that run's checkpoint/state.
Delayed continuations persist `retry_at`, so database workers and early queue deliveries respect
provider backoff. Redispatch alone no longer records fictitious dataset progress.

WordPress reconciliation reuses a recent completed full/manual inventory with all five WP
datasets complete, without advancing pending content-event cursors. Access-role-only events
do not trigger another full inventory. Daily/three-day CMS inventory and bounded event refresh
remain; general public crawl and PageSpeed still require explicit collection. Cross-run HTTP
conditional revalidation and adaptive URL scheduling are not implemented by this change.

Verification: focused PHPUnit regressions added for recovery bounds, live leases, dependencies,
orphan parents, delayed continuation, header visibility/stall classification, inventory reuse
and access-only events. `git diff --check` passes. PHPUnit could not execute (`php` unavailable);
Pint could not execute (no vendor installation). Live provider, worker and operator UAT remain
unverified. No schema or connector package change is required.

## Automatic collection recovery — 2026-09-11

Source fixes on `chatgpt/search-demand-foundation`: paired WordPress connections initialize
inventory scheduling without an incoming heartbeat (including existing installations on the next
tick). Signed status refresh updates the installed version without inventing event receipts.
Full inventories work with V1; changed-object scopes still require 1.1.0. Pause/cursors persist.

New discovered accounts become due immediately under the existing two-slot bound. Old unused
initial delays are recovered, while pauses and error backoff remain. Terminal parents no longer
consume slots through stale resource rows; Google Ads repairs their unfinished datasets. A new
planner clears its prior run reference so the scheduler cannot mistake a previous failure for
the new attempt. Missing run references use bounded retry. Staging planning jobs target the
existing Redis/Horizon worker; non-durable queue configuration fails explicitly. Meta/GBP resume
after the required real binding appears; unbound collection is still unsupported and no binding
is invented. This is not a claim that all discovered Meta accounts can collect without mapping.

Verification: regression tests added for capacity, initial delays, paused accounts, binding recovery,
missing/previous runs, sink rejection, WordPress bootstrap and preserved cursors. `git diff --check`
passes. PHP, Composer dependencies and Pint are unavailable here; PHPUnit/Pint could not execute.
Live scheduler/worker/provider UAT and deployment remain unverified. No migration or plugin ZIP
update is required for these server-side fixes.

> Manual query clusters, 2026-09-09: source-reviewed staging implementation only. No tests, migrations, queue, build or UAT executed.

> **Canonical product capability truth table for MoxDOP.**  
> Public Discovery Stage 1 update: 2026-09-07, `chatgpt/search-demand-foundation`. 70 targeted tests / 365 assertions passed; Pint, frontend build and Blade compilation passed. Real staging PostgreSQL, worker operation and operator UAT remain unclaimed. Contract: `docs/product/DISCOVERY_INTELLIGENCE.md` (ADR-061).
> Previous Website standards update: 2026-09-06, staging work branch `chatgpt/search-demand-foundation`; main is not evaluated by this update. No PR/merge is part of this task.
> Website verification: 63 targeted tests / 851 assertions, 39 PHP syntax checks, Pint, frontend build, Blade compilation and authenticated route checks passed. Full-suite, live-model and staging PostgreSQL/operator UAT remain unclaimed; details: `docs/product/website/WEBSITE_STANDARDS_ASSESSMENT.md`.
> Do **not** treat “IMPLEMENTED V1” in older docs as Definition-of-Done **DONE**.  
> Persistent product direction: `PROJECT_MEMORY.md`.  
> Async operator standard: `OPERATOR_ASYNC_EXECUTION.md`.

## How to read this ledger

| Column | Meaning |
| --- | --- |
| **Code** | Meaningful product code present; explicit `(branch)` rows apply only to the named work branch, never implicitly to main |
| **Automated Tests** | PHPUnit coverage for the recorded capability slice on its named branch |
| **Real UAT** | Real provider / operator UAT explicitly claimed in canonical docs |
| **Operator UX** | Filament / operator surface usable for the slice |
| **Background-ready** | Long-running operator flows actually queue and return control (not merely Job classes existing) |
| **State** | Ledger state — see `PROJECT_MEMORY.md` Definition of Done |
| **Known blocker / debt** | Explicit gaps |
| **Canonical notes** | Scope boundaries and pointers |

**States used:** `PLANNED` · `IMPLEMENTING` · `CODE COMPLETE` · `TESTED` · `UAT REQUIRED` · `UAT PASS` · `PARTIAL` · `BLOCKED` · `DONE`

**Inspection rules used for this snapshot:**

- Unmerged PR code is **not** main.
- Job classes implementing `ShouldQueue` without operator `dispatch` ≠ background-ready.
- Filament actions that call `(new SomeJob(...))->handle(...)` or inline services are **synchronous**.
- “IMPLEMENTED V1” in product docs = version label / scoped slice, not automatic **DONE**.

---

## Capability ledger

| Capability | Code | Automated Tests | Real UAT | Operator UX | Background-ready | State | Known blocker / debt | Canonical notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Manual service query clusters (four-step operator roadmap) | YES (staging branch) | NO — operator instruction | NO | Bilingual tree/table, filters, bulk selection/move, central rename, child CRUD/merge, receipts/undo, CSV, Website target plans | YES — 250-row queued operations + durable progress/Activity; confirmation snapshot is one synchronous SQL statement | **CODE COMPLETE / UAT REQUIRED** | No runtime, migration, queue, load or visual validation; 100k throughput unmeasured. Existing import caps unchanged. No automatic export/receipt retention. Legacy Brand AI clusters preserved separately; downstream bridging is later scope. | Global service is root; one child level on existing query-service associations; no AI or DataForSEO call. Snapshot/revision guards protect subsequent changes. Source and scope: docs/product/SEARCH_DEMAND_INTELLIGENCE.md, manual-clusters section. |
| Customer / Brand management | YES | YES | NO | YES | N/A | TESTED | Formal real-operator UAT not recorded as PASS | Operator `/customers` `/brands`; Filament `/admin` technical CRUD |
| Digital Assets | YES | YES | NO | YES | PARTIAL | TESTED | Long actions migrated to queue; short cross-asset checks still sync | Operator `/assets`; types include website, google_ads, gbp, meta_ads, instagram |
| MoxDOP Intelligence Core | YES (branch) | NO | NO | N/A | N/A | **CODE COMPLETE** | Tests and live UAT intentionally not run. Formula-to-Evidence consumers and additional provider adapters remain later milestones. | ADR-046/047; versioned registry + capability/metric contracts + Page/Search Term/Entity/Business Action identities and provenance aliases. Provider fact tables stay canonical; existing Formula/Evidence/Finding pipeline is reused. |
| Website Intelligence Projection | YES (branch) | NO | NO | PARTIAL | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Pages & Content, Technical Health, Infrastructure & WordPress and Data Sources consume projection profiles; Search Console and Google Analytics specialist drill-ins remain directly available. Remaining cross-source Website tabs do not yet consume their target projections. Live projection backfill and provider/collection UAT are required. DataForSEO, GBP and AI Search adapters remain later slices. | Rebuildable 90-complete-day Page/Search Term/Entity/Outcome profiles over Website public facts, authenticated WordPress, bound GSC and bound GA4. GSC page/query period grains and GA4 landing/event period grains are explicit contracts. External source keys longer than the storage boundary use a deterministic SHA-256 key while raw identity stays in canonical facts/aliases; failed source cards expose only safe run/error-class references. Queued and synchronous rebuilds share an asset-scoped lock to prevent concurrent identity writes. Source-keyed typed states retain period, coverage, value state and provenance. Collection completion queues a rebuild; `intelligence:website-projection:rebuild` supports backfill. No generic metric warehouse, magic score, AI Finding or provider write. |
| Website Pages & Content workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run per operator request. Requires deployed projection backfill, two post-deploy public HTML observations for semantic comparison, and operator review with real Website/WordPress/GSC/GA4 coverage. Remaining Website tabs are separate phases. | Compact projection-backed Page inventory with reconciled public/CMS/platform scopes, saved operator views, deterministic pagination Page families, source-aware missing states, meaningful-vs-raw HTML change separation, mobile cards and a right-side detail drawer. Recent stored HTML versions remain authenticated, checksum-verified and non-executable plain text. Missing remains distinct from zero; the screen presents facts, not Findings. |
| Website Technical Health workspace | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires real public crawl, targeted verification, TLS and optional PageSpeed operator review. Deterministic observations are not yet promoted to Findings in this slice. | Projection-backed HTTP reachability, redirects, crawl observation severity/counts, document-head and schema facts, TLS certificate state, PageSpeed lab LCP coverage, responsive page filters/details and source-record provenance. `Verify fix` queues the selected URL plus at most 99 same-issue URLs in the same pagination family; each URL is independently fetched, versioned and only clears when the current observation disappears. It never manually resolves observations or expands into a full crawl. No opaque health score; missing is not zero; interpretation remains in Improvements. |
| Website Infrastructure & WordPress workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires projection rebuild and operator review with a real paired WordPress site. Site Health and update facts remain observations, not Findings. | Entity-projection-backed WordPress/PHP/runtime facts, safe settings, active theme, plugin/theme inventory and updates, taxonomy/feature/SEO-provider summaries, connector state and separate external TLS/Website configuration. Credentials are never exposed; missing remains distinct from zero. |
| Website Data Sources workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires operator review against real Public Website, WordPress, PageSpeed, GSC and GA4 source states. DataForSEO, GBP and AI Search cards are intentionally deferred until source adapters exist. | Projection coverage and canonical connection health are presented separately for Public Website, WordPress Connector, PageSpeed, confirmed GSC and confirmed GA4. Shows source readiness, collected-data state, watermark, honest coverage counts, Website profile contribution and links to canonical management/collection screens without duplicating raw dataset tables. |
| Canonical operator URL architecture (ADR-044) | YES | YES | NO | YES | N/A | **TESTED** | Formal live host UAT not claimed on this PR | Operator product at `/` `/login` `/customers` `/brands` `/assets` `/integrations` `/tasks`; Filament `/admin` only; legacy `/app` `/system` → 410 |
| Google central Integration | YES | YES | NO | YES | NO | TESTED | Live OAuth requires external Google Cloud console; resource refresh sync | Agency Google Integration; ADR-039/040; Prompt 13+14 |
| Frozen Google Integration UI (backend state) | YES | YES | NO | YES | N/A | **TESTED** | Discovery/bind UX still PARTIAL (Prompts 15–16); connector pages still Demo | `GoogleIntegrationReadModel` + `GoogleConnectorRegistry`; docs: `GOOGLE_INTEGRATION_ARCHITECTURE.md` |
| Google OAuth & credential lifecycle | YES | YES | NO | YES | YES | **TESTED** | External Google Cloud verification/approval MANUAL; no live OAuth in CI | `GoogleOAuthService` + `GoogleCredentialBroker` + attempt store; docs: `GOOGLE_OAUTH_CREDENTIAL_LIFECYCLE.md` |
| Google resource discovery (GA4/GSC/Ads/GBP) | YES | YES | NO | YES | PARTIAL | **TESTED** | GBP/Ads external API access MANUAL; discovery sync on operator action; no auto bind | `DiscoverGoogleResourcesService` + four discoverers; operator Data Sources refresh is Admin-gated before any provider call. Docs: `GOOGLE_RESOURCE_DISCOVERY.md` |
| Google resource selection & asset binding | YES | YES | NO | YES | N/A | **TESTED** | Human confirmation required; no collection side effect; Filament `/admin` + operator Data Sources share Confirm* guards; replacement preserves binding identity | `ConfirmGoogleResourceBindingService` (+ Meta equivalent); docs: `GOOGLE_RESOURCE_SELECTION_BINDING.md` |
| Google resource discovery / binding | YES | YES | NO | YES | NO | TESTED | Refresh resources runs in-request; frozen bind workflow Prompt 16 | ExternalResources + AssetBinding |
| Google live collection | YES | YES | NO | YES | YES | TESTED | Async via Activity Center / database queue; real Ads UAT not re-run here | Operator Collect Now / Collect live data for GA4/GSC/Google Ads uses Collection Engine (`ExecuteCollectionLifecycleService::runNow` → `CollectionRun` / warehouse), not BoundCollector Evidence summaries. GBP remains BoundCollectorRegistry. Queued `CollectLiveBoundDataJob` still wraps the operator trigger. |
| Google Ads Intelligence | YES | YES | NO | YES | YES | TESTED | Collect + AI guidance queued; Expert Workspace not redesigned | Module Findings + Analyst + Skills; docs say IMPLEMENTED V1 |
| Website collection | YES | YES | NO | YES | YES | TESTED | Refresh data + diagnosis queued | GSC/GA4 + diagnosis probes; distinct from public Discovery |
| Website Intelligence | YES | YES | NO | YES | YES | TESTED | SEO refresh queued when provider work needed; fresh cache stays sync | Workspace V2A, SEO Light, AI guidance. Period presets: Demo catalog/fixtures stay on `DemoPeriod::ANCHOR_DATE`; real operator/provider reads use wall-clock / `OperatorPeriod` (not `APP_ENV`). |
| Public Website Discovery — stored Stage 1 | YES (branch) | YES — 70 targeted tests / 365 assertions including regression checks | NO | YES — TR/EN review and Integration handoff | YES — existing collection + resume + missed-event recovery | **TESTED / UAT REQUIRED** | Real PostgreSQL, queue and representative Website/operator UAT remain required. Deterministic extraction can miss unstructured services. SERP, reviews, social content and continuous monitoring remain later stages. | Reads current verified stored HTML; refreshes gaps via existing public collector. Original source dates, coverage/gap examples, identity-safe dedupe, edit/map/ignore, canonical service/area/competitor receipts and social profile handoff. No basic AI/paid calls; preserves legacy decisions. ADR-061 / `docs/product/DISCOVERY_INTELLIGENCE.md`. |
| WordPress Connector V1 | YES (branch) | YES (pending gate) | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Live disposable WordPress install, pairing, signed status/snapshot, five connector datasets, versioned public HTML collection and connector↔public parity UAT are still required. Production deploy is not claimed. | Installable read-only plugin; one-time pairing; encrypted credentials; HMAC request/response; site/content/media/taxonomy/extensions/SEO snapshots. Public Discovery remains active on paired WordPress Websites. Final visitor HTML is stored separately per URL as content-addressed compressed artifacts with current/previous hashes and explicit change state. Website integration separates discovered URLs from HTML coverage and keeps interpretation in Website analysis. |
| Brand Context | YES | YES | NO | YES | N/A | TESTED | Discovery proposes candidates; humans approve | `BrandIntelligenceContext` operator-owned facts |
| Global Service Catalog + Brand Service Areas | YES (branch) | PARTIAL — Discovery transfer/preservation covered | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Full catalog and Brand-edit workflow tests were not run in this slice. City/district values are operator-entered in this slice; a maintained geography reference import remains later. | Stable agency-wide Service IDs + aliases link to existing Brand Offering IDs. Brand form captures services, explicit priority and multiple country/city/district areas without creating service × area jobs. Structured Brand Context receives compatibility projections. |
| Search Query Library | YES (branch) | NO | NO | YES | NO | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Import runs synchronously and is bounded by upload/row limits. Brand Query Portfolio, SERP validation and URL ownership are later phases. | Manual, pasted, CSV/TSV/TXT/XLSX queries with TR-safe normalization, service association, market/language, approved semantic fields, branded exclusion state, source provenance and optional Ads/GSC/DataForSEO metrics. Missing metrics stay missing. |
| AI Search Demand Librarian | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires configured AI Integration plus queue worker and operator review. It has no provider-spend, Finding, Task, CMS or external-write capability. | Search Intelligence Analyst + generation/classification Skills; structured query, alias, family, intent, problem, stage, location, branded-suspicion and future cluster candidates. Persistent confidence/abstention/provenance; exact agent/Skill/route/input fingerprint reuse; bulk edit/approve/reject gate. |
| Brand Query Portfolio | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Global-promotion submissions need a later library-review workflow; no provider call is triggered. | Relational global-query inheritance from active Brand services, Brand-only queries, explicit Brand overrides/exclusion, dynamic multi-area `{location}` rendering, canonical `IntelligenceSearchTermIdentity` resolution and per-Website activation. No copied global query text or persistent Service × Area expansion. |
| AI Search Demand Clustering | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires configured AI Integration, queue worker and human review. No SERP evidence is consumed in this phase, so approval remains `ai_prediction`; locked clusters reject mutation. | Three-layer demand-family/SERP-intent/content-target clusters, representative query, content-type suggestion, confidence/rationale/uncertainty, incremental new-query runs, reviewable move/merge/split proposals, manual controls and immutable version snapshots. Exact Agent/Skill/route/input reuse; no automatic Finding, Task, content or external write. |
| Search Demand Query–URL Visibility Map | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Reads only website-active portfolio items. GSC/GA4 binding and requested-period coverage may be unavailable; provider row limits apply. Missing means unknown, never zero. | Period/comparison GSC query–URL performance joined to canonical Website Page profiles, observed HTTP/robots/HTML state and page-grain GA4 landing behavior. Query/cluster/service/area/observed filters, cluster summary, query detail and URL detail; no new metrics warehouse or query-level GA4 attribution. |
| Search Demand DataForSEO / SERP Enrichment | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires active DataForSEO credentials, configured Website SEO market/language, queue worker and explicit paid consent. Live endpoint shape, reported cost and provider UAT remain unverified. An unresolved paid attempt fails closed as `CHARGE_UNKNOWN` and is never auto-retried. | Provider-neutral adapter; service/cluster-scoped max-20 batch; desktop/mobile first 10/20 organic results, SERP features and observed Brand rank; separately labelled provider-estimated volume/CPC/competition/monthly trend; exact fingerprint freshness reuse and paid locks. Optional Keyword Ideas remain human-reviewed portfolio candidates. SERP-overlap cluster status is a persisted recommendation applied only by operator approval. No auto Brand call, URL ownership, competitor library, Finding, Task or external write. |
| Search Demand URL Ownership / Page Relevance | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a rebuilt Website Page Projection, observed page language/HTTP/head facts for the fail-closed technical gate, configured AI Integration for semantic review and a queue worker. Real GSC/SERP/operator UAT is required. | One versioned human URL-owner decision per Website + content-target cluster; bounded Page candidates from existing projection, separate GSC/SERP/current-owner/term-match provenance, deterministic technical gate and two-period wrong-URL/cannibalization candidacy. Page Relevance AI can only propose an eligible URL or abstain. Approval rechecks live eligibility; lock is human-controlled. No automatic redirect, delete, merge, page creation, Finding, Recommendation, Task, provider spend or external write. |
| Search Demand Competitor Library / Discovery | YES (branch) | PARTIAL — reviewed Discovery handoff/preservation covered | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Full stored SERP import and Library workflows were not exercised in this slice. Real stored SERP/domain observations and operator review require UAT. Import is synchronous and bounded to 100 distinct domains; it performs no provider request. | Brand-scoped normalized competitor domains with pending/approved/rejected lifecycle; independent commercial/SERP/content roles and business/directory/platform/authority kind; append-only source provenance, observed URLs/queries and service/area/cluster relations. Manual approved entry plus individual/bulk candidate review. Stored DataForSEO facts can establish SERP candidacy only, never automatic commercial competition. No crawl, AI analysis, Finding, Recommendation, Task or external write. |
| Search Demand Competitor Page Collection | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a queue worker, public DNS/HTTP access and approved competitors linked to a content-target cluster. Live-site response behavior and operator UAT are unverified. | Deterministic URL-hash dedupe; max 3 URLs per competitor / 20 per run; exact-URL-only SSRF-safe Public Discovery fetch with no link following; normalized text, title/meta/H1–H6, schema, bounded links and service/location expression observations. Raw/content fingerprints append history and reuse unchanged content without duplicate parsing/storage. No AI, Finding, Recommendation, Task, provider spend or external write. |
| Search Demand Competitive Intelligence | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a verified URL owner with stored checksum-valid Website HTML, successful Phase 10 observations, configured AI Integration and queue worker. Real operator/model UAT remains unverified. | Dedicated Competitive Intelligence Analyst + Skill + route; exact-fingerprint reuse; bounded stored-evidence comparison over max 8 competitor pages; proposed competitor kind/roles, page intent, topics/questions, structure, local trust, missing user needs, do-not-copy cautions and differentiation. Canonical Activity plus separate run/page proposal records and human accept/reject. Review never mutates competitor truth or URL ownership; no browsing, Finding, Recommendation, Task, publication or external write. |
| Search Demand Finding / Recommendation Planning | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a verified URL owner and readable own-page HTML, enabled standards, configured AI Integration and queue worker. Phase 11 context is optional (ADR-060); see tested Website standards rows below. Migration, queue/model behavior and operator promotion require staging UAT. | Deterministic technical and evidence-bounded Website Improvement AI semantic proposals; exact Agent/Skill/route/input fingerprint reuse; action taxonomy, content brief, evidence IDs, confidence, rationale, verification and abstention. Explicit operator acceptance publishes canonical Evidence → Finding evaluation → existing Finding → existing Recommendation. Rejection changes only review state; insufficient evidence cannot be promoted; Task remains a separate manual action. No browsing, content publication, Website mutation or external write. |
| Website Standards Library (phases 14–16, staging) | YES (branch) | YES (targeted) | NO | YES | N/A | **TESTED / UAT REQUIRED** | Admin catalogue controls and all Skill definitions tested locally; manual visual/operator acceptance is pending. | 26 versioned definitions, preserving 17 diagnosis IDs; groups, applicability, evidence, source, action, verification; admin toggles and up to 30 expert criteria. Existing Library records and collection/binding flow retained. |
| Independent Website Standards Assessment (staging) | YES (branch) | YES (targeted) | NO | YES | YES | **TESTED / UAT REQUIRED** | SQLite tests and route renders; live PostgreSQL/storage/worker/operator UAT pending. Limits: 500 profiles, 3,000 active queries, 100 clusters, 20 candidates/cluster. No full-site completeness claim beyond those limits. | Queue-based stored-data evaluation with zero AI/provider calls; applicability/unknown/advisory/verified outcomes, explicit technical priorities and grouped affected URLs; source/freshness reuse and changed HTML invalidation; current-evidence human promotion to canonical Website Findings/Recommendations. Existing service/query/owner coverage preserves blocked candidates and human locks. |
| Shared Website Content / Competitor Criteria (staging) | YES (branch) | YES (targeted, fake model) | NO | YES | YES | **TESTED / UAT REQUIRED** | Live model advice quality and real competitor comparison require UAT. Own page must have readable HTML within 30 days. No automatic new-page/merge decision, external publication or Task creation; global technical proposals excluded from cluster-specific Phase 13. | Selected-page standards review works without competitors; approved comparable rival context is optional. Exact criterion IDs/own-rival excerpts, non-actionable and fabricated-output rejection, source changes invalidate approval, unchanged semantic content reuse; scoped Library links preserve selection. |
| Search Demand Change / Outcome Tracking | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a completed Task from an approved Phase 12 proposal, stored pre-change HTML, a queue worker, configured AI Integration, post-change targeted crawl and real GSC/GA4/SERP coverage for full comparison. Migration, storage checksum, queue/model and operator acceptance require staging UAT. | Applied-change provenance with affected URLs/clusters and old/new HTML fingerprints; bounded exact-URL + page-family Public Crawl; deterministic technical recheck; review-only stored-evidence semantic AI; explicit GSC/GA4 periods and stored SERP comparison. Human acceptance writes the existing Task Outcome and optionally appends resolved/reconfirmed Finding evaluation. No Result entity, causal claim, automatic DataForSEO spend, publication, redirect/delete or external write. |
| DataForSEO | YES | YES | NO | YES | YES | TESTED | Paid refresh queued when not fresh; cost/freshness guards remain | Central Integration + Website SEO collectors |
| AI Control Plane | PARTIAL | YES | NO | YES | PARTIAL | PARTIAL | Capability Router / Playbooks / RAG still PLANNED; long AI guidance queued | AI Router + Agent Profiles + Skill Library V1 present |
| Website Analyst | YES | YES | NO | YES | YES | TESTED | Guidance generation queued; no tools/MCP/Capability Router | Website SEO Analyst + Brand Discovery Analyst |
| Google Ads Analyst | YES | YES | NO | YES | YES | TESTED | Guidance generation queued; real Ads UAT not claimed PASS | Second operational Agent after Website |
| Recommendation | YES | YES | NO | YES | N/A | TESTED | AI drafts only; humans create Recommendations | Finding → Recommendation gate |
| Tasks | YES | YES | NO | YES | N/A | TESTED | Snapshot immutability (ADR-029) | Manual Recommendation → Task |
| Outcome Loop | YES | YES | NO | YES | N/A | TESTED | Metric Outcomes / Learning Candidates not in V1 | Task outcome signals + Finding re-eval; no Result entity |
| Business Outcome aggregates (Prompt 57) | YES | YES | NO | YES | N/A | **TESTED** | Client Value Story / Report Snapshots not yet; CRM out of scope | Definition + Observation + Revision + Manual/CSV; Brand Value outcomes cards use Read Service |
| Client Value Story (Prompt 58) | YES | YES | NO | YES | N/A | **TESTED** | Report Snapshots / PDF / share not yet; Demo catalog story fixtures retained | Deterministic read projection over Findings/Opportunities/Work/Outcomes; no attribution/AI |
| Meta central Integration | YES | YES | YES | YES | NO | UAT PASS | Resource refresh still sync | Agency Meta Integration; product docs claim real UAT PASS |
| Meta resource discovery | YES | YES | YES | YES | NO | UAT PASS | Discovery sync | Canonical `DiscoverMetaResourcesService` (selected Business context). Operator Data Sources Meta refresh uses `refreshInventory`, not broad `me/adaccounts` enumeration. Admin-gated. |
| Meta binding | YES | YES | YES | YES | N/A | UAT PASS | Collect live data hidden without collector | Meta Ads Digital Asset ↔ AssetBinding |
| Meta Ads Intelligence | YES | YES | YES | YES (interim specialist UX) | YES | **UAT PASS / ACCEPTED — NOT DONE** | Collect + AI guidance **queued** (async foundation). Professional Meta Expert Workspace **NOT IMPLEMENTED**. Real async Meta collect UAT tracked on Async Operations PR. | Read-only Intelligence engine on main after PR #119. Ads Manager spot-check PASS retained. |
| Professional Operator Workspace (Meta Ads) | NO | NO | NO | NO | N/A | PLANNED / BLUEPRINTED | Blueprint only — no final dashboard/charts/filters built; depends on Operational Data Foundation after async | Canonical blueprints: `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` + `docs/product/META_ADS_EXPERT_WORKSPACE.md` |
| Async execution | YES | YES | YES (Cloud Meta async smoke) | YES | YES | **TESTED / ACCEPTED** | Cancellation future; cross-asset still sync; persistent public host **deferred** (templates only) | Async implementation accepted on #121 (queue + Activity + Cloud Meta smoke). Persistent deployment ≠ required for this acceptance. |
| Historical performance memory | PARTIAL | PARTIAL | NO | PARTIAL | NO | PARTIAL | No dedicated historical warehouse / backfill / incremental store | Run/Evidence history exists; Historical Performance Store **PLANNED** |
| Operational Taxonomy | NO | NO | NO | NO | N/A | PLANNED | Do not invent taxonomy module yet | Direction in `PROJECT_MEMORY.md` |
| Marketing Initiative | NO | NO | NO | NO | N/A | PLANNED | No model/service on main | Brand-level commercial effort grouping — future |
| Benchmark Cohorts | NO | NO | NO | NO | N/A | PLANNED | No cohort objects on main | Compatible taxonomy dimensions required first |
| Cross-Asset Analyst | PARTIAL | YES | NO | YES | NO | PARTIAL | Deterministic packs TESTED; Analyst persona PLANNED; jobs invoked sync | Consistency packs in Core; Digital Operations Analyst future |
| Agency Learning | NO | NO | NO | NO | N/A | PLANNED | No Learning Candidate pipeline | Human-reviewed Agency Knowledge only — future |
| Platform Engineer | NO | NO | NO | NO | N/A | PLANNED | Research reference only (e.g. OpenHands) | Not a customer-analysis runtime |
| Google Business Profile | PARTIAL | YES | NO | PARTIAL | NO | PARTIAL | No local rank grid / competitor data; reply drafting not in scope; live UAT not run | Location page reads collected gbp_* tables: profile + completeness, performance with comparison, search keywords, reviews, photos/posts; Advisor tab (2026-09-25 Faz A) |
| Finding lifecycle / fingerprint | YES | YES | NO | YES | N/A | TESTED | Unique `(digital_asset_id, fingerprint)` | Persistent Findings; ADR-034 |
| Evidence / Run model | YES | YES | NO | YES | N/A | TESTED | Foundational model; not a historical warehouse | Evidence bound to Run; no separate Result entity |
| Shared collection engine (control plane) | YES | YES | NO | NO | YES | **TESTED** | Redis/Horizon required for production collection queue | Prompt 9: `CollectionRun`→`ResourceRun`→`DatasetRun` + planner + Horizon. Docs: `docs/implementation/COLLECTION_ENGINE_ARCHITECTURE.md`. Operator Collect Now for GA4/GSC/Google Ads/Meta Ads starts this engine (not specialist Evidence collectors). GA4/GSC/Ads/Meta/Website/DFS DatasetExecutors exist on the current release stack; that does **not** make those collectors REAL/DONE without their own UAT gates. |
| Data pool / warehouse foundation | YES | YES | NO | NO | N/A | **TESTED** | Provider population not REAL; BigQuery not implemented; SQLite proves writer semantics, PostgreSQL proves partitions | Prompt 10: raw object storage + typed PostgreSQL facts + materialization. Docs: `docs/implementation/DATA_POOL_ARCHITECTURE.md` + `MOXDOP_DATA_POOL_STORAGE_V1`. |
| Persistent collection monitoring | YES | YES | NO | YES | YES | **TESTED** | Provider collectors still fake/unimplemented; Reverb optional; polling is mandatory fallback | Prompt 11: Integrations hub `MonitoringPanel` + `CollectionRunMonitorQuery`. Docs: `docs/implementation/COLLECTION_MONITORING_UX.md`. Does **not** make provider collectors REAL. |
| GA4 production collection (contract-driven) | YES | YES | PARTIAL (staging) | YES | YES | **PARTIAL** | Live closed-period ±1% vs GA4 UI is EXTERNAL UAT REQUIRED (`moxdop:reconcile-provider-period GA4`). Unique users stay non-additive. Property retention may truncate 16m. Canonical `/assets/analytics/{id}` is numeric-only; DemoCatalog string ids 404 and never yield fixture KPIs. | Track A: 16-month backfill token, optional `newUsers`/`conversions`/`keyEvents`/`totalRevenue` on `ga4_property_daily`, YoY period compare, pool-backed Analytics screen |
| GSC production collection (contract-driven) | YES | YES | PARTIAL (staging) | YES | YES | **PARTIAL** | Live closed-period ±1% vs Search Console UI is EXTERNAL UAT REQUIRED (`moxdop:reconcile-provider-period SEARCH_CONSOLE`). Search Appearance still deferred. Canonical `/assets/search-console/{id}` is numeric-only; DemoCatalog string ids 404 and never yield fixture KPIs. | Track A: 16-month recommended backfill, pool-backed Search Console screen, previous+YoY compare, missing≠zero |
| Google Ads production collection (contract-driven) | YES | YES | PARTIAL (staging) | NO | YES | **PARTIAL** | Daily facts are successful zero-row on the bound staging account; GBP not in this slice; keyword grain now includes `ad_group_id` but staging 735 remains collapsed historical inventory (`IMPLEMENTED_UNPROVEN` until staging recollection with exact-resource `current_run_grain_proven`). Cursor Cloud cannot reach staging OAuth; operator path is `moxdop:google-ads:recollect-entity-snapshot` (`docs/operations/GOOGLE_ADS_KEYWORD_GRAIN_RECOLLECTION.md`) | Prompt 19; sibling-asset eligibility + v25 GAQL + keyword ad-group grain + pre-fact-commit checksum retry + brand-scoped Google backfill/incremental due-query (`core_asset_binding_ids`); non-keyword snapshots proven on PR #200 |
| Meta production collection (contract-driven) | YES | YES | NO | YES | YES | **PARTIAL** | Unmerged stacked child of PR #200. Live Marketing API / warehouse Collection Engine UAT **not** reachable from this Cursor Cloud agent (`META_APP_ID` / `META_APP_SECRET` / `META_ACCESS_TOKEN` unset; `APP_URL=http://127.0.0.1:8000`; no staging SSH/OAuth DB). Legacy Intelligence Ads Manager UAT (`act_744654160596455`) does **not** prove this warehouse path. Google Ads keyword grain remains `IMPLEMENTED_UNPROVEN` on #200. GBP isolated. No Meta specialist UX. | Prompt 24 `MetaAdsDatasetExecutor` + Prompt 25 initial backfill + Prompt 27 incremental on the shared engine. COLLECTION_READY families: `RF_META_AD_ACCOUNT_META`, `RF_META_ENTITY_SNAPSHOT`, `RF_META_INSIGHTS_SYNC`, `RF_META_INSIGHTS_DAILY`, `RF_META_TYPED_ACTIONS`, `RF_META_INSIGHTS_BREAKDOWN`. Deferred (not expanded): `RF_META_ASYNC_INSIGHTS` (async is transport inside daily/breakdown). Incremental due selection uses exact preflight `core_asset_binding_ids`; `DATA CURRENT` only when every eligible Meta dataset in that binding scope is current. Google bindings never enter Meta runs. |
| Website production crawl collection | YES | YES | NO | YES | YES | **PARTIAL / UAT REQUIRED** | Live Website crawl and authenticated WordPress Connector Collection Engine UAT remain external gates. Public collection truthfulness, per-URL idempotency, HTML artifact recovery and change detection require live UAT. Connector is not a substitute for external crawl; no production deployment is claimed. | Shared engine public families remain `WEB_RF_HTTP_HTML_DIAGNOSIS`, `WEB_RF_PUBLIC_CRAWL`, `WEB_RF_DNS_TLS`, `WEB_RF_PAGESPEED`. The resumable crawl seeds from sitemap, existing URL inventory and published connector permalinks, follows same-site links and is bounded at 5,000 pages / 2 GB. `website_html_snapshot` retains every observation while unchanged bodies reuse a content-addressed private artifact. A paired WordPress Website additionally plans `WEB_RF_WP_REST`; non-WordPress sites stay public-only. Integration shows required progress and discovered-vs-captured HTML coverage; Website analysis owns interpretations. Not DONE. |
| DataForSEO production enrichment (engine-driven) | YES | YES | NO | NO | YES | **PARTIAL** | Unmerged stacked child of PR #203. Live Marketing/Labs UAT **not** reachable (`DATAFORSEO` credentials unset in this agent; no staging SSH). Paid POST is never auto-retried / never routinely scheduled. Fail-closed `paid_attempt_started` is checkpointed before the charged POST; an unresolved attempt fail-closes that DatasetRun (`CHARGE_UNKNOWN`) even if the fingerprint recomputes. Paid request fingerprint remains the lock/cost provenance key **and** the `HIT_FRESH` pool key (asset + dataset + fingerprint, including `target` / `location_code` / `language_code`); a market change is a cache miss and POSTs again. Warehouse write idempotency is unique per DatasetRun + batch so a sibling asset or force-refresh cannot reuse another run’s committed receipt. A different fingerprint is allowed only on a new DatasetRun. Legacy SEO Evidence collectors unchanged. Domain intersection, relevant pages, and SERP organic stay DEFERRED. | Shared engine `DataForSeoDatasetExecutor` for COLLECTION_READY: `DFS-FREE-USER`, `DFS-FREE-MARKETS`, `DFS-RK-LIVE`, `DFS-KFS-LIVE`, `DFS-COMP-DOMAIN-LIVE`. Agency Integration credentials; facts are Website-asset scoped. Paid families require `paid_enrichment_consented`; competitors also require `public_discovery`. Missing search_volume/etv recorded as missing, never a measured zero. Not DONE. |
| Agency brain / operational synthesis (Phase C.1) | YES | YES | NO | N/A | N/A | **PARTIAL** | Live provider/WordPress UAT remains external and is not claimed. No BrainV2 / FindingV2 / Result entity / auto-Task / Agency Learning. Document Head only evaluates collected public dimensions on a proven homepage. WordPress maintenance and parity rules only evaluate completed connector/public DatasetRuns; update availability never implies a vulnerability. Meta/Google coverage safeguards remain unchanged. | `EvaluateFindingsForAssetJob` runs canonical `FindingEvaluationService` then `CollectedFactsAnalysisService`. Website combines `DocumentHeadEvaluator` with `WordPressCollectedFactsEvaluator` for core/plugin/theme update state, REST/cron state and connector↔published title/description/canonical parity. Google Ads and Meta adapters remain unchanged. Recommendations are deterministic; Task creation remains manual. Not DONE. |
| Operational / settings completeness (Phase D) | YES | YES | NO | YES | N/A | **PARTIAL** | Live SMTP delivery UAT and browser/mobile push remain external/deferred. No SaaS whitelabel, no second credential screen, no SettingsV2. | Canonical operator `/settings` + `/profile` + `/integrations`. Admin/Team Member lifecycle with deactivate-not-delete. Agency timezone/locale drive operator rendering via `OperatorClock` (storage clock stays `APP_TIMEZONE`); dashboard greeting/date use `OperatorClock::now(auth()->user())`. Encrypted write-only operator SMTP overlay with env fallback and test-mail action. `SendReportDeliveryJob` reloads the persisted overlay and purges the mailer at the queued send boundary so long-lived workers honor settings changes/clears. In-app notification preferences only; push not implemented. |
| End-to-end operator UX / QA (Phase E) | YES | YES | NO | YES | YES | **PARTIAL** | Staging/browser operator UAT not reachable from this Cursor Cloud agent (`APP_URL=http://127.0.0.1:8000`; empty `GOOGLE_CLIENT_ID` / DataForSEO / Meta secrets; no staging SSH). Live provider collect remains the isolated #200/#203/#204 gate. Collection Engine still rejects PHPUnit `sync` queue; production Website refresh surfaces that as unavailable rather than a fake success. No UI redesign, no push/PWA, no GBP collector reopen. | Canonical root journey `Login → Customer → Brand → Asset → Data Sources bind/collect → Activity → Evidence/Finding → Recommendation → manual Task → Outcome`. Data Sources Collect Now for GA4/GSC/Ads/Meta creates `CollectionRun` (no specialist Evidence summaries); GBP stays on BoundCollectorRegistry. Production period reads use `OperatorPeriod` / `OperatorReportingPeriod` (custom dates override DemoPeriod math). Capture note/opportunity are truthful unavailable. Google Ads/Meta `runAnalysis` queues finding evaluation. Findings/Recommendations `?asset=` isolation. Activity Center lists `CollectionRun` + async `Run`. PHPUnit: `tests/Feature/PhaseE/*`. Not DONE. |
| Production readiness / release (Phase F) | YES | YES | NO | YES | YES | **PARTIAL** | **RELEASE-CANDIDATE CODE READY WITH EXTERNAL GATES** on the dedicated RC integration branch that actually contains #202 + #199 + #200-downstream (#203→#204→#206→#207→#208→#209). PR #209 alone is **not** that cumulative ancestry. Remaining external gates: Reviewer APPROVED, SSH/deploy of this RC SHA, Google Ads keyword recollection, GBP official API, Meta/Website/DataForSEO shared-engine live UAT, live SMTP, browser push/PWA (not implemented). Do not treat Demo fixtures as production truth. GitHub `verify`/`postgres` ran on the Collect Now SHA; `gate` failed because the RC PR body quoted the Autopilot product-PR HTML marker (substring match). This RC PR is not Autopilot and must not contain that marker. | Hardening only: controller-backed retired `/app` `/system` 410 routes so `route:cache` stays deploy-safe; `moxdop:production-check` HTTPS + OAuth-callback + redacted failures; canonical docs/ADR-044 match root operator + Filament `/admin`. Operator Data Sources bind through Confirm*; Google/Meta resource refresh on that page is Admin-only and Meta uses selected-Business `refreshInventory`. PHPUnit: `tests/Feature/PhaseF/*`. Not DONE. |

---

## Critical clarifications

### Meta Ads Intelligence — UAT PASS / ACCEPTED, not DONE

PR [#119](https://github.com/yakupudul/dijitaloperation/pull/119) (*Meta Ads Intelligence + Analyst V1*) merges the **read-only Meta Ads Intelligence engine** onto main.

Accurate multidimensional state:

> **UAT PASS / ACCEPTED — NOT DONE**

Accepted operator UAT (Ads Manager manual spot-check **PASS**):

| Field | Value |
| --- | --- |
| Meta Ad Account | Obezite ve Estetik (`act_744654160596455`) |
| Campaign | `09 \| Diaspora TR \| Form - Mox` |
| Period | `2026-07-14` → `2026-08-10` |
| Result | DOP metrics matched Meta Ads Manager |

Also accepted on this slice: hierarchy collection, provider-ID joins, missing≠zero, click/result metric semantics, synthetic UAT isolation, read-only Meta client (GET only).

**Explicitly still NOT DONE / not claimable as finished Meta product:**

- **Background-ready: YES** for collect + AI guidance (queued) — Activity Center persists progress. Real async Meta collect UAT is on the Async Operations PR.
- **Professional Operator Workspace: BLUEPRINTED / PLANNED, NOT IMPLEMENTED** — current Overview/Performance is an interim UAT surface; target IA is `docs/product/META_ADS_EXPERT_WORKSPACE.md` (+ global `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`)
- Historical arbitrary querying / performance warehouse: **NO**

Do **not** describe this merge as “Meta Ads complete”, “Meta module finished”, or “Meta workspace done”.

Main also continues to include Meta central Integration + discovery + binding (connection layer), with prior product-doc real UAT PASS for that scoped slice.

### Meta production collection (contract-driven) — PARTIAL, not DONE

Contract-driven Meta Ads collection already uses the shared Collection Engine and Data Pool (`MetaAdsDatasetExecutor`, `MetaInitialBackfillOrchestrator`, `MetaIncrementalCollectionOrchestrator`). This stacked child closes the contract-to-runtime loop for COLLECTION_READY `META_ADS` families (catalog + executor kind + PHYSICAL_TABLE natural keys + freshness/backfill policy), bounded 180d historical slices with exact asset/resource provenance, DatasetWritePipeline grain/idempotency on the nine Meta physical tables, checkpoint resume for entity snapshot `step_index` and insights `work_index`, and incremental `DATA CURRENT` only when every eligible Meta dataset in the exact preflight binding scope is current.

**COLLECTION_READY families:** `RF_META_AD_ACCOUNT_META`, `RF_META_ENTITY_SNAPSHOT`, `RF_META_INSIGHTS_SYNC`, `RF_META_INSIGHTS_DAILY`, `RF_META_TYPED_ACTIONS`, `RF_META_INSIGHTS_BREAKDOWN`.

**Deferred (not expanded):** `RF_META_ASYNC_INSIGHTS` — async Insights is transport inside daily/breakdown, not a separate collector family.

**UAT REQUIRED / PARTIAL:** this Cursor Cloud agent cannot reach the already UAT-proven Meta Integration OAuth path. Exact missing capability: no `META_APP_ID` / `META_APP_SECRET` / `META_ACCESS_TOKEN` in process or `.env`, `APP_URL` is `http://127.0.0.1:8000`, no `.env.staging` / production secrets, no staging SSH or operator SQLite with a live Marketing API token. Do not treat PR #119 Ads Manager Intelligence UAT (`act_744654160596455`) as proof of this shared-engine warehouse path.

**Not claimed:** live Marketing API warehouse CollectionRun, professional Meta Expert Workspace, Google Ads keyword-grain staging proof (still `IMPLEMENTED_UNPROVEN` on PR #200), or GBP.

Do **not** describe this slice as “Meta collection DONE”.

### Agency brain / operational synthesis (Phase C.1) — PARTIAL, not DONE

Phase C.1 wires already-supported deterministic analyzers to collected Data Pool facts instead of Demo fixtures or live provider HTTP:

- Website/SEO: `website_metadata_snapshot` → existing Document Head rules (only collected dimensions) on the proven homepage URL (`primary_url` slash variants or the HTTP snapshot redirect target of that request). Homepage metadata, redirect HTTP, and schema rows must belong to a completed DatasetRun for this asset; running/failed crawls skip as `unproven_website_homepage_snapshot`. Multi-page crawls with a shared checkpoint `observed_at` never fall back to a higher-ID sibling such as `/contact`.
- Google Ads: bound `google_ads_campaign_daily` → existing campaign spend-with-zero-conversions rule, only from a non-partial 28-day window whose coverage dates are attributed to completed DatasetRuns (warehouse facts from those runs plus completed zero-row dates); failed paged-run slices in merged materialization metadata cannot prove the window
- Meta Ads: bound `meta_campaign_daily` + `meta_campaign_snapshot` → existing inactive-campaign-with-spend rule; daily window uses the same completed-run coverage attribution. Snapshot status is taken only from the materialization's latest successful `meta_campaign_snapshot` DatasetRun (stale entities from older completed refreshes, and running/failed/partial entity snapshots, are never current / `response_ok=true`)

Canonical production job `EvaluateFindingsForAssetJob` now runs collected-facts adapters after `FindingEvaluationService`. `FindingEvaluationService` emits `FindingEvaluationCompleted` so Outcome V1 can observe later canonical GSC/GA4 evaluations (ported from superseded PR #205; Google Ads account `conversions-decline` Evidence definition was not copied because #206 already has a campaign-grain Ads vertical). A thrown rule after eligibility emits `evaluationSuccessful: false` for that module so a crashed follow-up cannot classify `IMPROVEMENT_OBSERVED` from a stale Finding. Manual Recommendation → Task remains human. AI does not create Findings or Tasks. Live provider UAT from #200/#203/#204 is a separate external gate.

Do **not** describe this slice as “Agency brain DONE” or as proof of live Google/Meta/Website collection UAT.

### Operational / settings completeness (Phase D) — PARTIAL, not DONE

Phase D reuses the existing operator Settings/Team/Profile/Integrations surfaces. It does **not** introduce SettingsV2, UserV2, NotificationV2, SaaS whitelabel, or a second credential store.

Shipped in this slice:

- Admin-only team create/role/deactivate (no destructive delete; last admin protected)
- Operator forgot-password / reset on `/forgot-password` (inactive/unknown emails share the same success copy and receive no mail; successful reset rotates remember token and does not reactivate). Reset mail locale is set on the Notification at dispatch and in explicit `__()` calls; `MailMessage::locale()` is not used.
- Agency timezone/locale/default analytical range affect operator date rendering (`OperatorClock`) and session period defaults, including dashboard greeting/date via `OperatorClock::now(auth()->user())`. Invalid stored timezone/locale values fall back to catalog defaults. Laravel `APP_TIMEZONE` remains the storage clock (password-reset tokens / Eloquent datetimes / queue+artisan are not rewritten per operator).
- Operator SMTP overlay: encrypted write-only password, env fallback without copying env secrets into the DB, test-mail action; invalid host/port/encryption is rejected without poisoning runtime mail config; test-mail failures log exception class only; queued report send reloads the overlay and purges the resolved mailer so Horizon/worker processes do not keep a stale transport after Settings change or clear
- In-app notification preferences for existing events; browser/mobile push is **not** implemented

**Not claimed:** live SMTP provider UAT, web-push/PWA, SaaS tenant branding, or Filament as the canonical operator settings product (`/admin` remains technical).

Do **not** describe this slice as “Phase D DONE”.

### End-to-end operator UX / QA (Phase E) — PARTIAL, not DONE

Phase E is QA + narrow remediation of the canonical root operator journey. It does **not** reopen provider collection, Agency Brain analytics, Settings architecture, GBP collectors, push/PWA, or whitelabel.

Shipped in this slice:

- Production date presets/custom ranges use agency `OperatorClock` “today” (`OperatorPeriod`) and treat filled from/to as a custom range (`OperatorReportingPeriod`) so workspace period controls actually change warehouse reads
- Website overview KPIs stay `—` when the requested period does not overlap collected days (`period_has_data`); collected values outside the range are not reused as stale current KPIs. KPI summaries and `gsc_daily` still use overlap / per-day slice semantics. GSC queries/pages and GA4 landing/acquisition **undated aggregates** require an exact `requested_period` match; dated detail rows may be sliced when the Evidence period overlaps. A wider aggregate is not shown under a narrower 7-day/custom selection and is never prorated. Missing/uncollected detail datasets stay empty arrays, never numeric zero.
- Capture `note` / `opportunity` are unavailable (no DemoState persistence); `client_request` and `task` still persist
- Google Ads `createRecommendation` / `markClusterReviewed` no longer flash a fake success
- Google Ads and Meta Ads `runAnalysis` queue `FINDING_EVALUATION` instead of AI guidance
- Findings and Recommendations indexes honor `?asset=`
- Activity Center lists Collection Engine runs plus async `Run` rows (`metadata.async`)
- Website `refreshData` starts production collection when possible and surfaces Collection Engine `sync`/Redis unavailability instead of a fake refresh
- Deterministic PHPUnit journey: Customer → Brand → Website asset → GA4 bind → `Http::fake` collect → Evidence + Document Head Finding → grounded Recommendation → manual Task → later `improvement_observed`
- Demo catalog IDs remain 404 on operator routes; Atlas copy is not rendered on production website/GA4 surfaces

**Not claimed:** staging browser smoke, live provider collect UAT, Collection Engine on PHPUnit `sync` queue, or Phase E DONE.

Do **not** describe this slice as “Phase E DONE”.

### Production readiness / release (Phase F) — PARTIAL, not DONE

Phase F is release convergence/hardening. The dedicated RC integration branch is the first tested head that actually contains **#202 + #199 + #200-downstream**. PR #209’s own ancestry does **not** include #202 or #199. This slice does **not** reopen collection, Agency Brain, Settings, GBP, push/PWA, or whitelabel.

Shipped in this slice:

- Retired `/app/*` and `/system/*` 410 responses are controller-backed so staging `route:cache` remains a real deploy step
- `moxdop:production-check` verifies HTTPS/`APP_FORCE_HTTPS`/secure cookies on staging/production, canonical Google/Meta callback paths, and does not print `APP_KEY` or exception secrets
- Canonical docs (MASTER_SPEC §6/§12, ADR-044, AGENTS.md, PROJECT_MEMORY, deploy/rollback/backup/smoke) match the root operator + Filament `/admin` contract
- Focused PHPUnit: `tests/Feature/PhaseF/PhaseFReleaseReadinessTest.php`

**External gates (not claimed here):** live staging deploy from this agent (public HTTPS login is reachable at `app.moximu.com` but there is no SSH, no host `.env` access, and no ability to `git checkout` the RC SHA); Google Ads keyword exact-resource recollection; GBP official API; Meta/Website/DataForSEO shared-engine live UAT; live SMTP delivery; browser/mobile push (not implemented); Prompt 68 host backup restore drill; repository Reviewer APPROVED + stacked merge.

Do **not** describe this slice as “Phase F DONE” or as production-deployed.

### Public Website Discovery is limited

Current Discovery is **Website-owned bounded public discovery**:

- public website / context signals
- Brand Context candidates (human review)
- optional DataForSEO competitor **candidates**

It is **not** full digital web discovery, social intelligence, review/news monitoring, or continuous monitoring.

### Async foundation (material)

Long operator actions (bound collect, Website diagnosis, public discovery, SEO refresh when not fresh, Website/Google/Meta AI guidance) queue via `AsyncOperationService` onto Laravel **database** queue (Redis/Horizon on staging/production). Canonical execution record remains **Run** (`queued|running|completed|partial|failed`; `cancelled` reserved). Operator Activity Center is the root Livewire surface `/activity` (`operator.activity`) with phase progress, duplicate guards, stale detection, retry for safe failures, and in-app database notifications. Filament `RunResource` at `/admin/runs` remains technical/admin tooling only (ADR-044). **Cancellation** is intentionally **not** shipped (fragile with current job architecture). Cross-asset consistency packs and integration resource refresh remain synchronous by design for now.

### Async is not fully universal

On main after Async Operations merge:

- Migrated Digital Asset long actions **dispatch** queue jobs (not `(new Job)->handle()`)
- Cross-asset consistency checks may still call `->handle()` in-request (short/safe)
- Integration resource discovery refresh may still be sync
- Cancellation of in-flight provider work is **future**

Track readiness per capability in this ledger’s **Background-ready** column — do not mark Historical Store / Expert Workspace DONE because async landed.

### “IMPLEMENTED V1” ≠ DONE

Many product docs and `docs/PROJECT_STATUS.md` use **IMPLEMENTED V1** / **COMPLETED** for scoped milestones. This ledger intentionally separates:

- version / milestone labels
- Definition-of-Done **DONE**

Most coded capabilities on main are **TESTED** or **UAT PASS** (Meta connection slice) or **PARTIAL**, not **DONE**, especially while async debt remains for long-running flows.

---

## Module inventory (main)

| Module | On main | Notes |
| --- | --- | --- |
| `website` | YES | Collection, intelligence, Discovery, analysts, skills |
| `google-ads` | YES | Collector, Findings, Analyst, skills, workspace |
| `google-business-profile` | YES | First-module collector; Reputation not present |
| `meta-ads` | YES | Insights collector + Intelligence + Analyst on main after #119; async collect/AI via Core queue jobs |
| `sample-module` | YES (fixture) | Not an operator product capability |

Core owns Customer/Brand/DigitalAsset, Integrations, Run/Evidence/Finding/Recommendation/Task, and cross-asset packs.

---

## Maintenance rule

When a PR changes capability behavior or readiness:

1. Update **this ledger in the same PR**
2. Update `PROJECT_MEMORY.md` if the change is a material product / architecture decision
3. Do not mark DONE without reconciling code, tests, real UAT, operator UX, async requirement, blockers, and docs

---

## Snapshot provenance

| Field | Value |
| --- | --- |
| Base | `origin/main` (updated at PR #119 acceptance) |
| PR #119 | Ads Manager operator spot-check **PASS** — merge acceptance for Intelligence engine |
| Accepted UAT | `act_744654160596455` / `09 \| Diaspora TR \| Form - Mox` / `2026-07-14`→`2026-08-10` |
| Method | Code / test / Filament invocation / operator Ads Manager comparison |
| Guessing | Forbidden — unknown real UAT recorded as **NO** unless docs claim PASS |

## Global services management — 2026-09-07

Operator-authorized scope: Library navigation contains only Services, Search Queries, Query Clusters and Competitors. Other routes remain available to existing deep links. Services is the agency-wide editing surface: paginated searchable table, sector filter, edit drawer, sector CRUD and reversible deletion.

Global catalogue names are authoritative. A rename synchronizes linked Brand Offering primary names in one transaction, preserving offering IDs, priorities and goal/query relationships. A conflicting name aborts the entire edit. Brand-local rename of linked services directs the operator to Library. New Brand Offerings resolve the global catalogue, and the additive migration links legacy unlinked offerings without fuzzy matching or silently merging conflicts.

Service deletion is soft deletion: current catalogue/name/brand-offering reads hide the deleted identity, while historical foreign keys and observations remain. Restore brings the same identity and its links back. Current Brand Intelligence products/services and priority projections refresh on rename, deletion and restoration. Category keys remain stable on rename; deleting a category clears the service category, never deletes its services.

Validation: operator explicitly requested direct GitHub edits, no cloning and no tests. No test, build, browser or staging database verification is claimed. Deployment and operator acceptance remain required.

## Library imports and central locations — 2026-09-08

Scope: operator-authorized direct edits on staging work branch `chatgpt/search-demand-foundation`. Library navigation remains Services, Queries, Query Clusters, Competitors.

- Services supports bulk lines (up to 2,000), optional `service | phrase, phrase` syntax, and editable matching expressions. Expressions have service-local uniqueness; shared expressions can match multiple services. Existing global identity aliases remain separate.
- Queries supports paste, XLSX/CSV/TSV/TXT and selected stored Google Ads / Search Console resources over an explicit date range. Only existing canonical provider fact tables are read. No provider collection/API/spend follows import, and provider metrics are not copied or aggregated as query performance.
- A valid sector is mandatory. Optional service selections must be active and belong to that sector. Whole-phrase matching uses selected service expressions. Unmatched queries remain available through the sector-aware Unassigned filter. Operators can bulk assign up to 500 selected queries and create a sector/service inline.
- The agency Library identity for new writes is the location-free canonical text, independent of account, sector and market. Existing exact canonical items are reused. Multiple sectors attach to one item rather than creating another identity; the legacy scalar sector remains the primary compatibility value. Provider Intelligence identities and provider facts are unchanged. Existing historical query variants with locations are not destructively merged or rewritten by this migration.
- Country/province/district names are stripped at whole-expression boundaries, longest first; Turkish/ASCII spelling is folded only for location and phrase recognition. Original query spelling and removed expressions are visible in source records. Ambiguous names such as Of/Kale are also stripped under the operator's literal rule; the import panel says so. Location-only rows fail explicitly. Source repetition does not add identical source rows.
- The central bundled catalog has 249 ISO country/territory labels, 81 Turkish provinces and 973 districts with parent IDs and available dataset details. Original MIT files, licenses and pinned provenance live under resources/data/locations. No runtime downloads, new provider, new dependencies or Locations navigation item. CountryOptions and CityOptions delegate to the central catalog; Brand, customer HQ, prospect, public-discovery and search-profile location inputs use it. Non-Turkey free-form existing city data remains possible; no additional foreign subdivision catalog is introduced. Provider-specific geotarget IDs retain their own provider contract.
- Imports and bulk assignment use durable SearchQueryLibraryImport records and 100-row queued chunks, with bounded inputs, progress, first 20 row errors and terminal cleanup of temporary source files. Activity links to the relevant Library screen. Unknown failure never reports completion; reimport is safe. XLSX expansion is bounded to 32 MiB, 256 columns and 10,000 query rows; XML external loading and DTD/entity declarations are not accepted.
- Migration adds matching expressions, query-sector relations and import payload storage; backfills sector relations while preserving IDs and historical records.

Verification truth: source was reviewed without cloning, installing, running tests, Pint, build, browser acceptance or staging execution, as explicitly requested. Code is saved for deployment; migration, workers, runtime and operator acceptance are unverified. Not a DONE/UAT claim.

## Brand edit hotfix — 2026-09-08

Source review found that `Brand.offerings` is both a legacy text attribute and a HasMany relationship. `fillCommercialContext()` called collection methods on the shadowing text/null attribute during Brand edit mount. It now explicitly reads the loaded relationship through `getRelation('offerings')` for selected services and priorities. Legacy text and all database identities remain unchanged.

Direct staging hotfix; no clone, tests, build or server execution, per operator instruction. This resolves the identified code defect; the reported production HTTP 500 has not been correlated with server logs or verified after deployment.

## Multi-sector Brand form — 2026-09-08

Operator-authorized direct staging work on `chatgpt/search-demand-foundation`. Brand create/edit uses a searchable multi-sector selector and the union of active services in selected sectors. Service search, selected-only view, selected count, priority controls, inline service creation with explicit sector, reviewable out-of-scope selections, validation summary and a sticky save bar keep the form focused.

Selected sectors reference global ServiceCategory IDs through `brand_service_category`; current labels follow Library edits and deleted categories detach. The first selected code remains the legacy scalar `sector` for single-sector consumers. The additive migration attaches the existing sector and sectors of active linked services without changing offerings, priorities, goals or query identities. Existing scalar-only creation paths retain a read fallback.

Sector removal does not silently remove services. Save requires all selected services to be active and in scope; the operator restores a sector or explicitly removes its service. A newly entered service that resolves to an existing identity in another sector is rejected without relabeling the global identity. Brand, sector links, services, priorities and areas save transactionally. Edit mount reads form data directly instead of running portfolio findings/task calculations.

Source-reviewed only. No clone, dependency installation, tests, formatter, build, browser or server verification ran, per operator instruction. Deployment migration/runtime and human UI acceptance remain unverified; this is not a DONE claim. This is ordinary synchronous form CRUD with no provider work.

## Brand detail mount hotfix — 2026-09-08

The routed Operator BrandShow adapter still called map() on Brand's legacy offerings text attribute when a BrandIntelligenceContext existed. The prior edit-form fix did not cover this separate mount path. Read the explicitly eager-loaded offerings relation with getRelation('offerings') when constructing the business-context service list. Existing filtering, order, labels and stored data are unchanged.

Source-reviewed direct staging patch; no clone, tests, build or server execution, per operator instruction. This fixes an identified fatal code path; the reported HTTP 500 remains unverified against server logs and post-deployment runtime.

## Brand service bulk selection — 2026-09-08

The shared Brand create/edit form adds Select all shown and Deselect all shown. Actions use the same server-derived serviceOptions as rendering, respecting selected sectors, active status, search text and selected-only filtering. Selection is deduplicated and preserves other selections and existing priorities. Deselection removes priorities only for deselected services. These actions change form state; persistence still requires Save. Empty/inapplicable buttons are disabled; TR/EN labels and visible count are provided.

Direct staging change, source-reviewed only. No cloning, tests, build or runtime/UAT verification, per operator instruction.

## Query Library daily management — 2026-09-08

Operator-authorized scope: inline query-name editing, download of filtered/all queries, immediate removal and related list usability, on `chatgpt/search-demand-foundation`.

- Hover/focus pencil opens the row editor; mobile keeps it visible. Enter saves, Escape cancels, inline errors retain input. Rename applies the same location stripping and canonical normalization as import, checks existing/deleted identities and rejects stale concurrent edits. ID and service/sector links persist. Original observations remain; a manual source record records previous/new input, actor and stripped locations. Linked Brand portfolio entries without text overrides refresh their Intelligence identity through the existing resolver; provider fact identities are not rewritten.
- Soft removal uses an additive deleted_at column. Removed items leave normal Library reads; Deleted supports individual/bulk restore, and the last individual removal has Undo. Imports refuse to recreate/resurrect removed identities. Existing Brand portfolio references explicitly retain their Library relation (withTrashed); removal from Library does not silently remove previously applied Brand queries.
- The default unfiltered list includes all nondeleted statuses. Status, sector, service, source, unassigned and literal folded search use one model query scope shared with authenticated CSV export. Export downloads all matching records, independent of pagination or row selection, in stable selected ordering. It is a direct streamed download with 500-row eager-loaded batches, UTF-8 BOM, semicolon delimiter and formula-cell neutralization; no Livewire base64/full-memory download. A concurrent writer can change records during a long export: no point-in-time snapshot guarantee.
- Service filter, clear-filters action, status labels, newest/A–Z/Z–A sorting, 25/50/100 page size and page correction after removal are exposed. Selection actions preserve the current filter scope and have a 500-selected-row mutation limit. Selected rows can be activated, excluded, removed or restored. Existing assignment/import jobs remain queued. New bounded status CRUD and rename are synchronous; export is a streamed read, not provider or AI work.

Verification: source inspection only. Per operator instruction no clone, tests, dependency install, formatter, build, browser or server execution. Migration, runtime, CSV opening and UI acceptance are unverified; no DONE claim.

## Query exclusion list — 2026-09-08

Operator approved the proposed exclusion-list workflow, then requested continuation. Scope remains direct staging branch `chatgpt/search-demand-foundation`; no main, PR or server deployment.

- Sorgular embeds a collapsible Exclusion list with bulk expression entry (1–2,000 lines per save), normalized deduplication, search, edit, enable/disable and deletion. Matching is whole folded word/phrase, case/Turkish-ASCII tolerant, never substring or fuzzy typo guessing. Deleting a rule never restores previously removed queries.
- Existing cleanup is a durable queued preview over all nondeleted Library queries through the start-time maximum ID, independent of current list filters. It checks canonical text and retained original source texts, showing the actual matching text/expression. All matches are paginated and initially selected; operators may uncheck individual rows or select/deselect the full preview before approval.
- Only the creator can approve their ready run. Run-row locking serializes approval and preview selection changes. Current rule fingerprint must match the preview; changed rules require a new preview. Apply processes only approved selected rows, rechecks item version, matching text and exceptions, and soft-removes with the existing model. Changed/protected/deleted rows are skipped, never silently replaced by unseen candidates. No Brand portfolio or provider fact deletion follows Library removal.
- Scan/apply execute in 100-item queued chunks with durable cursor/results/counts, overlap protection, terminal failure history and no automatic restart after partial failure. History resumes from the Sorgular panel after navigation/reload. It is a dedicated Library maintenance history, not a new provider collection or Finding/Run engine.
- New writes through SearchQueryLibraryService consult active exclusions before location stripping, preserving the existing store return contract through a specific validation exception. The main paste/Excel/CSV/stored Ads/GSC LibraryImportWorkflow catches it as a separately counted excluded row, storing every raw row and matched rule snapshot for paginated inspection. These are neither accepted queries nor generic errors/duplicates. Older callers of the shared writer also respect screening and receive the explicit validation reason.
- Individual and bulk restore expose an enabled-by-default protection checkbox. Protection stores a canonical query exception used by both preview/apply and future imports. Exceptions can be removed from their own list; removing protection does not immediately delete the query. Import raw text is checked first; only exemption identity resolution uses the location-free canonical text.
- Rule changes during processing are checked between rows; execution is not an all-or-nothing global transaction. Completed removal receipts persist and removed queries remain recoverable. Preview membership is a snapshot; new/changed data can be covered by a new scan.

Verification truth: code inspected only; operator forbade cloning and tests. No tests, formatter, build, browser or staging DB/queue execution ran. New migration, worker runtime and human acceptance remain unverified. No DONE/UAT claim.

## Automatic resource collection → Query Library — staging source, 2026-09-09

| Capability | Code | Operator UX | Async | Tests / UAT | Remaining scope / limits |
| --- | --- | --- | --- | --- | --- |
| Discovered-account cadence and smart continuation | Added on staging work branch | Google/Meta integration account controls; daily/3-day/pause/update now, status and actionable errors | Scheduler + existing central/bound collectors; two account slots | NOT RUN by operator instruction; live runtime unverified | Google Ads/GSC/GA4 resource-first; Meta/GBP require real existing binding; no Website/paid-provider auto enablement |
| Completed-data automatic Ads/GSC query imports | Added on staging work branch | Persistent sector/service mapping, progress, observations, resume/close, Activity | Four admitted account imports, 100-row steps, indexed dataset receipts | NOT RUN; no 100k benchmark | Initial successful stored history included; counters describe source rows; no provider-metric aggregation |
| Preserve manual decisions and recheck unmatched queries | Added on staging work branch | Sticky query deletion/rename aliases, removable service chips and automatic-match blocks, confirmed recheck | Recheck queued; small manual edits synchronous | NOT RUN; migration/UAT required | Existing service/child placements preserved; changed account mapping does not retroactively reclassify existing queries |

See PROJECT_MEMORY automatic account section for full contract, worker/scheduler prerequisites and explicit unimplemented resource-first Meta/GBP scope. No main or server deployment is claimed.

## Connector activity and standards extension — 2026-09-09

Operator-approved sequence: connector → integration ingestion/UX → deterministic standards.
The existing pairing and read endpoints remain compatible; downloadable connector version is 1.1.0.
No clone, dependency install, tests, formatter, build, browser or host execution was performed.
Source implementation is not deployment, runtime acceptance, or DONE.

Implemented:
- WordPress local non-autoloaded outbox table, 50-event signed POST batches through WP-Cron every
  five minutes, signed per-ID acknowledgements, replay protection, retry/backoff and 10,000-event cap.
  Save hooks never perform HTTP. Events in one request coalesce by type/object; separate editor
  requests remain separate audit records. Missing queue writes/overflow report a persistent gap.
- Content publish/update/status/delete, allowlisted SEO/business metadata, selected settings,
  theme/plugin maintenance and role-change events. Acting WP user ID/display name are included;
  no passwords, form entries, arbitrary metadata values, visitor clicks or binary media.
  Public content types are inventoried; internal submission/order stores are excluded.
- Safe cached/runtime health, delayed cron count, module presence, debug/registration policies,
  cache flags, adapter versions and allowlisted branch fields extend existing CMS metadata.
- Additive receiving tables; connection/installation-bound HMAC, timestamp/nonce verification,
  credential recheck under connection lock, deduplication and transactional acknowledgement.
- Website Integration Activity tab: period/type/actor filters, 25-row pagination, delivery age,
  pending count and coverage-gap warning. Global Activity merges the same stored events.
- Scheduler admits at most two event reconciliation collections, at most 50 events/changed object
  IDs per incremental scope, and defers while the asset has an active collection. Successful
  completion advances the receipt watermark; failed/partial collections do not. The existing
  CMS snapshot writer retains untouched objects on incremental refresh and removes only scoped
  missing objects. The connector must echo the exact object scope before writes are accepted.
- Daily CMS inventory reconciliation and global-settings changes use full CMS inventory; ordinary
  content changes use scoped content/media/SEO reads. Site/extensions/taxonomies are still full
  lightweight snapshots. Relevant known public URLs use existing bounded targeted crawl (max 100).
  No daily all-page browser run, paid provider or AI call is introduced.
- Standards categories: Website, Google Ads, Meta Ads. Ads categories are placeholders with explicit
  empty-state copy; their criteria are not shipped. Website definitions distinguish general vs
  WordPress inheritance. Existing expert criteria are retained but inactive; their add form is
  removed. Length heuristics default inactive. Historical evidence is preserved.
- 25 additional deterministic/advisory checks cover duplicate/multiple metadata, H1/language,
  internal target status, canonical/hreflang target status, content fingerprint matches, image
  attributes, mixed resources, WordPress debug/registration/visibility/cron/update/health policies
  and delivery freshness. Existing 500-profile assessment bound remains visible. Missing target
  HTTP, stale evidence, unsupported connector fields and truncated scans do not become passes.
  Inventory, HTML and cached Site Health observations remain distinct.

Not implemented in this delivery:
- Remote SEOPress/LiteSpeed write controls, installations, automatic plugin/theme/core updates,
  image optimization or rollback. management_enabled=false; no advertised executable action.
- Complete malware scans, backup/SMTP provider adapters, visitors/session recording, historical
  activity reconstruction, all-page browser checks or automatic whole-site standards after each event.
- Full planned SEO catalogue (e.g. complete sitemap membership, reciprocal hreflang, orphan/depth
  graph, GSC URL Inspection/cluster comparison) is not yet implemented by the new checks.
- Continuous CMS delta cursor independent of events; daily inventory is the recovery mechanism.
  Low traffic or disabled WP-Cron needs host scheduling. A coverage gap cannot reconstruct history.

Deployment: normal staging script installs additive Laravel tables and restarts workers.
Then download connector 1.1.0 from the existing connector screen and replace the installed plugin.
Existing pairing remains; WordPress init creates the local outbox. First successful heartbeat
enables reconciliation. Scheduler and collection workers must run. Verify live pairing, event
redelivery, scoped deletion, filtered history, and standards before operational acceptance.

## Website integration collection controls — 2026-09-10

Implemented in staging source; no clone, tests, formatter, build, browser UAT or deployment run,
as explicitly requested by the operator. This is not a verified runtime/DONE claim.

- Website integration offers General (public HTML/TLS + paired WordPress), Public, WordPress full
  inventory, and PageSpeed scopes. PageSpeed is explicit in this screen; other existing callers'
  family defaults are unchanged. Missing CMS/PageSpeed connections are checked on the server.
- Connector 1.1.0+ delivery state exposes daily/three-day full inventory cadence and pause/resume.
  Event-driven refresh remains in bounded batches between inventories. Pause affects future
  admissions, not an active run or receipt/audit recording; manual collection remains available.
- Display last receipt, automatic reconciliation/full inventory, central pending event count,
  stale receipt warning and retry errors. Pending count is stored unprocessed events, not the
  sender's last-reported outbox size. Automatic inventory timestamps exclude manual collections.
- Source statuses use latest dataset attempts per source, independent of the most recent overall
  run. Latest-run counters remain explicitly latest-run counters. Queries no longer hydrate the
  entire collection history each poll; idle screen refreshes every 30 seconds.
- Collection console/history show scope and automatic trigger. PageSpeed-only runs have a valid
  progress denominator. Admission through the Website orchestrator rejects another active run
  under a short per-asset cache lock; reconciliation ticks also use a shared lock.
- Fix full-inventory reconciliation advancing past the first 50 events: only the processed event
  batch advances the cursor after success, retaining later URL refresh work. No incoming history
  is deleted. Failed/partial work retains its cursor, retrying later. At most two automatic
  reconciliation runs remain active; busy/unsupported candidates are deferred.
- A missing recent heartbeat no longer silently suppresses periodic recovery inventory after the
  first supported delivery. Disconnected/unpaired sources are excluded; old plugin versions wait.
- New additive preference columns/indexes require the normal staging migration. Shared cache,
  scheduler and collection workers are required. Keep Connector 1.1.0+ installed and WP-Cron or
  host cron running. General public crawl/PageSpeed are not automatically run daily. No remote
  CMS mutation, paid enrichment or AI request is introduced.

Remaining acceptance: migrate staging, exercise scope selection and pause/resume, confirm delayed
event batches/cursor recovery and source timestamps against a real paired site. No such acceptance
has been performed in this change.

## Standards workspace revision — 2026-09-10

Operator requested standards after the Connector and Website integration updates.

- Deterministic catalogue now has 52 visible definitions (37 general, 15 WordPress); 7 expert
  criteria remain archived. Eight additions cover HTML canonical-target noindex, hreflang
  self/return links, WP HTTPS home setting, plain permalinks, cache declaration, outbox setup
  declaration and post-gap inventory recovery. Two existing length heuristics remain disabled
  by default. Existing enabled/disabled overlays are retained; no catalogue seed is required.
- Website / Google Ads / Meta Ads navigation, populated category counts, platform/status/search
  filters and 20-row pagination replace the unbounded card list. Ads categories remain explicitly
  empty. Active administrators can change enabled state and low/medium/high severity, and restore
  a definition's shipped defaults. Existing settings JSON stores only validated severity overrides;
  arbitrary executable checks cannot be submitted. Historical runs remain unchanged.
- Current completed raw TLS and robots.txt collection rows feed site-level assessment, preferring
  newer evidence. The TLS check is explicitly certificate expiry only, not trust-chain/host
  verification. HTTP-to-HTTPS and sitemap still require their existing diagnosis evidence; missing
  observations stay unknown. No fresh network request or paid data is started by assessment.
- Hreflang inspection reports truncation and resolves against the HTML base URL. Return-link
  checks require fresh full target HTML and observed successful HTTP; external/uncollected targets
  are unknown. This is HTML-only coverage, not sitemap or HTTP-header hreflang validation.
  Canonical noindex covers HTML robots/googlebot directives, not X-Robots-Tag or actual indexing.
- WP inventory freshness is four days, supporting the three-day inventory option; update-cache
  freshness remains two days and delivery review remains 30 minutes. Invalid/future timestamps
  and absent extension inventory cannot become passes. WP freshness state participates in cached
  result identity. Missing stored HTML contributes to incomplete coverage.
- Cache declaration is a review signal, not measured speed or cache-hit proof. Outbox setup checks
  the connector's declared installation marker, not a database write probe. Gap recovery cannot
  reconstruct lost audit events. No automatic site mutations or plugin installs are implemented.
- Assessment rows open 25-row result details with state filters, URLs, reasons and observed values,
  including unknown/pass/not-applicable outcomes. Site checks are stored explicitly on new runs;
  old reports without these details request reassessment. Proposed actions for site checks use
  site-level wording. Saved severity applies to new proposals, with verified target blockers still
  forced high. Existing human approval/Findings/Recommendations pipeline remains in place.
- Existing 500-profile, 5 MB HTML and scoped evidence bounds remain. No all-page scale claim.
  Broader sitemap graph/orphan analysis, full PHP support/vulnerability feeds, GSC URL Inspection,
  browser rendering, remote speed repairs and Ads standards are outside this change.

Verification: direct GitHub source inspection only. No clone, tests, formatter, build, browser
acceptance or server execution performed, per operator instruction. No new migration required
beyond the already committed integration migrations. Live rendering, stored-fact compatibility,
queue execution and real operator acceptance remain unverified.

## 2026-09-10 — WhatsApp reply assistant (staging only)

Owner-authorized simple Sales menu `/whatsapp`: conversations, received/sent message history,
Turkish AI reply/wait/clarify and copy. Direct Meta adapter with signature-verified durable receipts,
fixed WABA/phone binding, encrypted central credentials/message bodies, paginated inbox and own
persistent async statuses. Existing Laravel AI provider credentials and a dedicated sales.whatsapp_reply
route are reused. New message/settings changes invalidate old drafts; context is capped and labelled.
Active Admin-only access. No WhatsApp send endpoint, task creation or external mutation.
Additive migration + existing minute scheduler/default Redis Horizon required. Initial API setup,
webhook subscription and Coexistence eligibility remain external. History/echo support only covers
received provider events; no guarantee of complete phone history. Media not interpreted; delivery,
edits/deletions, global Activity and full Agent/Skill execution ledger not implemented in this slice.
Contract: docs/product/WHATSAPP_ASSISTANT.md. Source reviewed only; no tests/build/formatter, dependency
installation, runtime/migration execution, live Meta/AI UAT or host deployment. Not DONE/live accepted.
All changes are on chatgpt/search-demand-foundation; no main edits and no PR.

## WhatsApp credential form correction — 2026-09-10

Failed settings saves previously cleared all three password inputs via finally/dehydrate and showed
an unnamed first-missing error. Password inputs now stay browser-local (wire:ignore, DOM refs), are
submitted only as action arguments, and clear only on explicit successful save. Validation failures
retain unsaved inputs in the current open form, not in server snapshots or persistent browser storage.
Field-specific messages list every missing credential; server-derived presence flags distinguish
stored credentials from blank edits. Reads use a fresh provider-credential relation query. Existing
stored values remain write-only and blank submissions preserve them. Stale save errors reset before
new save attempts. No credential/account data migration or provider mutation is performed.
Source-reviewed fix only: no tests, build, formatter or live deployment run. Supersedes earlier
notes about clearing fields after failed saves. Number mismatch remains a separate configuration issue.

## WhatsApp setup and action feedback correction — 2026-09-10

Initial WABA/phone binding corrections are now allowed only with no conversations and no
non-completed receipts. Completed ignored receipts are retained. IDs compare as trimmed strings;
existing history continues to block rebinding with field-specific explanations. Receipt signature
validation/persistence and settings changes serialize on the integration row. No data is deleted.
The form distinguishes stored IDs, stored secret presence and unsaved secret edits, offers visibility
for newly typed secrets, and shows saving/success/failure feedback beside the actions. Failed saves
preserve input; controls are disabled during save. API checks use persisted queued/error/result
states, timestamps, duplicate-click suppression and automatic result refresh. Request IDs prevent
an older result overwriting settings saved during the HTTP call. Two-minute queue delays show a
retry/help message. Separate WhatsApp App Secret guidance leaves Meta Ads credentials untouched.
Added PHPUnit coverage for initial correction, receipt/history guards, blank credential preservation
and stale API results. Execution was attempted but unavailable: this workspace has no PHP executable
or installed vendor/Pint. No PHP/Blade compilation, full browser UAT, Meta verification or server deploy
was performed. The actual form submit handler passed isolated JavaScript checks for success,
validation rejection and network failure; this is not browser UAT. Source reviewed; runtime
acceptance remains pending, not DONE.

## 2026-09-13 — Free public-source Intent Radar (staging)

Owner authorization: implement the agreed fastest no-paid-API/no-AI source-monitoring slice;
write directly to chatgpt/search-demand-foundation, no main/PR/clone/test/server deployment.
The current free path supersedes the paid-only UI described in the historical Batch B below.

- Existing SalesSearchProfile/Signal/RadarRun and sales activity history are reused. Profiles
  may bind the global ServiceCatalogItem and its live names/matching keywords, with optional
  additional/excluded terms and a location expression. Built-in aliases cover website, SEO,
  Google Ads and social advertising. Rule scores are heuristic, never purchase probabilities.
- FreeIntentRadar + RunFreeIntentRadar use the existing default worker; scheduler every five
  minutes admits one agency-wide job. Default per-profile cadence is hourly or daily. No
  DataForSEO, AI, Google scraping, browser service, new dependency or outbound message.
- Migration seeds four public category URLs: WM Aracı (job requests, Ads, SEO) and
  R10 software/web job requests, not sample leads. Operators can add same-origin RSS/Atom or public HTML lists, capped at 20 sources.
  This is bounded source monitoring, not automatic whole-web/source discovery.
- Shared source/page cache prevents per-service repeated fetches. A job reads at most two
  due lists and two due detail pages; robots is cached separately for one hour. Lists yield
  at most 100 candidates, matching examines the latest 500 candidates. Remaining due work
  gets another scheduled pass. HTML discovery requires a demand-bearing link title.
- PublicHttpFetcher/PublicUrlSafety are reused. Robots disallow fails closed; unavailable
  robots, source errors and unrecognized content are shown rather than reported as no demand.
  Same-host page identity preserves URL path/query. No login/cookie/CAPTCHA bypass.
- Detail extraction uses primary structured article/discussion data or known first-post
  markup; it does not classify forum replies/navigation as the original request. Missing
  content/date remains review-only. Known publication older than 30 days is excluded.
  Unknown market remains review-only. Cached details refresh daily, bounded by the queue.
- Profile+URL fingerprint is stable across edited text. Rediscovery preserves review,
  dismissal and prospect conversion. Historical paid/AI signals remain visible and labelled.
  Prospect handoff is the existing explicit conversion/research workflow.
- Source failures back off 1h to 24h. Interrupted queued/running sales runs are failed by
  the watchdog after 20 minutes; the next due profile is eligible again. Active owner
  and application access are rechecked before automatic work.
- Tests, PHP lint, formatter/build and live operator UAT were NOT run at owner request.
  Source URLs were inspected through public web research, not fetched successfully from
  this execution environment. Real staging robots/HTML/queue/parser behavior remains UAT.
  Especially missing publication markup, body-only requests, large/paginated archives and
  rapid deletions between list reads limit recall. This slice does not fix broader brand
  Public Discovery or add paid/semantic search.

## 2026-09-13 — Integration and Intent Radar operator review corrections

Owner-authorized direct staging revision; no main changes, PR, clone, tests or local build.
The owner additionally authorized SSH inspection/deployment for this revision. The SSH attempt
failed at DNS resolution before authentication; no server access or deployment was performed.

- Search Appearance filtered queries use automatic aggregation, removing the observed BY_PROPERTY
  invalid request. Normalizer keeps provider response aggregation provenance. Deployment explicitly
  re-admits automation accounts stopped by this known error; original runs/checkpoints remain.
- Invalid-request/persistence failures stop account-level automatic retry storms. Known earlier GA4
  landing-page recovery also recognizes this stopped state. Other errors are not silently repaired.
- Expired queue dispatch claims can republish due retrying datasets as well as queued datasets,
  respecting retry deadlines, execution leases, dependencies, terminal/cancelling parents.
- DB worker ordering uses COALESCE(activity, created_at), preventing PostgreSQL NULL-last ordering
  from indefinitely favouring previously started work over never-started datasets. Initial account
  admission precedes repeat account refreshes. Existing concurrency limits and history scopes remain.
- GSC/GA4/Ads share a read-only state presenter: queued, running, retry wait and progress delayed
  are distinct. Thirty-minute delay is an observation, not proof of a failed worker. Future retry
  deadlines do not become stalls. Success percentages count successful datasets only.
- Main account surfaces use the existing paginated automation table with locked source type,
  stored date bounds, status and collection detail drawer. Bulk operations have their own tab;
  live collection/error history is in Activity. Query mapping stays in the Queries import workspace.
  Coverage bounds do not prove uninterrupted coverage. Details distinguish earlier attempts.
- Account summaries load latest attempt/latest success per resource/provider/asset instead of
  hydrating all history. Monitor polling is reduced to 15 seconds. Operational table times use
  Europe/Istanbul; provider reporting dates keep their original meaning.
- Radar defaults to explicitly chosen catalog services, with search to add a sold service.
  Existing profiles persist; customer services are not inferred as agency offerings. Setup is
  collapsible. Missing body is labelled rather than repeating the title as an apparent excerpt.
- Public-source classification reasons are language-independent keys; existing TR/EN reason text
  is localized on read without deleting history. Primary-post parsing supports nested schema
  objects, schema type arrays, postcontent and scoped publication/author markup; no reply-body
  fallback, login bypass, paid API, AI call or invented publication date is introduced.

Validation: source review only, no PHP/Blade compilation, test suite, browser UAT or live provider
verification. Real forum accessibility/markup and server queue recovery remain deployment UAT.
The broader recent-data-first / bounded historical backfill redesign is not part of this correction;
initial 486-day GSC/GA4 scopes and existing Ads history policy still apply. This is not a DONE claim.

## 2026-09-13 — Runtime evidence: provider admission starvation

Owner supplied bf9c171 deployment output and a second status sample 16 minutes later.
All three Supervisor processes were RUNNING. GSC attempts increased 104→160 and 91→154
while completed datasets stayed at 16 each. This proves execution attempts continued,
not by itself that pages/checkpoints advanced. Ads attempts stayed at 3185 and 832;
retry deadlines/errors were absent, so quota or another root cause is not yet established.
The null collection queue sink is intentional DB-worker architecture, not a missing queue.

Confirmed code/runtime mismatch: two GSC active accounts consumed the entire non-Ads
admission lane. Ninety GA4 and 27 Meta accounts waited. Admissions now allocate the existing
two-account limit per resource type, preserving actual worker count and per-account locks.
Existing Meta/GBP binding/readiness checks still apply; this does not bypass account access.
GSC/GA4 turns are capped at five API pages and resume saved checkpoints to share the worker
more frequently. Full history scope is preserved; this is not the separate recent-first redesign.
ProgressReporter now reflects persisted chunk progress on parent activity timestamps without
counting the dataset complete. CLI status derives effective state from datasets, reports rows,
API pages and retry deadline; --details prints capped sanitized error/progress lines. Deployment
includes Ads detail output so its remaining blocker can be diagnosed from actual retry reasons.

No tests, build, PHP/Blade compilation, provider UAT or SSH execution performed in this revision.
The previous deployment was successful per the owner log; this revision still requires deployment
and proof of GA4/Meta admission and advancing stored rows/pages. No overall resolved/DONE claim.

## 2026-09-14 — WhatsApp Embedded Signup and connection diagnostics

Owner approved implementing the WhatsApp status review's next actions. On the existing
chatgpt/search-demand-foundation branch: App ID/configuration setup (initial Configuration ID
1757572378897162), dedicated Facebook signup page with Coexistence selection, encrypted expiring
Admin/session-bound attempts, async token/app/scope/WABA/phone verification, explicit number choice
when Meta omits it, preserved history/rebinding guards and WABA subscription with separate retry.
User-authorized scoped setup mutation is POST WABA/subscribed_apps; no message send, automatic
migration, number registration or automatic history request. Meta Ads integration stays separate.
Provider diagnostic message/HTTP/code/subcode/trace are redacted and visible. Subscription,
callback verification, actual message persistence and history/echo observations remain separate.
Existing WhatsApp scheduler handles queue recovery/expiry; browser not needed after code handoff.
Product contract: docs/product/WHATSAPP_ASSISTANT.md. Additive migration only. No tests, build,
PHP/Blade compilation, live Meta UAT or server deployment executed. PHP/vendor are unavailable;
Pint unavailable. Source-reviewed implementation; actual app login/permissions/webhook fields and
real inbound/echo/history/AI behavior await operator acceptance. Not DONE or live-verified.

## 2026-09-14 — WhatsApp signup table mapping hotfix

Operator reported HTTP 500 on /whatsapp after the Embedded Signup release. Source inspection
found WhatsAppSignupAttempt lacked an explicit table name: Eloquent infers
whats_app_signup_attempts, while the migration creates whatsapp_signup_attempts.
Set the model table explicitly, consistent with the existing WhatsApp conversation/message/receipt
models. This corrects the same query path in inbox rendering, setup guards and background signup
processing. No schema changes or data deletion. Source reviewed; runtime environment and server
logs are unavailable. No tests, formatter, live rendering or deployment performed. Operator
deployment/confirmation of page recovery remains required; Meta connection readiness is unchanged.

## 2026-09-14 — Isolate Embedded Signup from FedCM login

Operator supplied a failing Facebook URL with scope=openid, response_type=token and
dialog_source=fedcm, followed by a second signup popup. That URL is a separate SDK login
step; the application requests config_id with response_type=code for Embedded Signup.
The connection page now explicitly sets FB.init fedCM:false, status:false and xfbml:false
to opt out of FedCM and automatic status/widget processing. Login remains a synchronous
operator click with the configured business flow and Coexistence featureType.

Meta's FedCM documentation search excerpts expose the fedCM boolean/object option:
https://developers.facebook.com/documentation/facebook-login/web/fedcm
Full documentation and SDK source could not be retrieved in this workspace. The effect of
the opt-out on the current served SDK is not runtime-verified; the operator must confirm
the unexpected OpenID popup disappears after deployment. This is a targeted mitigation,
not a claim that WhatsApp onboarding or the existing-number connection is complete.
Previously reported advanced-access error #2655111 is a separate Meta approval blocker.
No extra permission, account deletion, number registration or backend mutation was added.
No tests, formatter, browser UAT or deployment performed, per operator workflow.

## 2026-09-14 — GBP discovered-resource automation

Owner reports Google GBP access approved and 65 discovered locations. Supplied connector HTML
shows available locations with not_collected and only Manage binding. Discovery is not collection
proof. Source confirms ResourceAutomationService required GBP binding, then routed it through
CollectionLifecycle rather than the existing GBP Run/typed-table collector.

GBP now uses the existing resource automation scheduler and durable ResourceCollectionJob, with
one of ten GBP datasets per worker turn. Run metadata checkpoints completed dataset outcomes;
resource_automations.gbp_run_id references the existing GBP Run, separately from CollectionRun IDs.
This intentionally retains GBP's existing Run collector; it does NOT claim a completed migration
to the shared Collection Engine/warehouse control plane. No new scheduler, credentials or provider
store. Nullable asset ownership in existing GBP tables and runs allows unbound collection with
non-null external_resource_id provenance. Existing exact active binding is validated and retained;
no automatic customer/brand/asset creation. Old binding-only attention states are re-admitted.
Explicitly paused automations remain paused. New locations retain existing default daily cadence.

The dedicated root GBP connector embeds existing paginated TR/EN automation controls: pause, daily/
three-day interval, run now, error status, last full success, next due time, processed dataset count
and recent GBP Run outcomes/row counts/errors. Discovery count is labelled locations. No claim that
all APIs are authorized just because location discovery succeeds. Initial windows reuse 180 days
of performance and 12 complete keyword months (configurable); each refresh currently rereads these
windows. Missing/partial endpoints do not become current. 429/5xx and local pacing failures retry the affected dataset up to three attempts with increasing delay and jitter. Other partial cycles retry at normal cadence,
manual retry is available. Per-step wall-time budget and request pacing bound work; pagination caps
are partial, not complete. Within-dataset page continuation and delta-only refresh remain future
improvements. Interrupted work reuses saved completed dataset outcomes and idempotent typed writes.
No provider writes, AI analysis, competitor/rank-grid calls, or new paid providers.

Verification: source/diff review only. Per owner no tests, build or live provider calls. PHP and
vendor/Pint are unavailable. Not deployed or runtime proven; actual persisted rows, API-specific
403/429 errors, queue/scheduler operation and browser rendering require operator deployment/UAT.
Official quota guidance: https://developers.google.com/my-business/content/limits

## 2026-10-03 — AI iş kuyruğu: Claude (MCP) Faz 1 (staging)

Website-screen AI operations (`SiteAi::run`: makale taslağı, SEO analizi, fikirler, eşleştirmeler…) can be set to
"Claude (MCP, abonelik)" per operation in Ayarlar › AI işlemleri. Such a call does not reach a provider: it becomes an
`ai_tasks` row and the site operation status shows "Claude bekleniyor". MoxDOP MCP server (`laravel/mcp`, route
`/mcp/moxdop`, bearer `MOXDOP_MCP_TOKEN`) exposes list_tasks, get_task, submit_result (validated against the agent's
JSON schema, errors returned to fix), fail_task. When every open call of a run is answered the job is re-dispatched
and continues unchanged (evidence check, sector compliance gate, review, WordPress draft only on approval).
AI işlemleri screen shows the queue (open / done 7 days / failed, last 10). Other AI call sites keep the API.

Verification: PHPUnit `tests/Feature/Mcp/AiTaskQueueTest.php` (queue → MCP submit → resume → stored article; schema
refusal; fail_task; non-delegated keeps provider; token gate on HTTP; screen option). Not deployed; real Claude
routine run against production and quality acceptance (10 drafts) await operator UAT. Not DONE.

## 2026-10-03 — AI iş kuyruğu: Claude (MCP) Faz 2a (staging)

MCP server 1.1.0. The server instructions are Claude's whole working guide (facts vs notes, read only what changed,
queue loop, content, system), so a new Claude account needs only the token and a short routine prompt.
New tools: `list-brands` (active brands, brand-file sections changed since Claude's last read), `get-brand` (the
rule-built brand file as `facts` + Claude's open notes; marks sections read, cursor in `brand_memory` kind
`claude_seen`), `save-note` / `list-notes` (`claude_notes`: observation / hypothesis / proposal / followup,
append-only, supersede / close; never read by other AI operations), `content-queue` + `request-article` (starts
"Taslak hazırla" for an operator-APPROVED content title only; sending to WordPress stays the operator's click),
`system-health` (release, scheduler, stopped workers, queue waits, top error groups, Hata merkezi groups, failed AI tasks).
Eşleştir (`ClusterAudit`) waits for Claude: AI questions → match (one task per service) → gaps (one per page) → extra
ideas, each step queued at once, the pass kept, the job resumed by Claude's answers. A re-run finds an answer by
operation + input hash (`ai_tasks.input_hash`), not call position. Delegation is limited to operations whose callers
wait (`AiTaskQueue::SUPPORTED`: write_article, content_recipe, cluster_match, cluster_gaps, queries.ai_queries).
A new article draft is always a WordPress post; only an update of an existing non-blog page stays a page.

Verification: PHPUnit `tests/Feature/Mcp/McpWorkspaceTest.php` + `AiTaskQueueTest.php`. Not deployed; a real
routine run of Eşleştir over MCP and the operator's quality review await UAT. Not DONE.
`php artisan moxdop:mcp:delegate [operations…] [--api]` sets the supported operations to Claude (MCP) or back to the
route model from the shell (a new prompt version published as the first active admin); tested in `McpWorkspaceTest`.

## 2026-10-03 — Sayfa içeriği Markdown iskelet (staging)

`pages.content_outline`: the main content as a light Markdown outline (`#` headings, paragraphs, `- ` / `1. ` lists,
tables, `> ` quotes, `S: / C:` for details / dl questions, `[görsel: alt]`, `[metin](link)`), built by
`MainContentExtractor` for WordPress content and sitemap pages. Not in the content hash: filling it in on an
unchanged page saves quietly (no re-analysis), an SEO-only refresh keeps the stored one. AI operations (Eşleştir
match + gaps, content recipe, change applier, URL analysis, page summary, page mapper, GBP post) read
`Page::aiText()` (outline, else the flat text). Flat `content_text` stays for evidence and word checks.
MCP 1.2.0 adds `get-page` (stored page, outline, no live fetch). Backfill: `php artisan moxdop:pages:sync --all`
(WordPress sites through the connector inventory; sitemap sites as pages are re-read).
Verification: PHPUnit `tests/Feature/Mcp/McpPageOutlineTest.php`. Not deployed; not DONE.

## 2026-10-03 — İşletme Profili ve Meta kontrol listeleri (staging)

Sources: claude-seo `maps-gbp-checklist.md` and marketingskills `ads/references/meta-decision-system.md` (both MIT),
re-expressed as MoxDOP rules (no AI). Business Profile standards 11 → 16: `gbp:verified` (verification snapshot),
`gbp:additional_categories` (≥ 2), `gbp:contact` (website + phone; 0850 / 444 / 0800 = review), `gbp:photo_set`
(logo, cover, ≥ 10 photos), `gbp:service_area` (hidden address needs areas); `gbp:description` under 250 characters
= review. Meta checks 10 → 12: `ad_count` (a campaign feeds 14-day spend ÷ (2 × cost per result) ads) and `starved`
(an active ad running a week that got under half its fair share of the campaign's last-7-day spend). Prompts:
gbp-description-v2 (250–700), meta-creatives-v2 (refresh order hook → visual → format → text), meta-structure-v2
(ad ceiling, test campaign, +20 % budget steps, no edits on performing ads, ~50 results / week), meta-landing-v2
(higher-intent lead forms, 1–3 questions). Operator-edited prompts are not overwritten.
Verification: PHPUnit `tests/Feature/Gbp/GbpStandardsTest.php`, `tests/Feature/Meta/MetaScreenTest.php`. Not
deployed; operator review of the new findings on real profiles / accounts awaits UAT. Not DONE.

## 2026-10-03 — Claude (MCP) devri: site grubu, görsel alt metni, şema koruması (staging)

Delegable to Claude (`AiTaskQueue::SUPPORTED`): `site.page_categories`, `site.service_pages`, `site.cluster_pages`,
`site.image_alts`. Their batches are all asked at once (the re-run with the answers sees the same batches) and the
run ends `queued`; Kurulum sonrası hazırlık and Haftalık yenileme stop at a waiting step (categories → service pages
→ cluster pages read each other). Callers outside a site job (rule-only passes) keep the provider route.
Görsel alt metni (`ImageAlts`, Öneriler › "Görsel alt metni öner"): WordPress images without alt text that are on a
stored page (uploaded to it / featured image) → `site.image_alts` from file name, image title and page (names that
say nothing are skipped; ≤ 125 characters; sector forbidden phrases blocked) → one suggestion per page → "Onayla ve
WordPress’e gönder" = `alt_text` site fix (ADR-070, undoable). An image is proposed once.
"AI ile yap" (site-apply-change-v6) gets `existing_schema` (SEO plugin of the object: Yoast / Rank Math / SEOPress
and the base types it prints; types MoxDOP wrote before); a proposal with only those types is dropped in code.
Queue routine: every 2 hours 07:57–19:57 Istanbul.
Verification: PHPUnit `tests/Feature/Site/ImageAltsTest.php`, `tests/Feature/Mcp/McpWorkspaceTest.php`. Not deployed;
not DONE.

## 2026-10-03 — Claude (MCP) devri: kümeleme, sorgu planı, marka işlemleri (staging)

Delegable to Claude too: `queries.cluster`, `queries.cluster_review` (daily clustering of the autopilot and "AI ile
kümele"), `queries.plan_sectors`, `queries.plan_services`, `queries.plan_filters`, `queries.scan_filters`,
`queries.filter_rules`, `queries.assign_services`, `brand.services`, `brand.candidates`, `brand_setup.assistant`.
`queries.triage` (15-minute autopilot triage) stays on the API. A clustering step stores the part it asked
(`pending` in the run state) so the answer is found although triage adds queries meanwhile; calls whose pack drifts
(samples, metrics) are named by a stable slot in the run (`AiTaskQueue::answer(..., $slot)`). Waiting steps show
"Claude bekleniyor" and are not closed as stale (kept 5 days); Otomatik kur stays "building" without becoming stuck.
Inline calls (outside a resumable job, e.g. `moxdop:brand-candidates --sync`) of a delegated operation never fall
back to the provider route (yakup, 2026-10-03): the call fails (`AiTaskQueue::blocksInline`); `--sync` queues the job.
Verification: PHPUnit delegated tests in `QueriesScreenTest`, `QueryPlanWizardTest`, `QueryBulkAndAssignTest`,
`BrandServicesAndSettingsTest`, `BrandSetupAssistantTest`, `BrandCandidatesTest`. Not deployed; not DONE.

### Customer / brand / digital asset pages — website v2 look and report fixes (2026-10-03)

Report `raporlar/marka-sayfalari-raporu.md` (sample brand Panorama Ankara). All three lists and the brand page use
the website screen's v2 style (compact header, underline tabs, ring cards, coloured chips, inline Turkish text).
- **Lists** (`PortfolioSignalsReader`): Açık iş (`Suggestion::actionable()`, same rule as brand Özet, per channel /
  asset), Veri durumu (worst source of `DataStatusReader`), Dikkat with reason; retired Finding / WorkTask counts
  removed; customer and brand lists paginated (50) with grouped queries; brand search matches the domain; channel
  dots; customer detail without its single tab; active/passive switch checked server side (Admin or a responsible
  user); customer sector comes from its brands (`industry` only a fallback). Dijital varlıklar is in the menu again,
  with all filters in the URL (`?brand=`, `?customer=`, …); numeric account names read as "Marka · Meta reklam hesabı
  …9017". Not done: "Kurulum x/y" in the brand list.
- **Brand page:** four tabs Özet / Dijital varlıklar / Bilgi dosyası / Ayarlar; old `?tab=` values map in `mount`
  (channel tabs open Özet with `?kanal=`). ★ is `priority='main'` only (`is_priority` kept in sync, backfill
  migration `2026_11_27_090000_brand_offerings_priority_sync`). Setup checklist counts an account only when data has
  arrived, a local business needs a city; each gap has "Düzelt →". Goals / constraints edited only in Ayarlar › İş
  bağlamı; the dossier (MCP `get-brand` facts) gains İş bağlamı, service pages (hub first) and per-channel open work.
- **Discovered assets:** "Markaya bağla" on brandless rows (website → brand; account through `PortfolioGroupCreator`),
  candidate rows on two lines with "Güven %N" and "Sektör belirlenmedi"; Entegrasyonlar links to the page and its
  "boşta" count opens the brandless filter.
- **Google Ads closed accounts:** `CUSTOMER_NOT_ENABLED` in historical discovery stores `metadata.not_enabled_at`;
  automatic collection readiness is `not_enabled` ("Hesap kapalı") for 7 days, then retried; success clears it.
- **Business Profile checks:** titles reworded (İşletme adına eklenen kelimeler, Profil doğrulaması, Ad / adres /
  telefon tutarlılığı); rules unchanged.
- **Livewire:** `DropInvalidLivewireUpdates` drops update keys that cannot name a property (`$`, empty), the cause of
  "Public property [$] not found" on the header AI indicator.
Verification: PHPUnit (`Portfolio/PortfolioSignalsTest`, `Portfolio/BrandOverviewTest`, `Portfolio/BrandWorkspaceTest`,
`Brand/BrandDossierTest`, `BrandCandidatesTest`, `ResourceAutomationRecoveryTest`, `AiLiveOperationsTest`,
`PanelDesignFreezeTest` + updated UX tests). No UAT; not deployed; not DONE.

### Automatic AI for work delegated to Claude (yakup, 2026-10-03)

`AiBudget::automaticAllowed` also allows an operation delegated to Claude over MCP (no API cost), and the gate
`site.weekly_refresh` opens when one of its site steps is delegated. So the nightly site flow (after the brand file)
and the Monday weekly site refresh queue Claude tasks with nobody clicking; operations still on the API (page
summaries, analysts, care agent, Şef) keep waiting for a click and are stopped call by call by the spend guard.
Claude tasks never count toward the daily ceiling. Verification: `Mcp/AiTaskQueueTest::test_work_delegated_to_claude_may_run_without_a_click`.
Not deployed; not DONE.

### Geliştirme havuzu: Claude improves MoxDOP itself (yakup, 2026-10-03)

Ayarlar › **Geliştirme havuzu** (`/settings/improvements`, Admin). Table `system_changes` (kind bug | collection | page
| design | improvement, fingerprint so a finding is proposed once, status proposed → approved → in_progress → ready →
deployed → verified | failed, rejected). Claude proposes over MCP (`propose-change`) from `system-health` and the new
**Sayfa taraması** (`screen-checks`: `ScreenChecker` renders every operator screen plus sample brands, customers,
website tabs and one asset per channel as an Admin, nightly 04:50 `moxdop:screens:check` and after each deploy mark;
status, ms, queries, exception @ file:line, text outline). The operator approves / rejects (with a note) or writes a
request (approved at once). Claude codes approved ones (`list-changes`, `update-change step=start|ready|give_back`),
pushes the branch and writes commit + deploy commands; "Deploy bekliyor" shows them with Kopyala and **Deploy
tamamlandı** (also detected when the live release SHA equals the commit); Claude then checks (`step=verified|failed`).
Admins get a notification when a deploy is ready or a check fails. Claude never deploys.
Verification: `Operations/ImprovementPoolTest`. Not deployed; not DONE.

### Genel işler and phone notifications (yakup, 2026-10-03)

**Genel işler** (`/work`, `operator.work`, sidebar entry after Bugün; Panel stays as it is) lists every operational
brand's open work in six tabs: Web site SEO içerikler (channel `search` except the technical types; content titles show
their line stage: başlık onayı → yazılıyor → okunacak → siteye gönderildi), Teknik SEO (`title_description`,
`internal_links`, `technical_seo`, `conversion`, `image_alt`), Teknik sağlık (active website asset alerts), Google Ads,
Meta Ads and Google İşletme (their suggestions + active asset alerts). Freshness-only alerts are left out. Rows are
ordered by urgency (alert severity / suggestion priority), with a brand filter and an urgent / total count on each tab.
`WorkDesk` reads the existing `suggestions` and `asset_alerts` and acts through the same services as the asset
screens: Onayla (website suggestions via `SiteSuggestions`, Google Ads via `GoogleAdsSuggestions`), Yaptım
(`AnalystDecisionStore::markDone`, outcome baseline), Geri al, Reddet, 7 gün ertele. Writes to a site or Business
Profile still happen on the asset screen ("Aç"). "Yapıldı (30 gün)" lists applied work with the system's check.
- **Yaptım + automatic detection (both, yakup's choice):** new `suggestions.verification` / `verified_at`. "Yaptım" on a
  system-check item (`ads_check`, `meta_check`, `gbp_standard`) waits as `pending`. The next run of that check
  (`WorkVerifier`, called from `GoogleAdsSuggestions::syncChecks`, `MetaChecks::sync` and
  `GbpSuggestions::syncStandards`) marks it `confirmed` when the check passes, or `still_seen` when it still fails on
  data pulled at least 12 hours after the click. An open check item whose check now passes is applied by the system
  (`auto`, "Sistem kendisi fark etti"). Checks without data change nothing. AI suggestions are closed only by "Yaptım".
- **Phone notifications (Web Push):** "Telefona bildirim aç" on Genel işler registers `public/sw.js`, subscribes with the
  VAPID key and stores the device (`push_subscriptions`). The key pair is created once in `web_push_keys`, with the
  private key encrypted. `PushNotifier` gains the `browser` channel. It sends an empty VAPID-signed push (no payload,
  so no message encryption library is needed), and the service worker reads the text from `/push/latest`. Only high /
  critical work alerts go to phones: site down, ad account blocked or budget out, conversions stopped, ads
  disapproved, bad unanswered review, account access lost, and the operator's own reminders. Software error,
  watchdog, backup and new-account messages stay on ntfy / Telegram. 404 / 410 from the push service removes the
  device. "Dene" sends a test push. iPhone needs the page added to the home screen first (`manifest.webmanifest`).
Verification: `Work/WorkDeskTest` (tabs, Yaptım → confirmed / still seen / auto, approve rules, snooze, subscribe, VAPID
JWT verified with the public key, system alerts not pushed, expired device removed). Not deployed; real-phone delivery
not tested yet; not DONE.
- **Fixes after the first deploy (yakup's page review, 2026-10-03):** a seventh tab **Marka kurulumu** takes `brand_gap`
  and `brand_audit` out of SEO içerikler. Those rows get their own buttons through `WorkDesk::act`: "Onayla ve yap"
  (`BrandGaps::apply`, only when the gap has an automatic fix; otherwise "Elle yap →" opens the gap's link), "Düzelt"
  and "Doğru, bırak" (`BrandAudit::fix` / `accept`). Küme çakışması rows get "301 ile birleştir"
  (`ClusterOverlaps::redirect`, only for the REDIRECT recommendation) and "Ayrı kalsın" (`keep`). A written article gets
  "WordPress'e taslak gönder" (`ContentPlanner::sendDraft`) and "Yazıyı oku →" (website İçerik tab with `taslak`). The
  generic Onayla and Yaptım are refused for setup work and 301 merges, because the system closes those itself. The
  "kim yapar" lines are corrected. A done time shows only on applied rows: a gap reopened by `BrandGaps::sync` showed
  its old "yapıldı" time while open. `WorkDeskTest` covers these.

- **İçerik fikirleri (yakup, 2026-10-03; layout "İkisi birden" chosen after review):** Genel işler › Web site SEO
  içerikler opens with the operator's steps across every site (`ContentBoard::queue`): Onay bekleyen başlıklar,
  Okunacak yazılar, Gönderildi (30 gün). Each step is grouped by site, most urgent first, with the site's languages.
  A site with several waiting titles has "Hepsini onayla ve yazdır" (at most 25 per click, `writeAll`). A title being
  written stays with a "yazılıyor…" marker. The per-site box (the cluster-card style of the website Kümeler board)
  moved to the website İçerik tab. The box shows the site's languages (page count per language) and three steps:
  - **Yazılacak:** the title waits, or is being written. "Yaz" approves the title and queues `WRITE_ARTICLE` in one
    click; Claude writes it over MCP.
  - **Okunacak:** the article is written, or held by a sector rule ("Yeniden yaz"). "Oku" opens a reader with every
    language version and "WordPress'e taslak gönder".
  - **Gönderildi:** the draft was sent in the last 30 days.
  Other SEO work of the tab stays as the list under "Diğer SEO işleri". A sent idea (`action.article_write_id`) is no
  longer counted as open work.
- **Languages:** `ContentPlanner::siteLanguages` combines the languages of the site's pages with the asset's own
  setting, the main language first. Before writing, the operator can pick the article's language (`action.language`).
  After writing, "+ {dil} yaz" writes the same plan in another site language as a translation
  (`action.translations.{lang}`; `WRITE_ARTICLE` with a `language` param). "Taslak gönder" sends the source and its
  translations as linked Polylang drafts (ADR-076, `sent_languages`); a translation written later is sent on its own.
  A language the site does not use is refused. `ContentBoardTest` covers this. Not deployed; a real Polylang site is
  not tested yet; not DONE.
- **Öncelik puanı (yakup, 2026-10-02; coded 2026-10-04):** no weekly title cap; every content idea gets a rule-based
  1–100 score (`ContentScore`, no AI): 100 × (0,35 Talep + 0,25 Hizmet + 0,25 Boşluk + 0,15 Niyet). Talep = the
  cluster's search volume or the brand's Search Console impressions on it, log scale against the brand's largest
  cluster (0,5 when no data); Hizmet = ★ 1 / other active service 0,6 / none 0,3; Boşluk = from the page score
  (`cluster_page_scores`); Niyet = hizmet / lokasyon 1, SSS 0,7, blog 0,5. Waiting titles and sites sort by it, the
  chip "Puan N" explains the parts, and "Hepsini onayla ve yazdır" writes the highest first. `ContentScoreTest`.
- **Yazım kuralları (2026-10-04):** writer prompt `site-write-article-v8` adds the SEO rules (main query in title,
  meta title and first paragraph; subtopics as headings; no stuffing; link to the service page; areas named
  naturally). `ArticleSeoCheck` checks the written article by rules (main query in title / first paragraph, repeats,
  internal link, service areas) and the reader shows "SEO kontrolü"; it never blocks. A forbidden phrase `{marka}`
  (Sorgular › Yasaklı ifadeler) stands for each brand's name and domain root (not a service-word domain) and applies
  to AI content only (`ai_draft`, `seo_brief`). `moxdop:prompts:adopt-default site.write_article` publishes the new
  default prompt on a published / Claude-delegated operation and keeps its model. `ArticleRulesTest`. Not deployed;
  not DONE.

### Microsoft Clarity behaviour (yakup, 2026-10-03)

The Ayarlar tab of each website saves a Clarity project id (for the dashboard link) and the Data Export API token
(`clarity_projects`). The token is encrypted and never sent back to the browser. The tab also has "Şimdi çek" (at most
once an hour), "Durdur" and the last pull state or error. `moxdop:clarity:pull` runs daily at 07:03 Istanbul and
queues `PullClarityJob` for each enabled operational site. It makes one `project-live-insights` request (last 24 h,
URL × Device; the API allows 10 a day and at most 3 days). `ClarityCollector` folds query-string variants per page URL
and device. It stores sessions, rage / dead click, quick back, JavaScript error, error click and excessive scroll
shares, scroll depth and active time as the previous day (`clarity_page_days`, matched to `pages`, kept 120 days).
`ClarityRules` (no AI) uses a 7-day, session-weighted view; pages with fewer than 30 sessions are skipped. Each rule
has a fail line and a lower pass line:
- JavaScript hatası: fail ≥5%, pass <2%.
- Öfkeli tıklama: fail ≥4%, pass <2%.
- Tıklanan ama çalışmayan alan: fail ≥12%, pass <7%.
- Hızlı geri dönüş: fail ≥20%, pass <12%.
- Sayfa okunmuyor: average scroll fail <35%, pass ≥45%.

A failing page becomes a `clarity` suggestion. It is attached to the site and page, carries a mobile note, and shows in
Genel işler › Teknik sağlık and the site's Yapılacaklar, with "Clarity'de aç ↗". It is manual work: Yaptım is followed
by `WorkVerifier` (`clarity` → `check`), and a clear pass closes the item by itself. An item the system closed itself
reopens if the page fails again. Nothing is written to the site, and the Clarity tag is installed by the operator.
Verification: `Site/ClarityTest` (token encrypted and write-only, hourly pull guard, API answer folded per page ×
device, rule → work in Teknik sağlık, a pass closes it itself, 401 shown on Ayarlar). Not deployed; no real Clarity
project tested; Ads / Meta landing-page link not built yet; not DONE.
