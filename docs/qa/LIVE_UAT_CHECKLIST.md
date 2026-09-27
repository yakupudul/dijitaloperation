# Canlı UAT kontrol listesi

Gerçek hesaplarla, üretim ortamında elle yapılan kabul testi. Otomatik kısım her sabah `moxdop:verify:live` (06:20 İstanbul) ve `moxdop:verify:data` (07:25 İstanbul) ile çalışır; elle yapılan adımlar bunların göremediği şeyleri (ekranda doğru sayı, onaylı yazmaların gerçekten gidip geri alınması, e-posta teslimi, WhatsApp mesajlarının alınması) kontrol eder.

Kurallar:

- Her kanal için **gerçek** ve açıkça seçilmiş bir hesap kullanın; ilk/rastgele hesabı otomatik seçmeyin. Sentetik test verisi üretim veritabanına yazılmaz.
- Onaylı yazmalar (ADR-064 / 068 / 070 / 071 / 073) yalnız test için ayrılmış bir sayfada, konumda veya gönderide denenir ve geri alma da denenir.
- Sonuçları tarih, hesap ve ekran adıyla not edin. Beklenen sonuç tutmazsa adımı "başarısız" işaretleyin, ekran görüntüsü yalnız gerekiyorsa alın.

## 0. Önce otomatik kontrol

1. Ayarlar › **Sistem Sağlığı** › **Canlı doğrulama** bölümünde **Şimdi doğrula**ya basın (Admin).
2. Birkaç dakika sonra sayfayı yenileyin. Beklenen: her bağlantı ve bağlı hesap için bir satır; hepsi "Çalışıyor" ya da gerekçeli "Denenmedi".
3. "Başarısız" satır varsa **Komuta merkezi**nde "Canlı doğrulama" kaynağıyla görünmeli; sorunu giderip yeniden doğrulayınca kaybolmalı.
4. Komuta merkezinde "Veri şüpheli" kaynağını açın. Beklenen: listelenen her madde (eksik gün, reklam trafiği etiketsiz, dönüşüm farkı, para birimi) ilgili hesapta elle doğrulanabilir; yanlış alarm varsa not edin.

`moxdop:verify:live` şunları otomatik kanıtlar: Google anahtar yenileme; GA4'te dünkü oturum sayısı (1 günlük rapor); Search Console site okuma; Google Ads `SELECT customer.id FROM customer LIMIT 1`; İşletme Profili konum okuma; Meta anahtar (`/me`) ve reklam hesabı `account_status`; DataForSEO ücretsiz `appendix/user_data`; WordPress Connector imzalı durum yanıtı. Hiçbiri yazma yapmaz. Ekrandaki sayıların doğruluğunu, yazmaları ve teslimleri kanıtlamaz — aşağıdaki adımlar bunun için.

## 1. GA4

1. Markanın web sitesi › Google Analytics sekmesini açın, son 28 gün seçin.
2. Aynı mülkü GA4 arayüzünde aynı tarih aralığı ve saat dilimiyle açın. Beklenen: oturum, kullanıcı ve anahtar olay toplamları ±%2 içinde.
3. Kanal kırılımında (Organik, Ücretli arama, Doğrudan…) ilk üç kanal GA4 ile aynı sırada.
4. Giriş sayfaları listesindeki ilk 5 sayfanın oturumları GA4 ile tutuyor.
5. Canlı doğrulamadaki "Dünkü oturum" sayısı GA4'teki dünkü oturumla aynı.
6. Sistem Sağlığı › Hesaplar tablosunda GA4 hesabının "Veri tarihi" dün ya da evvelki gün.

## 2. Search Console

1. Web sitesi › Search Console sekmesi, son 28 gün.
2. Search Console arayüzünde aynı mülk, aynı tarih, "Web" arama türü. Beklenen: tıklama ve gösterim toplamı ±%2 (Search Console 2–3 gün gecikmeli veri gösterir; son günleri karşılaştırmayın).
3. İlk 10 sorgunun tıklamaları tutuyor.
4. İlk 10 sayfanın tıklamaları tutuyor.
5. Canlı doğrulamada site satırı "Yetki: siteOwner" ya da "siteFullUser" gösteriyor.

## 3. Google Ads

1. Google Ads genel bakış, son 30 gün.
2. Google Ads arayüzünde aynı hesap ve tarih. Beklenen: harcama, tıklama, gösterim, dönüşüm ±%1 (dönüşümler birkaç gün içinde oturur; en yeni 3 günü ayrı değerlendirin).
3. Kampanya listesi ve kampanya durumları (etkin / duraklatılmış) aynı.
4. Arama terimleri sekmesinde ilk 10 terim ve maliyetleri tutuyor.
5. Bütçe izleme: hesabın harcama limiti / ön ödemeli bakiyesi Google Ads ile aynı; bitmek üzereyse uyarı çıkmış.
6. ADR-064 (onaylı): paylaşılan negatif anahtar kelime listesine test için bir kelime ekleme isteği oluşturun, Admin onaylasın. Beklenen: kelime Google Ads'te listede görünür; geri alma kelimeyi siler.
7. Veri şüpheli: "Google Ads harcıyor ama GA4'te reklam trafiği görünmüyor" maddesi varsa Google Ads'te otomatik etiketlemenin açık olduğunu ve GA4 bağlantısını kontrol edin.

## 4. Meta Ads

1. Meta genel bakış, son 30 gün.
2. Reklam Yöneticisinde aynı hesap ve tarih, aynı atıf ayarı. Beklenen: harcama, gösterim, erişim, bağlantı tıklaması ±%1; sonuç sayısı kampanya hedefine göre aynı.
3. Kampanya → reklam seti → reklam ağacı aynı; reklam seti ve kreatif listeleri dolu (varlık anlık görüntüleri toplanıyor).
4. Kırılımlar (yaş, cinsiyet, yerleşim) toplamları genel toplama eşit.
5. Hesap durumu: canlı doğrulamada "Hesap aktif"; hesap kapalı / ödeme bekliyorsa "Başarısız" ve Komuta merkezinde görünüyor.
6. Para birimi: hesap para birimi müşterinin fatura para biriminden farklıysa "Veri şüpheli" maddesi görünüyor.

## 5. İşletme Profili (ADR-073 dahil)

1. İşletme Profili sayfası: ad, adres, telefon, çalışma saatleri Google'daki profille aynı.
2. Yorumlar listesi en yeni 10 yorumu, puanları ve yanıt durumlarını doğru gösteriyor.
3. Performans (arama/harita görüntüleme, arama, yol tarifi, web sitesi tıklaması) Google'daki İşletme Profili performansıyla aynı ay için tutuyor.
4. ADR-073 yorum yanıtı: test yorumuna yanıt taslağı hazırlayın, Admin onaylasın. Beklenen: yanıt Google'da görünür; işlem kaydında içerik ve kimlik tutulur.
5. Yanıtı geri alın. Beklenen: önceki yanıt geri gelir, önceden yanıt yoksa yanıt silinir.
6. ADR-073 gönderi: test gönderisi oluşturun, Admin onaylasın. Beklenen: gönderi profilde yayında; geri alma gönderiyi siler.
7. Çalışma saatleri, kategori, fotoğraf gibi alanlar için hiçbir yazma seçeneği yok (yalnız okuma).

## 6. WordPress Connector (site düzeltmeleri dahil)

1. Entegrasyonlar › WordPress siteleri: eklenti sürümü, bekleyen güncellemeler ve Site Sağlığı WordPress panelindekiyle aynı.
2. Canlı doğrulamada site satırı "İmzalı durum yanıtı alındı" ve doğru eklenti sürümünü gösteriyor.
3. Tek tık giriş (ADR-068, yalnız Admin): "WP paneline gir" 60 saniyelik tek kullanımlık bağlantıyla panele açıyor ve güvenlik kaydına düşüyor; Admin olmayan kullanıcıda buton çalışmıyor.
4. Onaylı güncelleme (ADR-068): test sitesinde bir eklenti güncellemesi isteyin, Admin onaylasın. Beklenen: sürüm yükselir, işlem kaydı oluşur.
5. Site düzeltmesi (ADR-070): Web sitesi › Düzeltmeler sekmesinde bir SEO başlığı / açıklama / alt metin düzeltmesini onaylayın. Beklenen: değişiklik sayfanın yayındaki HTML'inde görünür.
6. Düzeltmeyi geri alın. Beklenen: eski değer geri gelir.
7. Taslak (ADR-064): içerik taslağı gönderin. Beklenen: WordPress'te yalnız **taslak** olarak oluşur, yayımlanmaz.
8. Connector kendini güncelleme (ADR-071): eski sürümlü bir test sitesinde güncellemeyi onaylayın. Beklenen: sürüm güncel sürüme çıkar.

## 7. DataForSEO

1. Entegrasyonlar › DataForSEO: "Bağlantıyı test et". Beklenen: hesap adı ve bakiye DataForSEO panelindekiyle aynı.
2. Canlı doğrulamada DataForSEO satırı "Çalışıyor" (ücretsiz çağrı; bakiye düşmemeli).
3. Aylık harcama tavanı: Ayarlar › Maliyetler'de bu ayki DataForSEO harcaması DataForSEO panelindeki kullanım geçmişiyle tutuyor.
4. Bir marka için ücretli bir kontrol (ör. bölge SERP) çalıştırın. Beklenen: maliyet kaydı artar; tavan dolunca yeni ücretli çağrı engellenir.
5. Sonuçlardaki sıralamaları Google'da elle 2–3 sorguyla karşılaştırın (konum ve dil aynı).

## 8. WhatsApp

1. WhatsApp bağlantısı (Meta embedded signup) "bağlı" görünüyor; seçilen telefon numarası doğru.
2. Test telefonundan işletme numarasına mesaj gönderin. Beklenen: mesaj birkaç dakika içinde gelen kutusunda doğru konuşmada görünür (webhook → işleme kuyruğu).
3. Aynı numara bir müşteri/lead kişisiyle eşleşiyorsa konuşma o kişiye bağlanmış (saatlik kişi eşleştirme).
4. Otomatik öneri açıksa yanıt **önerisi taslak** olarak oluşur; MoxDOP mesajı kendisi göndermez.
5. Geçmiş içe aktarımı bitene kadar o konuşma için öneri üretilmiyor.
6. Saklama süresi: süresi geçen mesajlar günlük temizlikte siliniyor (KVKK ayarlarındaki süre).

## 9. Aylık rapor e-postası

1. Raporlar › Aylık raporlar: geçen ay için taslaklar ayın 1'inde hazırlanmış.
2. Bir raporun önizlemesini açın. Beklenen: GA4, Search Console, Google Ads, Meta ve İşletme Profili sayıları yukarıdaki kanal kontrollerindeki aylık toplamlarla aynı.
3. Grafikler ve PDF doğru ay ve markayı gösteriyor, boş bölüm yok.
4. Yayımlayıp test alıcısına gönderin. Beklenen: e-posta gelir, gönderen adı ve adresi ajansın, PDF eki açılıyor.
5. Gönderim hatası olursa rapor satırında hata metni görünüyor ve yeniden gönderilebiliyor.

## Sonuç

- Tüm kanallarda "beklenen" tuttu ve canlı doğrulama 24 saat boyunca yeşil kaldıysa kanal "Gerçek UAT geçti" olarak Capability Ledger'a işlenir.
- Tutmayan her adım için bir görev açılır; yazma adımları başarısızsa ilgili yazma özelliği kapatılır ve geri alma yapılır.
