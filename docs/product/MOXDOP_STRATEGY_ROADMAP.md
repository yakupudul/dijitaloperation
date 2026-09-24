# MoxDOP — Strateji ve Yol Haritası (2026-09-26)

Sahibi: tek kişilik dijital ajans. Amaç: hizmet verilen markaların dijital varlıklarını iyileştirmek; ajans işlerini
toplamak, planlamak, yapılacakları belirlemek, müşteriye raporlamak. Bu belge, 2026-09 denetimlerinden (varlık
sayfaları, entegrasyonlar, sistem şişmesi, AI/veri/ölçek, Perfex modülü, sektör uyumu) sonra sahibiyle kararlaştırılan
yönü kaydeder. Faz sırası sahibi onayıyla değişebilir; değişiklikler burada tutulur.

## İlkeler

1. **Algoritma yürütür, AI el olur.** Teşhis ve önceliklendirme kurallarla yapılır. AI yalnız operatör tıklamasıyla
   çalışır; arka planda token harcanmaz. Her AI işi aylık tavan ve tahmini maliyetle gösterilir.
2. **Veri işe yaramıyorsa tutulmaz.** Her tablo için soru: bir ekran, kural veya AI kullanıyor mu?
   - Altın veri (asla silinmez): GSC sorguları, Ads arama terimleri/anahtar kelimeler, GBP arama terimleri, DataForSEO
     kelime verisi, AI çıktıları, görevler, ölçülen sonuçlar.
   - Günlük performans: 25 ay; daha eskisi silinmeden önce aylık toplama çevrilir.
   - Ham sağlayıcı yanıtları / HTML kopyaları: işlendikten 60–90 gün sonra; telemetri 30 gün.
   - GBP içeriği: Google'ın 30 gün kuralı.
3. **Pasif müşteri = durmuş akış.** Müşteri (veya varlık) pasifken veri çekimi, plan, uyarı, AI durur; veri silinmez;
   aktif olunca kaldığı yerden devam eder. Hiçbir markaya bağlı olmayan hesap çekilmez.
4. **AI çıktısı varlıktır.** Yeniden üretmek eskisini silmez (Üretim Arşivi: sürüm, "kullandım/yayınlandı", 👍/👎).
   Aynı istek için taze çıktı varsa önce o gösterilir.
5. **Tek iş listesi, tek döngü.** Topla → Teşhis → Önceliklendir → Yap → Doğrula (anında) → Ölç (28/56 gün) → Öğren →
   Raporla.
6. **Beyin ajans içidir.** Sektör desenleri markalar arası öğrenilir (ölçülen sonuçlar + sayfa yapıları + sahibin
   yöntemleri). Bir müşterinin verisi başka müşterinin raporunda asla görünmez. (Mevcut "cross-brand forbidden" kuralı
   bir ADR ile gevşetilecek.)
7. **10 markada nasılsa 100 markada da öyle.** Sayfalar sayım/sayfalama/önbellek kullanır; veri çekimi zamana yayılır;
   sayfalar render sırasında sağlayıcı çağırmaz.
8. **Güvenlik.** 2FA; sistem yedeği; açık metin anahtar, kapalı TLS doğrulaması, gizli admin gibi kalıplar yok.
9. **Harici yazma dar ve kayıtlı.** ADR-064 istisnaları (Ads paylaşılan negatif liste, WordPress taslağı); yeni
   istisnalar (WordPress onaylı güncelleme, Google Takvim) ayrı ADR ile.

## Faz sırası

| Faz | İçerik |
|---|---|
| 0 – Acil ✅ | Otomatik AI'yı kapat (SEO planı LLM varsayılanı, WhatsApp otomatik öneri, marka kurulumu sonrası plan), pasif müşteri + bağsız hesap kapısı, SEO haftalık sırası tüm siteler (ilk 25 değil), GBP 30 gün silme işi, yetki açıkları (AI yönlendirme kaydı, otomasyon paneli, bağlama), 2FA |
| 1 – Temizlik + saklama ✅ | Kullanılmayan katmanlar (ajans beyni stack'i, ekip iş akışları, Filament kopyaları, demo fikstürleri, ölü AI kodu, Instagram sayfası), hiç okunmayan veri toplama; saklama politikası + aylık toplama. **Not:** Arama talebi hattı silinmeyecek; Faz 2b'de sadeleştirilip otomatikleştirilecek (sahibinin sorgu → sayfa → rakip hedefi) |
| 2 – Portföy ✅ | "Keşfet ve Grupla" toplu oluşturma, müşteri listesinde aktif/pasif düğmesi, birleşik Rakipler, onboarding kontrol listesi |
| 2b – Talep hattı ✅ | Hizmet + hizmet bölgesi → sorgular (GSC/Ads/GBP/DataForSEO, otomatik atama) → sorgu kümeleri → hangi sayfa → bölgede SERP rakipleri → rakip sayfa HTML analizi → karşılaştırma → strateji/SEO görevi |
| 3 – Ölçüm temeli ✅ | Marka dönüşüm sözlüğü, takip sağlığı, GTM (salt okunur; sayfa HTML'inden etiket tespiti, GTM API'siz), GA4 sayfa × kanal, Sayfa Karnesi, marka/marka dışı ayrımı |
| 4 – Entegrasyonlar (E1–E4) ✅ | E1 hatalar/güvenlik; E2 kendi kendini onaran akış (yeniden bağlanınca devam, günlük yeniden deneme, token süre uyarısı, zamanlayıcı/işçi izleme, deploy kontrolleri); E3 tek merkez + tek kalıp; E4 veri seti tazeliği, backfill, maliyet ekranı, eklenti sürüm takibi |
| 5 – Sağlık paketi + uyum + arşiv ✅ | Sektör paketleri (eklenti gibi); Sağlık: yasaklı ifade/zorunlu uyarı/hedefleme kuralları (RG 12.11.2025 / 33075 — hukuk görüşü alınacak); uyum denetçisi (AI taslakları, canlı reklamlar, site, GBP); Üretim Arşivi |
| 6 – Asistan ✅ | Bugün ekranı, hatırlatıcı + telefon bildirimi (ntfy/Telegram) + Google Takvim, alan adı/hosting/SSL yenileme + ücret, uptime izleme, WhatsApp ↔ müşteri + satış asistanı |
| 7 – Beyin ✅ | Yöntem Kütüphanesi (ekrandan düzenlenen yöntem/eşikler), sektör desenleri, sonuca dayalı önceliklendirme, "Yapıldı"da anında doğrulama + geri gelen sorunu yeniden açma, varlıklar arası tutarlılık kontrollerinin (7 `Analyze*ConsistencyJob`; Faz 1'de tetikleyicisiz kaldı) haftalık Danışman'a bağlanması, Perfex Ads analitiği (anomali dedektörleri, n-gram, negatif adaylar, QS geçmişi, sayfa↔kelime uyumu, öneri doğrulama/erteleme) |
| 8 – Rakip/yorum istihbaratı + ajans satışı | Yorum kazıyıcı (iç kullanım, sahibi riski üstlendi), Meta Reklam Kütüphanesi, rakip izleme, harita grid sıralama takibi, harita yığma (My Maps/KML) deneyi, backlink fırsat motoru, dış denetim/prospect raporu, ajans lead kutusu (yalnız ajansın kendi lead'leri) |
| 9 – Rapor v2 + eklenti v2 | Looker yerine aylık rapor (kanal KPI, grafik, karşılaştırma, yapılanlar ve etkisi, AI yorumu), WordPress eklenti v2 (sağlık, tek tık panel girişi, onaylı güncelleme) |

## Ek kararlar (2026-09-26, ikinci tur)

### Sade menü (hedef)
- **Bugün** (ana ekran: uyarılar, bu haftanın işleri, yenilemeler, cevap bekleyen mesajlar, "kime ne yazmalı")
- **Portföy:** Müşteriler (aktif/pasif düğmesi) · Markalar · Varlıklar
- **İşler:** tek iş listesi (Danışman + SEO Görevleri + kendi görevlerin) · Uyarılar
- **Pazar:** Sorgular (kümeler sekme olarak) · Hizmetler · Rakipler · Harita sıralaması · Backlink fırsatları
- **Satış (ajans):** Lead kutusu · Potansiyel müşteriler (+ dış denetim) · Niyet Radarı · WhatsApp
- **Raporlar**
- **Sistem:** Entegrasyonlar · Ayarlar (AI ve maliyet, Yöntem Kütüphanesi, sektör paketleri, arka plan işleri)

Menüden çıkanlar: Fırsatlar, Bulgular, Öneriler (tek iş listesine), Açık Web Keşfi (marka kurulumu ve dış denetime),
Dosyalar (marka sekmesine), Arka plan işlemleri (Ayarlar'a), ayrı Sorgu kümeleri (Sorgular'a).

### Faz 2b – Talep hattı (ayrıntı)
Bugünkü durum: sorgu içe aktarımı sektörü atıyor, hizmeti yalnız birebir kelime eşleşmesiyle atıyor; marka, hizmet
bölgesi (şehir/ilçe metinden silinip atılıyor), küme ve markalı/markasız ayrımı atanmıyor; GBP otomasyonda yok;
"sorgu → sayfa" için üç ayrı model var; SERP tek pazar kodu ile (bölgeye göre değil); hattın hiçbir adımı
zamanlanmamış ve testsiz.
Hedef haftalık marka işi: içe aktar (GSC + Ads + GBP) → markaya ve bölgeye otomatik ata (Türkçe ek toleranslı eşleşme,
konum çıkarımı → BrandServiceArea) → hizmet sayfasına bağla (tek model: ServicePageAssignment + küme alt grupları) →
her hizmet için en değerli N sorguyu bölge bazlı SERP'e sor (marka başına aylık USD tavanı, parmak izi tekrar kullanımı)
→ ilk 10'da tekrar eden rakipleri öner/ekle → rakip sayfa HTML'ini çek → karşılaştırma (kural + tıkla AI) → SEO
Görevleri'ne görev yaz. Önce eksik testler.

### Harita sıralama takibi (Faz 8)
DataForSEO `serp/google/maps` + `location_coordinate` ile N×N grid (7×7 önerilen), standart kuyruk; eşleşme GBP
place_id → cid → alan adı+telefon. Metrikler: ARP, ATRP, SoLV; her noktada ilk 20 saklanır (rakip sıklığı ücretsiz).
Görsel: Leaflet/OSM ısı haritası + rakip pinleri + GBP pin konumu kontrolü (düzeltme yazma değil, rapor).
Maliyet (2026-09 fiyatları, doğrulanacak): 30 lokasyon × 5 kelime × haftalık ≈ 20–25 USD/ay.

### Harita yığma / "map pinning" deneyi (Faz 8, ölçümlü deney)
Ne: Google My Maps'te işletme için özel harita; katmanlarda çok sayıda pin (ör. hizmet bölgesindeki ilçe/mahalle
merkezleri, en fazla ~2.000 öğe/katman), her pinde işletme adı, adres/telefon, hizmet + bölge ifadesi ve site/GBP
linki. Harita KML olarak içe aktarılır, herkese açık paylaşılır, siteye (iletişim/hizmet bölgesi sayfası) iframe ile
gömülür; bazen Drive/Sites/Blogger yığınıyla desteklenir.
Kanıt durumu: Etkisi anekdot düzeyinde; Google temsilcileri My Maps'in sıralama faktörü olmadığını söylemiş, bağımsız
testler karışık. Yerel sıralamada belirleyici olanlar alaka (GBP kategori/hizmet), mesafe ve bilinirlik (yorum,
atıf, link) olmaya devam ediyor. Aşırı anahtar kelime doldurma spam sinyali; sağlık müşterilerinde tanıtım yasağı
nedeniyle ifade bilgilendirici kalmalı.
MoxDOP yaklaşımı: (1) KML üretici — marka + hizmet + BrandServiceArea'dan pin listesi, doğal açıklama şablonu,
pin sayısı tavanı (varsayılan 50–150, 1000 değil), GBP NAP ile tutarlılık kontrolü; dosya indirilir, Google'a yazma
yok (içe aktarma ve gömme elle yapılır). (2) Ölçüm — yalnız bir-iki markada, harita grid takibiyle önce/sonra ARP ve
SoLV (en az 4–6 hafta); fark yoksa özellik "deney" olarak kalır, portföye yayılmaz. Grid takibi bu deneyin ön şartı.

### Backlink fırsat motoru (Faz 8)
DataForSEO Backlinks (aylık taahhüt yok, marka başına ≈ 0,3–0,5 USD/ay): özet, yönlendiren alan adları, rakip kesişimi
(≥2 rakibe link veren ama bize vermeyen), yeni/kaybedilen. Kalite filtresi (alan puanı, spam puanı, dofollow, TR/sektör
alakası). Türkiye rehber/atıf listesi (GBP, Yandex, Apple, Bing, Foursquare, Find.com.tr, Cylex, sektör: Doktortakvimi,
Doktorsitesi, oda listeleri) + NAP tutarlılığı. Takip: yeni → iletişim → bekliyor → yayında → kaybedildi, link var mı
kontrolü. Sağlıkta tanıtım yasağı nedeniyle içerik bilgilendirici kalır.

### DataForSEO bütçe kademeleri (tahmini, +%20 pay dahil)
Yalın (30 marka) 40–60 USD/ay · Standart (60) 100–150 · Tam (100) 200–300. Her ücretli çalıştırmadan önce maliyet
tahmini ve tavan.

### Gözden kaçmaması gerekenler
Uptime izleme; alan adı/hosting/SSL + ücret ve tahsilat hatırlatması; grafik notları (algoritma güncellemesi,
kampanya, site değişikliği); marka/marka dışı ayrımı; AI görünürlüğü (LLM'lerde marka geçişi); müşteri sağlığı puanı
(düşen KPI, 30 gündür temas yok, yaklaşan yenileme); KVKK: müşterilerle veri işleme sözleşmesi, WhatsApp'taki sağlık
verisi; sistem yedeği ve 2FA; algoritma/regülasyon değişikliklerinin Yöntem Kütüphanesine işlenmesi.

## Kaynak notları

- Perfex `dijital_varliklar` modülü (sahibin eski sistemi): taşınacak bilgi — ~110 TR stopword, sektör istisnalı
  alakasız niyet kelimeleri, 4 sınıflı niyet sözlüğü, yasaklı reklam ifadeleri, 43 görev şablonu, anomali/n-gram/negatif
  aday/QS/landing benzerliği/öneri doğrulama yaklaşımları, yenileme hatırlatıcı modeli. Taşınmayacak: açık metin API
  anahtarları, kapalı TLS doğrulaması, gizli yedek admin, müşteri portalı.
- Dış repolar: claude-seo (teknik SEO/CWV listeleri), open-seo (sorun sınıflandırması, DataForSEO uç noktaları),
  geo-seo-claude (AI tarayıcı robots kontrolü), HEAD (eskimiş etiketler), meta-ads-mcp (API notları) bilgi olarak;
  google-reviews-scraper-pro sahibinin kararıyla iç kullanımda değerlendirilecek.
