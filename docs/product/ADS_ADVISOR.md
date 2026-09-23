# Reklam Danışmanı (Faz 3 — Google Ads)

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
