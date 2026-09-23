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
| 0 – Acil | Otomatik AI'yı kapat (SEO planı LLM varsayılanı, WhatsApp otomatik öneri, marka kurulumu sonrası plan), pasif müşteri + bağsız hesap kapısı, SEO haftalık sırası tüm siteler (ilk 25 değil), GBP 30 gün silme işi, yetki açıkları (AI yönlendirme kaydı, otomasyon paneli, bağlama), 2FA |
| 1 – Temizlik + saklama | Kullanılmayan katmanlar (ajans beyni stack'i, ekip iş akışları, Filament kopyaları, demo fikstürleri, ölü AI kodu, Instagram sayfası), hiç okunmayan veri toplama; saklama politikası + aylık toplama. **Not:** Arama talebi hattı silinmeyecek; Faz 2b'de sadeleştirilip otomatikleştirilecek (sahibinin sorgu → sayfa → rakip hedefi) |
| 2 – Portföy | "Keşfet ve Grupla" toplu oluşturma, müşteri listesinde aktif/pasif düğmesi, birleşik Rakipler, onboarding kontrol listesi |
| 2b – Talep hattı | Hizmet + hizmet bölgesi → sorgular (GSC/Ads/GBP/DataForSEO, otomatik atama) → sorgu kümeleri → hangi sayfa → bölgede SERP rakipleri → rakip sayfa HTML analizi → karşılaştırma → strateji/SEO görevi |
| 3 – Ölçüm temeli | Marka dönüşüm sözlüğü, takip sağlığı, GTM (salt okunur), GA4 sayfa × kanal, Sayfa Karnesi, marka/marka dışı ayrımı |
| 4 – Entegrasyonlar (E1–E4) | E1 hatalar/güvenlik; E2 kendi kendini onaran akış (yeniden bağlanınca devam, günlük yeniden deneme, token süre uyarısı, zamanlayıcı/işçi izleme, deploy kontrolleri); E3 tek merkez + tek kalıp; E4 veri seti tazeliği, backfill, maliyet ekranı, eklenti sürüm takibi |
| 5 – Sağlık paketi + uyum + arşiv | Sektör paketleri (eklenti gibi); Sağlık: yasaklı ifade/zorunlu uyarı/hedefleme kuralları (RG 12.11.2025 / 33075 — hukuk görüşü alınacak); uyum denetçisi (AI taslakları, canlı reklamlar, site, GBP); Üretim Arşivi |
| 6 – Asistan | Bugün ekranı, hatırlatıcı + telefon bildirimi (ntfy/Telegram) + Google Takvim, alan adı/hosting/SSL yenileme + ücret, uptime izleme, WhatsApp ↔ müşteri + satış asistanı |
| 7 – Beyin | Yöntem Kütüphanesi (ekrandan düzenlenen yöntem/eşikler), sektör desenleri, sonuca dayalı önceliklendirme, "Yapıldı"da anında doğrulama + geri gelen sorunu yeniden açma, Perfex Ads analitiği (anomali dedektörleri, n-gram, negatif adaylar, QS geçmişi, sayfa↔kelime uyumu, öneri doğrulama/erteleme) |
| 8 – Rakip/yorum istihbaratı + ajans satışı | Yorum kazıyıcı (iç kullanım, sahibi riski üstlendi), Meta Reklam Kütüphanesi, rakip izleme, harita grid sıralama takibi, backlink fırsat motoru, dış denetim/prospect raporu, ajans lead kutusu (yalnız ajansın kendi lead'leri) |
| 9 – Rapor v2 + eklenti v2 | Looker yerine aylık rapor (kanal KPI, grafik, karşılaştırma, yapılanlar ve etkisi, AI yorumu), WordPress eklenti v2 (sağlık, tek tık panel girişi, onaylı güncelleme) |

## Kaynak notları

- Perfex `dijital_varliklar` modülü (sahibin eski sistemi): taşınacak bilgi — ~110 TR stopword, sektör istisnalı
  alakasız niyet kelimeleri, 4 sınıflı niyet sözlüğü, yasaklı reklam ifadeleri, 43 görev şablonu, anomali/n-gram/negatif
  aday/QS/landing benzerliği/öneri doğrulama yaklaşımları, yenileme hatırlatıcı modeli. Taşınmayacak: açık metin API
  anahtarları, kapalı TLS doğrulaması, gizli yedek admin, müşteri portalı.
- Dış repolar: claude-seo (teknik SEO/CWV listeleri), open-seo (sorun sınıflandırması, DataForSEO uç noktaları),
  geo-seo-claude (AI tarayıcı robots kontrolü), HEAD (eskimiş etiketler), meta-ads-mcp (API notları) bilgi olarak;
  google-reviews-scraper-pro sahibinin kararıyla iç kullanımda değerlendirilecek.
