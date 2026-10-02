# Marka kurulum rehberi

Bir markanın web sitesini, Google Ads ve Meta reklam hesaplarını MoxDOP'a bağlamak için adım adım rehber. Her adımda
ne yapacağınızı, sistemin sonra kendiliğinden ne yaptığını ve neyi kontrol etmeniz gerektiğini anlatır.

**Kısa yol:** marka sayfasını açın (Markalar › marka). "Genel bakış" sekmesinin en üstündeki **Kurulum durumu** kartı
her kanal için adımları sırayla gösterir: ✓ tamam, → sıradaki adım (düğmesi yanında), ○ önce önceki adım. Sıradaki
adımın düğmesine basmanız yeterli. Bütün kanallar hazır olunca kart "Dijital varlıklar" sekmesinin altına iner.

Google ve Meta bağlantısı **ajans genelinde bir kez** yapılır; her marka için tekrar edilmez. Hesap bağlamayı
(hangi reklam hesabı hangi markanın) yalnız **Admin** onaylar.

---

## 1. Marka ve web sitesi

### 1.1 Markayı oluşturun

1. **Müşteriler › müşteri › Marka ekle** (ya da Markalar › Yeni marka). Adı, sektörü ve web sitesi adresini girin.
2. Adres girdiyseniz kayıttan sonra **Otomatik kur** sayfası açılır.

### 1.2 Otomatik kur (önerilen)

Otomatik kur, girilen adrese göre şunları önerir; siz işaretleyip **Onayla** dersiniz:

- web sitesi varlığı (aynı alan adı başka markada kayıtlıysa ikinci kopya açılmaz, uyarır);
- bu adrese ait Search Console mülkü ve GA4 mülkü;
- İşletme Profili konumu;
- adı markaya benzeyen Google Ads ve Meta reklam hesapları (birden fazla olabilir; her biri ayrı varlık olur);
- siteden okunan hizmetler, eşleştirme ifadeleri ve iş bağlamı.

**Sonra kendiliğinden:** bağlanan her hesabın verisi birkaç dakika içinde çekilmeye başlar (ilk seferde 13 aylık
geçmiş). Search Console bağlandıysa ilk SEO planı kuyruğa alınır. Uygulama bitince sayfadaki **Kurulum durumuna git**
ile marka sayfasına dönün.

Başka bir varlığa bağlı hesaplar otomatik kurda **atlanır**, taşınmaz. Devretmek için aşağıdaki "Hesap başka yerde
bağlı" bölümüne bakın.

### 1.3 Elle kurulum

Otomatik kur hesabı bulamazsa:

1. Marka sayfası › **Varlık ekle** › tür "Web sitesi", adresi girin. Kayıttan sonra sitenin **Veri kaynakları**
   sayfası açılır.
2. Veri kaynaklarında **Search Console** ve **GA4** için listeden mülkü seçip **Bağla**. Liste boşsa önce Google
   bağlantısını yapın (bölüm 2) ya da **Hesapları listele** deyin.

**Sonra kendiliğinden:** her bağlı mülkün verisi birkaç dakika içinde çekilir. Search Console ilk kez bağlanınca ilk
SEO planı kuyruğa alınır (mesajda "İlk SEO planı kuyruğa alındı" yazar).

### 1.4 WordPress eklentisi (site WordPress ise)

1. Kurulum durumu › Web sitesi › **Eklentiyi eşleştir** (ya da Entegrasyonlar › Web sitesi › WordPress Connector).
2. Paketi indirip sitenin WordPress yönetiminde eklenti olarak yükleyin.
3. MoxDOP'ta **Eşleştirme kodu üret**, kodu WordPress'teki MoxDOP eklenti sayfasına yapıştırın.
4. **Bağlantıyı doğrula** ile kontrol edin.

**Sonra kendiliğinden:** sitede yapılan değişiklikler anında bildirilir, değişen sayfalar yeniden taranır; site
düzeltmeleri tek tıkla (Admin onayıyla) uygulanabilir. Eklenti yoksa sistem her saat site haritasını kontrol eder.

### 1.5 İlk tarama ve SEO planı

- Kurulum durumu › **Taramayı başlat**: sayfalar, başlıklar, teknik sorunlar toplanır (birkaç dakika).
- Kurulum durumu › **SEO planını başlat**: Search Console ve tarama verisinden SEO görevleri üretilir. Plan her
  pazartesi kendiliğinden yenilenir.

### 1.6 Günlük kullanım

- Site ekranı › **SEO** sekmesi: görevler, sorular, içerik önerileri.
- Site ekranı › **Düzeltmeler** sekmesi: WordPress'e uygulanabilecek düzeltmeler (başlık, meta açıklama, alt metin,
  yönlendirme…). Her uygulama kayıtlıdır ve geri alınabilir.
- **Komuta merkezi**: acil işler, kurulum eksikleri, veri şüpheleri.

---

## 2. Google Ads

### 2.1 Google bağlantısı (ajans genelinde bir kez)

1. **Entegrasyonlar › Google**. **Ayarlar** sekmesinde OAuth istemci kimliği / gizli anahtarı ve **Google Ads
   geliştirici jetonu** girili olmalı (Admin).
2. **Google ile bağlan** › ajansın Google kullanıcısıyla izin verin (Search Console, GA4, Google Ads, İşletme Profili).

**Sonra kendiliğinden:** izin verildiği anda hesap listesi arka planda yenilenir. Ajansın erişebildiği **her MCC'nin
altındaki bütün müşteri hesapları** (alt MCC'ler dahil) listelenir. MCC'nin kendisi bağlanmaz; yalnız hangi hesabın
hangi MCC altında olduğunu gösterir. Veri çekerken her hesap kendi MCC'si üzerinden (login-customer-id) okunur.
Liste her gece yenilenir; yeni hesaplar ve erişimi kaybolan hesaplar size bildirilir.

### 2.2 Hesapları markaya bağlayın

Bir markanın **birden fazla** Google Ads hesabı olabilir. Her hesap markada **ayrı bir Google Ads varlığı** olur; bir
varlığa tek hesap bağlanır, bir hesap tek varlığa bağlanır.

En kolay yol: marka sayfası › **Dijital varlıklar** › **Hesap ekle**. Burada şunlar listelenir:

- markanın zaten bağlı hesaplarıyla **aynı MCC altındaki** bağlanmamış hesaplar;
- adı markaya (ya da sitenin alan adına) benzeyen bağlanmamış hesaplar.

"Önerilen" işaretli olanlar neredeyse kesin markanındır (MCC yalnız bu markaya ait ya da ad eşleşiyor). Ajansın
bütün müşterilerini tutan ortak bir MCC'deki diğer hesaplar da listelenir ama önerilmez; doğru olanı seçin.
**Bağla** ya da **Önerilenlerin hepsini bağla**.

Diğer yollar: Entegrasyonlar › Google › **Hesaplar** › **Markaya bağla…** (arama kutusu var, bütün hesaplar listelenir)
ya da Otomatik kur.

**Sonra kendiliğinden:** her bağlanan hesap için ilk veri çekimi birkaç dakika içinde başlar (13 ay geçmiş). Sonra
aktif hesaplar her gün, sessiz hesaplar haftada bir güncellenir. Marka toplamları (aylık rapor, bütçe temposu,
lead maliyeti, Danışman'ın kanal karşılaştırması) markanın **bütün** hesaplarını toplar.

### 2.3 İlk veri

Kurulum durumu › Google Ads › **İlk veri** adımı: "İlk veri yükleniyor" birkaç dakika ile birkaç saat sürebilir.
Beklemek istemezseniz **Şimdi çek**. Erişim sorunu varsa adımda **Yeniden bağlan** çıkar.

### 2.4 Danışman ve Google Ads Editor

- Veri gelince her hesap kendiliğinden incelenir; hemen görmek için Kurulum durumu › **İncele**. İnceleme her
  pazartesi yenilenir.
- Google Ads varlığı › **Danışman** sekmesi: öneriler, negatif anahtar kelime ve metin önerileri.
- **Google Ads Editor dosyası indir**: seçtiğiniz önerileri Editor'da içe aktarılacak dosya olarak verir. Google
  Ads'e MoxDOP'tan hiçbir şey gönderilmez (tek istisna: Admin onaylı paylaşılan negatif liste).

---

## 3. Meta reklamları

### 3.1 Meta bağlantısı (ajans genelinde bir kez)

1. **Entegrasyonlar › Meta**. **Bağlantı** sekmesinde Meta uygulama kimliği / gizli anahtarı girili olmalı (Admin).
2. **Meta ile bağlan** › ajansın Meta kullanıcısıyla izin verin.

**Sonra kendiliğinden:** Business (Business Manager) listesi arka planda yenilenir.

### 3.2 Business seçin

Meta'da reklam hesapları Business'ların altındadır. Meta › **Reklam Hesapları** sekmesinde müşterinin reklam hesaplarını
gören Business'ı **seçin**. Seçtiğiniz anda o Business'ın **sahip olduğu** ve **müşteri olarak yönettiği** bütün
reklam hesapları listelenir (ayrı bir "keşfet" düğmesine basmanız gerekmez). Birden fazla Business seçebilirsiniz;
bir hesap birden fazla Business'tan görünüyorsa tek kez listelenir.

### 3.3 Hesapları markaya bağlayın

Google Ads ile aynı: marka sayfası › Dijital varlıklar › **Hesap ekle**. Markanın bağlı hesabıyla aynı Business'taki
bağlanmamış hesaplar ve adı markaya benzeyenler listelenir. Her hesap ayrı bir Meta Ads varlığı olur.

**Sonra kendiliğinden:** ilk veri birkaç dakika içinde (13 ay geçmiş), sonra her gün. Danışman veri gelince hesabı
inceler.

### 3.4 Günlük kullanım

Meta Ads varlığı › **Danışman** sekmesi; kampanya, kreatif ve kitle sekmeleri; Komuta merkezi.

---

## 4. Kontrol listesi (kurulum bitti mi?)

- Kurulum durumu kartında web sitesi ve kullanılan reklam kanalları "Hazır".
- Marka sayfası › Dijital varlıklar: her hesabın yanında "Son veri" tarihi var.
- **Portföy sağlığı**: markanın satırında hücreler yeşil. Bir kanalda birden fazla hesap varsa hücre "2 hesap · …"
  yazar ve en kötü durumdaki hesabı gösterir; üzerine gelince her hesabın durumu görünür.
- **Komuta merkezi › Kurulum eksiği**: markanız için "N reklam hesabı bağlanmamış" maddesi yok.

---

## 5. Sık karşılaşılan sorunlar

| Belirti | Neden | Çözüm |
|---|---|---|
| Hesap listesi boş | Google/Meta bağlantısı yok ya da liste hiç yenilenmedi | Entegrasyonlar › Google/Meta › bağlanın; Kurulum durumu › **Hesapları listele** |
| Google Ads hesapları gelmiyor, diğerleri geliyor | Geliştirici jetonu eksik ya da onaylı değil | Google › Ayarlar › jetonu girin; jetonun erişim seviyesini Google Ads API Center'da kontrol edin |
| Meta'da reklam hesabı yok | Business seçilmemiş ya da hesap o Business'ta değil | Meta › Reklam Hesapları › doğru Business'ı seçin; müşteriden ajansın Business'ına hesap erişimi isteyin |
| Hesap listede var ama "Hesap ekle"de yok | Başka bir varlığa bağlı ya da markayla MCC/Business veya ad ortaklığı yok | Entegrasyonlar sayfasından "Markaya bağla…" ile elle bağlayın; başka yerde bağlıysa aşağıya bakın |
| "Bu hesap … şu an X müşterisinin Y varlığına bağlı" | Hesap başka bir varlığa bağlı | Hedef varlığın **Veri kaynakları** sayfasında hesabı seçin, "Yetki devrini onaylıyorum" › **Devret** (Admin). Otomatik akışlar hiçbir zaman devretmez |
| "Bağlantı yenilenmeli" (Komuta merkezi) | Google/Meta izni sona erdi | Maddedeki bağlantıya tıklayın, izin ekranında yeniden onaylayın; hesaplar kendiliğinden devam eder |
| "Erişim kaybedildi: hesap" | Müşteri erişimi kaldırdı | Müşteriden erişimi yeniden isteyin ya da bağlantıyı kaldırın |
| Aylık raporda harcama "—" ve "farklı para birimleri" notu | Markanın reklam hesapları farklı para birimlerinde | Harcamalar toplanmaz; rapordaki hesap tablosuna bakın. Hesap yanlış markadaysa taşıyın |
| "Markanın reklam hesapları farklı para biriminde" (Veri şüpheli) | Aynı durum, günlük veri kontrolünden | Yukarıdaki gibi |
| MCC hesabı "Bağla" listesinde yok | MCC yönetici hesabıdır, performans verisi yoktur | Altındaki müşteri hesaplarını bağlayın |
| Tarama başlamıyor | Sitenin adresi girilmemiş | Varlığı düzenleyip adresi girin, sonra **Taramayı başlat** |
| İlk veri saatlerdir yükleniyor | Çok hesaplı / uzun geçmişli hesaplar sırayla çekilir (sağlayıcı başına aynı anda 2 hesap) | Bekleyin; Ayarlar › Arka plan işlemleri'nde ilerleme görünür |
