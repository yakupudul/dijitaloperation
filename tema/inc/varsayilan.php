<?php
/* tema.py üretir: Site bilgileri ekranının varsayılanları, ilk kurulumdaki menüler ve videolar. */
if (! defined('ABSPATH')) {
	exit;
}
return [
	'ayar' => [
		'tel' => '0332 265 35 43',
		'wa' => '0332 265 35 43',
		'sokak' => 'Sancak Mah. Zümrüt Sok. No:38',
		'ilce' => 'Selçuklu',
		'il' => 'Konya',
		'maps' => 'https://maps.app.goo.gl/xAE4X9FpC6U8xJ2n7',
		'mail' => 'piandpi.net@gmail.com',
		'ig' => 'https://www.instagram.com/aliakbulutklinikpsikolog',
		'ig2' => 'https://www.instagram.com/klinikpsikologkonya/',
		'yt' => 'https://www.youtube.com/@aliakbulut',
		'tt' => 'https://www.tiktok.com/@aliakbulutpsk',
		'alt_metin' => 'Konya Selçuklu\'da çocuk, ergen, yetişkin ve çiftlerle çalışan uzman klinik psikolog.',
		'sayac' => '2007 | yılından beri danışanlarla
20.000+ | seans
1.000 | meslektaşa hipnoz eğitimi
800 saat | meslektaşlara süpervizyon',
		'egitim_kisa' => 'Klinik Psikoloji Yüksek Lisans
Sağlık Bakanlığı onaylı hipnoterapist eğitimi
Şema Terapi, Psikonet
Cinsel Terapi, CİSED
EMDR 1. ve 2. Düzey ile süpervizyonları, DBE
APT onaylı Oyun Terapisi',
		'egitim' => '## Akademik
Atatürk Üniversitesi, Psikolojik Danışmanlık ve Rehberlik Lisans
İstanbul Esenyurt Üniversitesi, Klinik Psikoloji Yüksek Lisans

## Psikoterapi eğitimleri
Psikonet, Şema Terapi
Davranış Bilimleri Enstitüsü, EMDR 1. Düzey ve süpervizyon
Davranış Bilimleri Enstitüsü, EMDR 2. Düzey ve süpervizyon
Davranış Bilimleri Enstitüsü, EMDR ile Çocuk ve Ergenlerde Performans Geliştirme
Davranış Bilimleri Enstitüsü, EMDR ile Panik Bozukluklar
CİSED, Cinsel Terapi
PHM, Otojenik Terapi
Adnan Menderes Üniversitesi ADÜSEM, Aile Danışmanlığı Eğitim Programı (480 saat)

## Hipnoterapi
Ankara Yıldırım Beyazıt Üniversitesi, Sağlık Bakanlığı onaylı Hipnoz Uygulama Sertifikası
OMNİ Hipnoz Akademisi, Temel ve İleri Düzey Hipnoterapi
HTİ, Dönüşümsel Hipnoterapi ve Klinik Hipnoterapi

## Çocuk ve ergen
APT onaylı Oyun Terapisi
Psikolojik Testler Derneği, WISC-R Zeka Testi Uygulayıcılığı
Psikolojik Testler Derneği, Çocuk ve Ergenlerde Objektif ve Projektif Testler
ÜZE, Play Attention Edufeedback, Dikkat Eksikliği ve Hiperaktivite
PADEM, Terapötik Kartların Çocuk, Ergen ve Yetişkinlerde Kullanımı',
		'ilkeler' => 'Önce dinlemek | İlk görüşmede neden geldiğinizi, ne beklediğinizi ve şu an neler yaşadığınızı konuşur. Bir yol önermeden önce sizi tanımaya zaman ayırır.
Yöntemi kişiye göre seçmek | Tek bir ekole bağlı kalmaz. Ne yaşadığınıza göre şema terapi, EMDR, bilişsel davranışçı terapi ya da oyun terapisinden uygun olanı, gerekirse birkaçını birlikte kullanır.
Gizlilik ve meslek etiği | Görüşmelerde konuşulanlar danışanla terapist arasında kalır. Çalışmalar meslek etiği ilkelerine göre yürütülür.
Aileyi sürece katmak | Çocuk ve ergenlerle çalışırken ebeveynlerle düzenli görüşür; evde neyin değişebileceğini aileyle birlikte konuşur.',
		'kose_acik' => 1,
		'kose_video' => 0,
		'saatler' => '',
	],
	'menuler' => [
		'ana' => ['Üst menü', [['Hakkımda', 'ali-akbulut'], ['Çalışma alanları', 'calisma-alanlari', [['Çocuk ve ergen', 'konya-cocuk-ergen-psikologu', [['Ergen psikoloğu', 'konya-ergen-psikologu'], ['Konya pedagog', 'konya-pedagog'], ['Davranış sorunları', 'cocuklarda-davranis-sorunlari'], ['Dikkat eksikliği (DEHB)', 'konya-dehb-dikkat-eksikligi'], ['Özgüven ve içe kapanıklık', 'ozguven-ve-ice-kapaniklik'], ['Ekran bağımlılığı', 'ekran-bagimliligi'], ['Uyku sorunları', 'cocuklarda-uyku-sorunlari'], ['Alt ıslatma ve dışkı kaçırma', 'alt-islatma-ve-diski-kacirma'], ['Tik, kekemelik ve konuşma', 'tik-kekemelik-ve-konusma'], ['Parmak emme ve tırnak yeme', 'parmak-emme-ve-tirnak-yeme'], ['Yeme ve cinsel gelişim', 'cocuklarda-yeme-sorunlari-ve-cinsel-gelisim'], ['Yas ve kayıp', 'cocuklarda-yas-ve-kayip']]], ['Yetişkin', 'bireysel-terapi', [['Kaygı ve panik atak', 'kaygi-ve-panik-atak'], ['Depresyon', 'depresyon-terapisi'], ['OKB (takıntılar)', 'konya-okb'], ['Sosyal fobi', 'sosyal-fobi'], ['Fobiler', 'fobiler'], ['Travma sonrası stres', 'travma-sonrasi-stres'], ['Yeme bozuklukları', 'yeme-bozukluklari'], ['Kişilik bozuklukları', 'kisilik-bozukluklari'], ['Online terapi', 'online-terapi']]], ['Çift, aile ve cinsel terapi', 'konya-cift-terapisi', [['Aile danışmanlığı', 'konya-aile-danismanligi'], ['Cinsel terapi', 'konya-cinsel-terapi'], ['Vajinismus', 'konya-vajinismus'], ['Erkekte cinsel sorunlar', 'erkekte-cinsel-sorunlar'], ['Kurumsal', 'kurumsal']]]]], ['Terapi yöntemleri', 'terapi-yontemleri', [['Şema terapi', 'sema-terapi'], ['Bilişsel davranışçı terapi', 'bdt'], ['EMDR', 'konya-emdr'], ['Hipnoterapi', 'hipnoterapi'], ['Otojenik terapi', 'otojenik-terapi'], ['Oyun terapisi', 'oyun-terapisi']]], ['Videolar', '@video'], ['Blog', 'blog'], ['İletişim', 'iletisim']]],
		'alt_alanlar' => ['Alt bilgi: Çalışma alanları', [['Konya psikolog', ''], ['Çocuk ve ergen', 'konya-cocuk-ergen-psikologu'], ['Ergen psikoloğu', 'konya-ergen-psikologu'], ['Konya pedagog', 'konya-pedagog'], ['Çift terapisi', 'konya-cift-terapisi'], ['Aile danışmanlığı', 'konya-aile-danismanligi'], ['Kaygı ve panik atak', 'kaygi-ve-panik-atak'], ['Depresyon', 'depresyon-terapisi'], ['Vajinismus', 'konya-vajinismus'], ['Cinsel terapi', 'konya-cinsel-terapi'], ['Online terapi', 'online-terapi']]],
		'alt_yontemler' => ['Alt bilgi: Yöntemler', [['BDT', 'bdt'], ['EMDR', 'konya-emdr'], ['Şema terapi', 'sema-terapi'], ['Hipnoterapi', 'hipnoterapi'], ['Otojenik terapi', 'otojenik-terapi'], ['Oyun terapisi', 'oyun-terapisi']]],
		'alt_kurumsal' => ['Alt bilgi: Kurumsal', [['Hakkımda', 'ali-akbulut'], ['Uzmanlarımız', 'uzmanlarimiz'], ['Hangi terapi bana uygun?', 'hangi-terapi-bana-uygun'], ['Videolar', '@video'], ['Blog', 'blog'], ['İletişim', 'iletisim'], ['KVKK', 'kvkk']]],
	],
	'videolar' => [[
			'baslik' => 'Evlilikte sorun',
			'yt' => 'ZO8cy1CXu4U',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/s_ZO8cy1CXu4U.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['konya-cinsel-terapi'],
			'sira' => 1,
			'yas' => 30,
		], [
			'baslik' => 'Eşimi tanıyamıyorum',
			'yt' => 'LgUMe20FLuo',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/v_LgUMe20FLuo.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['konya-cift-terapisi'],
			'sira' => 2,
			'yas' => 2,
		], [
			'baslik' => 'Her tartışmada boşanalım demek',
			'yt' => 'IGkzSC_vhO4',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/v_IGkzSC_vhO4.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['konya-cift-terapisi'],
			'sira' => 3,
			'yas' => 0,
		], [
			'baslik' => 'Kavga yoksa sorun yok mu?',
			'yt' => 'uIbUVTupdW8',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/v_uIbUVTupdW8.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['konya-cift-terapisi'],
			'sira' => 4,
			'yas' => 33,
		], [
			'baslik' => 'Ergenle iletişim',
			'yt' => 'QLaUAz39cm0',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/s_QLaUAz39cm0.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['konya-cocuk-ergen-psikologu', 'konya-ergen-psikologu', 'ozguven-ve-ice-kapaniklik', 'ekran-bagimliligi'],
			'sira' => 5,
			'yas' => 34,
		], [
			'baslik' => 'Duygusal yalnızlık',
			'yt' => 'PgwQXCcp6ho',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/s_PgwQXCcp6ho.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['konya-vajinismus', 'bireysel-terapi', 'depresyon-terapisi', 'kisilik-bozukluklari', 'online-terapi', 'bdt', 'konya-emdr', 'hipnoterapi'],
			'sira' => 6,
			'yas' => 35,
		], [
			'baslik' => 'Cinsel isteksizlik',
			'yt' => 'wkgUu4B5Qdw',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/v_wkgUu4B5Qdw.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['konya-vajinismus', 'erkekte-cinsel-sorunlar', 'konya-cinsel-terapi'],
			'sira' => 7,
			'yas' => 36,
		], [
			'baslik' => 'Şema terapi',
			'yt' => 'Ky0dLRvwyBY',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/s_Ky0dLRvwyBY.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['bireysel-terapi', 'kisilik-bozukluklari', 'sema-terapi', 'hipnoterapi'],
			'sira' => 8,
			'yas' => 37,
		], [
			'baslik' => 'Güçlü görünenler',
			'yt' => 'wONm3fBFGCY',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/s_wONm3fBFGCY.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['bireysel-terapi', 'kaygi-ve-panik-atak', 'sosyal-fobi', 'travma-sonrasi-stres', 'online-terapi', 'sema-terapi', 'bdt', 'konya-emdr', 'otojenik-terapi'],
			'sira' => 9,
			'yas' => 38,
		], [
			'baslik' => 'Eşim beni merak etmiyor',
			'yt' => 'ngfsQwJGmsI',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/v_ngfsQwJGmsI.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['konya-cift-terapisi'],
			'sira' => 10,
			'yas' => 1,
		], [
			'baslik' => 'Vazgeçemediğin kişi değil, ilişki biçimi',
			'yt' => 'zHoAkUS87cw',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/v_zHoAkUS87cw.webp',
			'bicim' => 'dikey',
			'sayfalar' => ['kisilik-bozukluklari', 'sema-terapi'],
			'sira' => 11,
			'yas' => 3,
		], [
			'baslik' => 'Hareket çeken, küfür eden çocuklar',
			'yt' => 'Jo4q97x1Bd4',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/y_Jo4q97x1Bd4.webp',
			'bicim' => 'yatay',
			'sayfalar' => ['konya-cocuk-ergen-psikologu', 'konya-pedagog', 'cocuklarda-davranis-sorunlari', 'konya-dehb-dikkat-eksikligi', 'tik-kekemelik-ve-konusma', 'oyun-terapisi'],
			'sira' => 20,
			'yas' => 60,
		], [
			'baslik' => 'İlişkide sık yapılan hata',
			'yt' => 'R-pZxcHa-wo',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/y_R-pZxcHa-wo.webp',
			'bicim' => 'yatay',
			'sayfalar' => ['kurumsal'],
			'sira' => 21,
			'yas' => 61,
		], [
			'baslik' => 'Çalışan annenin çocuğu olmak',
			'yt' => '_4zYTru3R7A',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/y__4zYTru3R7A.webp',
			'bicim' => 'yatay',
			'sayfalar' => ['konya-aile-danismanligi', 'konya-cocuk-ergen-psikologu', 'cocuklarda-uyku-sorunlari', 'cocuklarda-yas-ve-kayip'],
			'sira' => 22,
			'yas' => 62,
		], [
			'baslik' => '3-6 yaş arası çocuğunuz inatçı mı?',
			'yt' => 'fP3FQjbDqsg',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/y_fP3FQjbDqsg.webp',
			'bicim' => 'yatay',
			'sayfalar' => ['konya-aile-danismanligi', 'konya-pedagog', 'cocuklarda-davranis-sorunlari', 'oyun-terapisi'],
			'sira' => 23,
			'yas' => 63,
		], [
			'baslik' => 'Hedefe ulaşmanın en önemli adımı',
			'yt' => 'gOzBK58FUas',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/y_gOzBK58FUas.webp',
			'bicim' => 'yatay',
			'sayfalar' => ['bireysel-terapi', 'kurumsal'],
			'sira' => 24,
			'yas' => 64,
		], [
			'baslik' => 'Anneci erkekler',
			'yt' => 'oj95T1Gxuzs',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/y_oj95T1Gxuzs.webp',
			'bicim' => 'yatay',
			'sayfalar' => ['konya-aile-danismanligi'],
			'sira' => 25,
			'yas' => 65,
		], [
			'baslik' => 'Çocukla kaliteli zaman geçirmek',
			'yt' => 'qeMw0YuRp-Y',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/y_qeMw0YuRp-Y.webp',
			'bicim' => 'yatay',
			'sayfalar' => ['konya-aile-danismanligi', 'konya-pedagog', 'ekran-bagimliligi', 'cocuklarda-yeme-sorunlari-ve-cinsel-gelisim', 'oyun-terapisi'],
			'sira' => 26,
			'yas' => 66,
		], [
			'baslik' => 'Çocuk ödev yapmıyorsa',
			'yt' => 'vWLr8spjbTw',
			'dosya' => '',
			'onizleme' => '',
			'kapak' => 'img/y_vWLr8spjbTw.webp',
			'bicim' => 'yatay',
			'sayfalar' => ['konya-cocuk-ergen-psikologu', 'konya-ergen-psikologu', 'konya-pedagog', 'konya-dehb-dikkat-eksikligi'],
			'sira' => 27,
			'yas' => 67,
		], [
			'baslik' => 'Ya kötü bir şey olursa?',
			'yt' => '',
			'dosya' => 'media/t_DfKuEBgx5OW.mp4',
			'onizleme' => 'media/o_DfKuEBgx5OW.mp4',
			'kapak' => 'media/p_DfKuEBgx5OW.jpg',
			'bicim' => 'dikey',
			'sayfalar' => ['sema-terapi', 'bireysel-terapi'],
			'sira' => 40,
			'yas' => 90,
		], [
			'baslik' => 'Kendi istediğimi seçersem bencil miyim?',
			'yt' => '',
			'dosya' => 'media/t_DfXkB07xkPW.mp4',
			'onizleme' => 'media/o_DfXkB07xkPW.mp4',
			'kapak' => 'media/p_DfXkB07xkPW.jpg',
			'bicim' => 'dikey',
			'sayfalar' => ['sema-terapi'],
			'sira' => 41,
			'yas' => 91,
		], [
			'baslik' => 'Başarınızı görebiliyor musunuz?',
			'yt' => '',
			'dosya' => 'media/t_DfiD1rLxe2i.mp4',
			'onizleme' => 'media/o_DfiD1rLxe2i.mp4',
			'kapak' => 'media/p_DfiD1rLxe2i.jpg',
			'bicim' => 'dikey',
			'sayfalar' => ['sema-terapi'],
			'sira' => 42,
			'yas' => 92,
		], [
			'baslik' => '"Hayır"ı sınır olarak duymak',
			'yt' => '',
			'dosya' => 'media/t_DfnCm64xPJ9.mp4',
			'onizleme' => 'media/o_DfnCm64xPJ9.mp4',
			'kapak' => 'media/p_DfnCm64xPJ9.jpg',
			'bicim' => 'dikey',
			'sayfalar' => ['sema-terapi'],
			'sira' => 43,
			'yas' => 93,
		]],
	'menu_ek' => [
		'ana' => [['Aile danışmanlığı', 'konya-aile-danismanligi', 'calisma-alanlari', 'konya-cift-terapisi'], ['Ergen psikoloğu', 'konya-ergen-psikologu', 'calisma-alanlari', 'konya-cocuk-ergen-psikologu'], ['Kaygı ve panik atak', 'kaygi-ve-panik-atak', 'calisma-alanlari', 'bireysel-terapi'], ['Depresyon', 'depresyon-terapisi', 'calisma-alanlari', 'kaygi-ve-panik-atak']],
		'alt_alanlar' => [['Ergen psikoloğu', 'konya-ergen-psikologu', '', 'konya-cocuk-ergen-psikologu'], ['Aile danışmanlığı', 'konya-aile-danismanligi', '', 'konya-cift-terapisi'], ['Kaygı ve panik atak', 'kaygi-ve-panik-atak', '', 'konya-aile-danismanligi'], ['Depresyon', 'depresyon-terapisi', '', 'kaygi-ve-panik-atak']],
		'alt_kurumsal' => [['Hangi terapi bana uygun?', 'hangi-terapi-bana-uygun', '', 'uzmanlarimiz']],
	],
	'video_metin' => [
		'DfKuEBgx5OW' => ['', 'Aklınız sürekli en kötü ihtimale mi gidiyor? Ali Akbulut, şema terapideki "ya olursa" düşüncesini ve kontrol ettikçe neden rahatlamadığımızı anlatıyor.', '<p>Başınız biraz ağrıdığında aklınıza hemen en kötü hastalık geliyor. Eşiniz telefonu açmadığında bir kaza olduğunu düşünüyorsunuz. Ekonomiyle ilgili bir haber gördüğünüzde bütün birikiminizi kaybedeceğinizi hayal ediyorsunuz.</p><p>Ali Akbulut bu kısa videoda, şema terapide bu düşünce biçimine eşlik eden en sık cümleyi anlatıyor: "Ya olursa?" Bu şemada dünya, zihinde sürekli bir tehlike alanı gibi görünür. Risk sıfırlanmadan rahatlamak mümkün değilmiş gibi gelir. Oysa hayatta risk hiçbir zaman sıfır olmaz. Kontrol ettikçe kısa bir süre rahatlarsınız, sonra zihin yeni bir tehlike bulur.</p><p>Videonun sonunda sorduğu soru şu: Şu anda gerçek bir tehlike mi var, yoksa zihniniz olabilecek en kötü ihtimali olmuş gibi yaşatıp sizi bugünden mi uzaklaştırıyor?</p>'],
		'DfXkB07xkPW' => ['Onsuz kim olduğumu bilmiyorum', 'Karar verirken önce annenizin ne düşündüğünü mü merak ediyorsunuz? Ali Akbulut, iç içe geçmişlik şemasını ve bireyselleşmenin neden suçluluk yarattığını anlatıyor.', '<p>Bir karar vermeden önce annenizin ne düşüneceğini merak ediyorsunuz. Evlisiniz ama önemli kararların merkezinde hâlâ aileniz var. Ya da partneriniz olmadan ne istediğinizi bile bilmiyorsunuz.</p><p>Ali Akbulut bu durumu şema terapideki iç içe geçmişlik şemasıyla açıklıyor. Sağlıklı gelişimde çocuk ailesine bağlanır, zamanla onlardan psikolojik olarak da ayrışır ve "Ben ne istiyorum, ben kimim?" sorularına kendi cevaplarını bulur. Bağ çok iç içe olduğunda ise bireyselleşmek suçluluk yaratır; kendi kararınızı verdiğinizde birine ihanet etmiş gibi hissedersiniz.</p><p>Videoya göre buradaki mesele sevgisizlik değil, sevgiyle bireyselliğin birbirinden ayrılamaması. Sorduğu soru: Hayatımdaki kararlar gerçekten bana mı ait?</p>'],
		'DfiD1rLxe2i' => ['Ben diğerleri kadar başarılı olamam', 'İyi bir iş yaptığınızda bile aklınıza eksikleriniz mi geliyor? Ali Akbulut, başarısızlık şemasının çocuklukla bağını ve iki farklı görünümünü anlatıyor.', '<p>İyi bir iş yaptınız ama aklınıza başarınız değil, eksikleriniz geliyor. Bir arkadaşınız başarılı olduğunda sevinmek yerine "ben geride kaldım" diye düşünüyorsunuz.</p><p>Ali Akbulut bu videoda başarısızlık şemasını anlatıyor. Bu şemadaki kişi çoğu zaman kendi kapasitesini değil, kendisi hakkındaki eski bir inancı yaşar. Çocuklukta sürekli kıyaslanmak, başarının küçümsenmesi, başarısızlıkların fazla vurgulanması ya da güçlü yanların görülmemesi bu algıyı besleyebilir.</p><p>Sonuçta iki farklı tablo ortaya çıkabilir: Biri "nasıl olsa başaramam" deyip hiç denemez. Diğeri başarısız hissetmemek için hayatını başarıya adar, çok çalışır ama hiçbir başarı ona "yeterliyim" duygusunu vermez.</p>'],
		'DfnCm64xPJ9' => ['Benim istediğim olmalı', 'Biri size hayır dediğinde öfkeleniyor musunuz? Ali Akbulut, hak görme şemasını ve ilişkinin neden tek kişinin ihtiyaçları etrafında dönmeye başladığını anlatıyor.', '<p>Sırada beklemek sizi çok rahatsız ediyor. Biri size hayır dediğinde öfkeleniyorsunuz. İlişkide kendi ihtiyaçlarınızın karşılanmasını doğal görürken karşı tarafın ihtiyaçları size yük gibi geliyor.</p><p>Ali Akbulut bu videoda şema terapideki hak görme şemasını anlatıyor. Bu şemada kişi kendini, başkalarının uyduğu sınırların dışında görebilir. Ama altında her zaman kendini çok beğenmek yatmaz: Bazen kişi çocuklukta yeterli sınırla karşılaşmamıştır, bazen de geçmişte yaşadığı değersizlik duygusunu tam tersine çevirerek telafi ediyordur.</p><p>İlişkide sorun, iki kişinin ihtiyaçlarının olduğu bir yerin zamanla tek kişinin ihtiyaçları etrafında dönmeye başlamasıyla çıkar.</p>'],
		'ZO8cy1CXu4U' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda evlilikte yaşanan sorunları anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda evlilikte yaşanan sorunları anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki Konya çift terapisi ve cinsel terapi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'LgUMe20FLuo' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda eşini artık tanıyamadığını hisseden kişilerin yaşadıklarını anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda eşini artık tanıyamadığını hisseden kişilerin yaşadıklarını anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çift terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'IGkzSC_vhO4' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda her tartışmada boşanmayı gündeme getirmenin ilişkiye etkisini anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda her tartışmada boşanmayı gündeme getirmenin ilişkiye etkisini anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çift terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'uIbUVTupdW8' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda kavga olmayan ilişkilerde de sorun olup olamayacağını anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda kavga olmayan ilişkilerde de sorun olup olamayacağını anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çift terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'QLaUAz39cm0' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda ergenlik dönemindeki çocukla iletişimi anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda ergenlik dönemindeki çocukla iletişimi anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çocuk ve ergen terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'PgwQXCcp6ho' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda duygusal yalnızlığı anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda duygusal yalnızlığı anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki bireysel terapi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'wkgUu4B5Qdw' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda cinsel isteksizliği anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda cinsel isteksizliği anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki cinsel terapi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'Ky0dLRvwyBY' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda şema terapinin ne olduğunu anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda şema terapinin ne olduğunu anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki şema terapi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'wONm3fBFGCY' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda hep güçlü görünmek zorunda hisseden insanları anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda hep güçlü görünmek zorunda hisseden insanları anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki bireysel terapi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'ngfsQwJGmsI' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda eşinin kendisini merak etmediğini hissetmeyi anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda eşinin kendisini merak etmediğini hissetmeyi anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çift terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'zHoAkUS87cw' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda vazgeçilemeyen şeyin bazen kişi değil ilişki biçimi olmasını anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda vazgeçilemeyen şeyin bazen kişi değil ilişki biçimi olmasını anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki şema terapi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'Jo4q97x1Bd4' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda hareket çeken ve küfür eden çocuklar karşısında ailelerin yaşadıklarını anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda hareket çeken ve küfür eden çocuklar karşısında ailelerin yaşadıklarını anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çocuk ve ergen terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'R-pZxcHa-wo' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda ilişkilerde sık yapılan bir hatayı anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda ilişkilerde sık yapılan bir hatayı anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çift terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'_4zYTru3R7A' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda çalışan annelerin çocuklarıyla ilişkisini anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda çalışan annelerin çocuklarıyla ilişkisini anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çocuk ve ergen terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'fP3FQjbDqsg' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda 3-6 yaş arası çocuklarda inatlaşmayı anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda 3-6 yaş arası çocuklarda inatlaşmayı anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki pedagog ve oyun terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'gOzBK58FUas' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda hedefe ulaşmanın en önemli adımını anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda hedefe ulaşmanın en önemli adımını anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki bireysel terapi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'oj95T1Gxuzs' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda anneci erkekleri ve bunun evliliğe yansımasını anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda anneci erkekleri ve bunun evliliğe yansımasını anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çift terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'qeMw0YuRp-Y' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda çocukla kaliteli zaman geçirmenin ne demek olduğunu anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda çocukla kaliteli zaman geçirmenin ne demek olduğunu anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki pedagog ve çocuk terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
		'vWLr8spjbTw' => ['', 'Uzm. Klinik Psikolog Ali Akbulut bu videoda ödev yapmak istemeyen çocuklar karşısında ailelerin ne yapabileceğini anlatıyor.', '<p>Uzm. Klinik Psikolog Ali Akbulut bu videoda ödev yapmak istemeyen çocuklar karşısında ailelerin ne yapabileceğini anlatıyor. Video, Ali Akbulut\'un YouTube kanalında yayımlanan serinin bir parçası.</p><p>Konuyla ilgili görüşmelerin nasıl ilerlediğini aşağıdaki çocuk ve ergen terapisi sayfasında okuyabilirsiniz. Görüşmek isterseniz WhatsApp\'tan yazmanız yeterli.</p>'],
	],
	'ek_bag' => [
		'QLaUAz39cm0' => ['konya-ergen-psikologu'],
		'vWLr8spjbTw' => ['konya-ergen-psikologu'],
		'DfKuEBgx5OW' => ['kaygi-ve-panik-atak'],
		'wONm3fBFGCY' => ['kaygi-ve-panik-atak'],
		'PgwQXCcp6ho' => ['depresyon-terapisi'],
		'DfiD1rLxe2i' => ['depresyon-terapisi'],
		'_4zYTru3R7A' => ['konya-aile-danismanligi'],
		'qeMw0YuRp-Y' => ['konya-aile-danismanligi'],
		'fP3FQjbDqsg' => ['konya-aile-danismanligi'],
		'oj95T1Gxuzs' => ['konya-aile-danismanligi', 'konya-cift-terapisi'],
		'DfXkB07xkPW' => ['konya-aile-danismanligi'],
	],
	'alan_menu' => [['Çocuk ve ergen', 'konya-cocuk-ergen-psikologu', [['Ergen psikoloğu', 'konya-ergen-psikologu'], ['Konya pedagog', 'konya-pedagog'], ['Davranış sorunları', 'cocuklarda-davranis-sorunlari'], ['Dikkat eksikliği (DEHB)', 'konya-dehb-dikkat-eksikligi'], ['Özgüven ve içe kapanıklık', 'ozguven-ve-ice-kapaniklik'], ['Ekran bağımlılığı', 'ekran-bagimliligi'], ['Uyku sorunları', 'cocuklarda-uyku-sorunlari'], ['Alt ıslatma ve dışkı kaçırma', 'alt-islatma-ve-diski-kacirma'], ['Tik, kekemelik ve konuşma', 'tik-kekemelik-ve-konusma'], ['Parmak emme ve tırnak yeme', 'parmak-emme-ve-tirnak-yeme'], ['Yeme ve cinsel gelişim', 'cocuklarda-yeme-sorunlari-ve-cinsel-gelisim'], ['Yas ve kayıp', 'cocuklarda-yas-ve-kayip']]], ['Yetişkin', 'bireysel-terapi', [['Kaygı ve panik atak', 'kaygi-ve-panik-atak'], ['Depresyon', 'depresyon-terapisi'], ['OKB (takıntılar)', 'konya-okb'], ['Sosyal fobi', 'sosyal-fobi'], ['Fobiler', 'fobiler'], ['Travma sonrası stres', 'travma-sonrasi-stres'], ['Yeme bozuklukları', 'yeme-bozukluklari'], ['Kişilik bozuklukları', 'kisilik-bozukluklari'], ['Online terapi', 'online-terapi']]], ['Çift, aile ve cinsel terapi', 'konya-cift-terapisi', [['Aile danışmanlığı', 'konya-aile-danismanligi'], ['Cinsel terapi', 'konya-cinsel-terapi'], ['Vajinismus', 'konya-vajinismus'], ['Erkekte cinsel sorunlar', 'erkekte-cinsel-sorunlar'], ['Kurumsal', 'kurumsal']]]],
	'ek_bag2' => [
		'Jo4q97x1Bd4' => ['cocuklarda-davranis-sorunlari', 'konya-dehb-dikkat-eksikligi', 'tik-kekemelik-ve-konusma'],
		'fP3FQjbDqsg' => ['cocuklarda-davranis-sorunlari'],
		'vWLr8spjbTw' => ['konya-dehb-dikkat-eksikligi'],
		'QLaUAz39cm0' => ['ozguven-ve-ice-kapaniklik', 'ekran-bagimliligi'],
		'qeMw0YuRp-Y' => ['ekran-bagimliligi', 'cocuklarda-yeme-sorunlari-ve-cinsel-gelisim'],
		'_4zYTru3R7A' => ['cocuklarda-uyku-sorunlari', 'cocuklarda-yas-ve-kayip'],
		'DfKuEBgx5OW' => ['konya-okb', 'sosyal-fobi', 'fobiler'],
		'wONm3fBFGCY' => ['sosyal-fobi', 'travma-sonrasi-stres'],
		'Ky0dLRvwyBY' => ['kisilik-bozukluklari'],
		'zHoAkUS87cw' => ['kisilik-bozukluklari'],
		'PgwQXCcp6ho' => ['kisilik-bozukluklari'],
		'wkgUu4B5Qdw' => ['erkekte-cinsel-sorunlar'],
	],
	'egitim_eski' => '## Akademik
Psikolojik Danışmanlık ve Rehberlik Lisans
Klinik Psikoloji Yüksek Lisans

## Psikoterapi eğitimleri
Psikonet, Şema Terapi
Davranış Bilimleri Enstitüsü, EMDR 1. Düzey ve süpervizyon
Davranış Bilimleri Enstitüsü, EMDR 2. Düzey ve süpervizyon
Davranış Bilimleri Enstitüsü, EMDR ile Çocuk ve Ergenlerde Performans Geliştirme
Davranış Bilimleri Enstitüsü, EMDR ile Panik Bozukluklar
CİSED, Cinsel Terapi
PHM, Otojenik Terapi

## Hipnoterapi
Sağlık Bakanlığı onaylı hipnoterapist eğitimi
OMNİ Hipnoz Akademisi, Temel ve İleri Düzey Hipnoterapi
HTİ, Dönüşümsel Hipnoterapi ve Klinik Hipnoterapi

## Çocuk ve ergen
APT onaylı Oyun Terapisi
Psikolojik Testler Derneği, WISC-R Zeka Testi Uygulayıcılığı
Psikolojik Testler Derneği, Çocuk ve Ergenlerde Objektif ve Projektif Testler
ÜZE, Play Attention Edufeedback, Dikkat Eksikliği ve Hiperaktivite
PADEM, Terapötik Kartların Çocuk, Ergen ve Yetişkinlerde Kullanımı',
	'egitim_yeni' => '## Akademik
Atatürk Üniversitesi, Psikolojik Danışmanlık ve Rehberlik Lisans
İstanbul Esenyurt Üniversitesi, Klinik Psikoloji Yüksek Lisans

## Psikoterapi eğitimleri
Psikonet, Şema Terapi
Davranış Bilimleri Enstitüsü, EMDR 1. Düzey ve süpervizyon
Davranış Bilimleri Enstitüsü, EMDR 2. Düzey ve süpervizyon
Davranış Bilimleri Enstitüsü, EMDR ile Çocuk ve Ergenlerde Performans Geliştirme
Davranış Bilimleri Enstitüsü, EMDR ile Panik Bozukluklar
CİSED, Cinsel Terapi
PHM, Otojenik Terapi
Adnan Menderes Üniversitesi ADÜSEM, Aile Danışmanlığı Eğitim Programı (480 saat)

## Hipnoterapi
Ankara Yıldırım Beyazıt Üniversitesi, Sağlık Bakanlığı onaylı Hipnoz Uygulama Sertifikası
OMNİ Hipnoz Akademisi, Temel ve İleri Düzey Hipnoterapi
HTİ, Dönüşümsel Hipnoterapi ve Klinik Hipnoterapi

## Çocuk ve ergen
APT onaylı Oyun Terapisi
Psikolojik Testler Derneği, WISC-R Zeka Testi Uygulayıcılığı
Psikolojik Testler Derneği, Çocuk ve Ergenlerde Objektif ve Projektif Testler
ÜZE, Play Attention Edufeedback, Dikkat Eksikliği ve Hiperaktivite
PADEM, Terapötik Kartların Çocuk, Ergen ve Yetişkinlerde Kullanımı',
	'egitim_satir' => [
		'Psikolojik Danışmanlık ve Rehberlik Lisans' => 'Atatürk Üniversitesi, Psikolojik Danışmanlık ve Rehberlik Lisans',
		'Klinik Psikoloji Yüksek Lisans' => 'İstanbul Esenyurt Üniversitesi, Klinik Psikoloji Yüksek Lisans',
		'Sağlık Bakanlığı onaylı hipnoterapist eğitimi' => 'Ankara Yıldırım Beyazıt Üniversitesi, Sağlık Bakanlığı onaylı Hipnoz Uygulama Sertifikası',
	],
];
