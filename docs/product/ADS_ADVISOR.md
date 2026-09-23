# Danışman (Faz 3 — Google Ads, Faz 4 — Meta Ads, Faz 5 — İşletme Profili)

Google Ads hesaplarında her hafta gerçekten yapılması gerekenleri gösterir. Toplanmış veriden çalışır; Google Ads'e hiçbir şey yazmaz (ADR-018). Liste kısa tutulur: hesap başına en fazla 6 açık öneri (kritikler hariç).

## Nerede

- **Menü → Reklam Danışmanı** (`/ads-advisor`): tüm hesaplar, hesap tablosu, öneriler.
- **Google Ads hesabı → Danışman** sekmesi: aynı liste, tek hesap.
- Haftalık otomatik: Pazartesi 07:00 (`moxdop:advisor:plan --scheduled`). Elle: "Tüm hesapları incele" veya satırdaki "İncele". Komut: `php artisan moxdop:advisor:plan --asset=ID --sync`.

## Kurallar (son 30 gün)

| Kural | Kategori | Veri | Ne zaman |
|---|---|---|---|
| `negative-keywords` | İsraf | arama terimi raporu, negatif anahtar kelimeler | Dönüşümsüz, ≥3 tık, harcama ≥ max(40, CPA×0,5). Mevcut negatifle kapananlar, marka ve hizmet adı geçen terimler çıkarılır. Yalnızca dönüşümsüz terimlerde geçen kelimeler ayrıca sıralı eşleme önerilir. Kopyala-yapıştır listesi. |
| `service-terms-not-converting` | Açılış sayfası | aynı | Yalnızca hizmet adı geçen dönüşümsüz terimler kaldıysa: negatif değil sayfa/reklam kontrolü. |
| `keyword-opportunities` | Büyüme | arama terimleri, anahtar kelimeler | ≥2 dönüşüm getiren, eklenmemiş terimler. |
| `budget-limited-profitable` | Büyüme | kampanya günlük (bütçe kaybı IS) | Bütçe kaybı ≥%20 ve CPA ≤ hedef/hesap CPA. |
| `budget-waste` | İsraf | kampanya günlük | Hesap dönüşüm alırken 0 dönüşüm ve harcama ≥ max(150, 2×CPA). |
| `no-primary-conversion`, `primary-no-signal`, `conversion-settings` | Ölçüm | dönüşüm işlemleri | Birincil yok (kritik); ≥150 tıkta birincil sıfır; düşük niyetli birincil; potansiyel müşteri "her dönüşüm". |
| `auto-tagging-off`, `ga4-mismatch` | Ölçüm | hesap, GA4 google/cpc | Otomatik etiketleme kapalı; Ads ↔ GA4 dönüşüm oranı 0,5–2 dışında veya oturum/tık < %30. |
| `landing-page-issues` | Açılış sayfası | Ads açılış sayfası raporu + site taraması | 4xx/5xx (kritik), yönlendirme, noindex, hız ≤4/10, mobil uyum <%50, 2×CPA harcayıp 0 dönüşüm. |
| `weak-ad-strength` | Reklam | reklam anlık görüntüsü | Etkin RSA'da reklam gücü POOR/AVERAGE. AI metin taslağı butonu. |
| `missing-assets` | Reklam | hesap öğe kitaplığı | Site bağlantısı <4, callout <4, snippet yok. |
| `google-recommendations` | Reklam | Google önerileri | Yalnızca öğe/reklam/etiket önerileri; bütçe, geniş eşleme, otomatik teklif önerileri gösterilmez. |
| `low-quality-score` | Kalite puanı | anahtar kelime (quality_info) | KP ≤4, harcama ≥50. |
| `change-impact` | Değişiklik etkisi | değişiklik geçmişi + kampanya günlük | Değişiklikten sonraki ≥7 günde CPA ≥%30 arttı (önce ≥5 dönüşüm). |

Eşikler: `config/moxdop-advisor.php`.

## Yaşam döngüsü

Aynı öneri anahtarı her hafta güncellenir. Artık üretilmeyen açık öneri **kendiliğinden kapanır**. "Yapıldı" / "Atla" korunur; yapıldığında öneri anındaki değerler (`baseline`) saklanır (etki ölçümü Faz 6).

## AI

Yalnızca "Taslak hazırla" tıklanınca: reklam grubunun anahtar kelimeleri, tıklanan/dönüşen terimleri ve açılış sayfası başlıkları ile 12–15 başlık (≤30) ve 4 açıklama (≤90). Rota `google_ads.ad_copy_draft`, aylık AI bütçesine tabi. Uzunluk sınırını aşan satır atılır.

## Bilinen boşluklar

- Öğe kitaplığı hesap düzeyinde; öğelerin hangi kampanyaya bağlı olduğu toplanmıyor.
- Arama terimi eşleme türü ve RSA metinleri toplanmıyor.
- Kalite puanı bir sonraki Google Ads toplamasından itibaren gelir.

## Meta Ads (Faz 4)

Aynı sayfa ve yaşam döngüsü; kanal seçiciyle yalnızca Meta gösterilebilir. Meta hesabında **Danışman** sekmesi. Eşikler `config/moxdop-advisor.php` → `meta_ads`.

"Sonuç": kampanyanın reklam seti optimizasyon hedefi (yoksa kampanya hedefi) için `result_actions` listesindeki ilk dolu eylem türü (ör. `lead`, `offsite_conversion.fb_pixel_purchase`, mesaj başlatma).

| Kural | Kategori | Veri | Ne zaman |
|---|---|---|---|
| `creative-fatigue` | Reklam | reklam günlük + kreatif | Son 7 gün günlük ort. sıklık ≥1,8 ve bağlantı TO önceki haftaya göre ≥%25 düştü (14 günde ≥200 harcama). AI kreatif taslağı. |
| `audience-saturation` | Kitle & teslimat | kampanya günlük | Son 7 gün günlük ort. sıklık ≥2,5 ve ≥200 harcama. |
| `learning-limited` | Kitle & teslimat | reklam seti + sonuçlar | Dönüşüm optimizasyonlu etkin sette haftalık sonuç <15 (Meta ~50 ister); gereken günlük bütçe = sonuç başı maliyet × 50 / 7. Öğrenme durumu toplanmadığı için tahmin. |
| `spend-no-results` | İsraf | kampanya + sonuçlar | Hesap sonuç alırken dönüşüm kampanyası 30 günde 0 sonuç, harcama ≥ max(200, 2 × sonuç başı maliyet). |
| `pixel-health` | Ölçüm | piksel / özel dönüşüm | Piksel kullanılamaz (kritik), dönüşüm kampanyası harcarken >3 gün sessiz, set bilinmeyen piksele optimize, arşivli özel dönüşüm. |
| `delivery-outliers` | Kitle & teslimat | hesap düzeyi yerleşim/cihaz/saat kırılımı | Harcama payı ≥%10 (saatte ≥%5) ve tık maliyeti ≥2× ortalama ya da TO ≤0,4× ortalama. Tık bazlı; sonuç bu düzeyde yok. |
| `landing-page-issues` | Açılış sayfası | kreatif bağlantısı + site taraması | ≥100 harcayan bağlantıda hata / yönlendirme / noindex. |
| `change-impact` | Değişiklik etkisi | hesap geçmişi + kampanya günlük | Değişiklikten sonra sonuç başı maliyet ≥%30 arttı. |

Bilinen boşluklar: öğrenme durumu, haftalık tekil erişim/sıklık, kitle büyüklüğü, kampanya düzeyinde kırılım ve yerleşim bazında sonuç toplanmıyor.

## İşletme Profili (Faz 5)

Aynı sayfa (kanal: İşletme Profili) ve profil sayfasında **Danışman** sekmesi. Veri: `gbp_*` tabloları (profil, hizmetler, özellikler, günlük performans, aylık arama kelimeleri, yorumlar, medya). Eşikler `config/moxdop-advisor.php` → `gbp`. Yorum cevaplama kapsam dışı.

| Kural | Kategori | Ne zaman |
|---|---|---|
| `profile-closed` | Profil | Profil geçici/kalıcı kapalı görünüyor (kritik). |
| `profile-gaps` | Profil | Açıklama yok/<250 karakter, ek kategori yok, saat yok, telefon yok, web sitesi yok/bozuk, hizmet listesi boş, kapak/logo yok, Google değişiklik yapmış, eklenebilecek özellikler. AI açıklama taslağı. |
| `keyword-service-gaps` | Büyüme | Son 3 ayda ≥30 görüntülenen arama (marka aramaları hariç) bir marka hizmetine denk geliyor ama profilde yok; ya da hiçbir marka hizmetine denk gelmiyor (≥60 görüntülenme: yeni hizmet fırsatı). |
| `site-profile-services` | Profil | Markanın (öncelikli) hizmetleri profil hizmetlerinde/kategorilerinde yok. |
| `profile-actions-drop` | Büyüme | Arama+web+yol tarifi+mesaj+rezervasyon son 28 günde önceki 28 güne göre ≥%30 düştü (önce ≥30). |
| `rating-trend` | Profil | Son 90 günde ≥5 yorumun ortalaması önceki yıla göre ≥0,3 düştü. |
| `photo-freshness` | Profil | Profil 28 günde ≥20 etkileşim alırken son fotoğraf 120 günden eski. |
| `website-utm` | Ölçüm | Web sitesi bağlantısında UTM yok (hazır adres verilir). |
