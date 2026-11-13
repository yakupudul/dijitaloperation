<?php
/** Masaüstü hizmet menüsü: eski sitedeki sıra ve sütunlar (Görünüm › Menüler'de 'Hizmet menüsü' atanırsa o kullanılır). */
if (! defined('ABSPATH')) {
	exit;
}

function bk_hizmet_menusu_veri(): array
{
	return [
		['Gebelik', '/hizmetlerim/gebelik-ve-dogum/', [
			[
				['Gebelik Takibi', '/hizmetlerim/gebelik-ve-dogum/gebelik-takibi-ankara/'],
				['Gebelik ve Doğum Takip Paketleri', '/hizmetlerim/gebelik-ve-dogum/gebelik-ve-dogum-takip-paketleri-ankara/'],
				['Sezaryen Doğum', '/hizmetlerim/gebelik-ve-dogum/sezaryen-dogum-ankara/'],
				['Vajinal Doğum (Normal Doğum)', '/hizmetlerim/gebelik-ve-dogum/vajinal-dogum-normal-dogum-ankara/'],
				['Riskli Gebelik Takibi', '/hizmetlerim/gebelik-ve-dogum/riskli-gebelik-takibi-ankara/'],
				['4 Boyutlu Gebelik Ultrasonu', '/hizmetlerim/gebelik-ve-dogum/4-boyutlu-gebelik-ultrasonu-ankara/'],
				['Dış Gebelik Tanı ve Tedavisi', '/hizmetlerim/gebelik-ve-dogum/dis-gebelik-tani-ve-tedavisi-ankara/'],
				['Düşük Sonrası Takip ve Tedavi', '/hizmetlerim/gebelik-ve-dogum/dusuk-sonrasi-takip-ve-tedavi-ankara/'],
				['NST Testi', '/hizmetlerim/gebelik-ve-dogum/nst-testi-ankara/'],
			],
			[
				['Gebelik Öncesi Danışmanlık', '/hizmetlerim/gebelik-ve-dogum/gebelik-oncesi-danismanlik-ankara/'],
				['Gebelikte Tarama Testleri', '/hizmetlerim/gebelik-ve-dogum/gebelikte-tarama-testleri-ankara/'],
				['Sezaryen Sonrası Vajinal Doğum Değerlendirmesi', '/hizmetlerim/gebelik-ve-dogum/sezaryen-sonrasi-vajinal-dogum-degerlendirmesi-ankara/'],
				['Çoğul Gebelik Takibi', '/hizmetlerim/gebelik-ve-dogum/cogul-gebelik-takibi-ankara/'],
				['Lohusalık Takibi', '/hizmetlerim/gebelik-ve-dogum/lohusalik-takibi-ankara/'],
				['Doğum Kontrol Yöntemleri Danışmanlığı', '/hizmetlerim/gebelik-ve-dogum/dogum-kontrol-yontemleri-danismanligi-ankara/'],
				['Tüp Bağlama', '/hizmetlerim/gebelik-ve-dogum/tup-baglama-ankara/'],
			],
		]],
		['Genital Estetik', '/hizmetlerim/genital-estetik-uygulamalari/', [
			[
				['Vajina Daraltma Ameliyatı (Vajinoplasti)', '/hizmetlerim/genital-estetik-uygulamalari/vajina-daraltma-ameliyati-vajinoplasti-ankara/'],
				['Lazerle Vajina Daraltma', '/hizmetlerim/genital-estetik-uygulamalari/lazerle-vajina-daraltma-ankara/'],
				['Barbie Genital Estetik', '/hizmetlerim/genital-estetik-uygulamalari/barbie-genital-estetik-ankara/'],
				['İç Dudak Estetiği (Labioplasti)', '/hizmetlerim/genital-estetik-uygulamalari/ic-dudak-estetigi-labioplasti-ankara/'],
				['Perine Estetiği (Doğum İzi Onarımı)', '/hizmetlerim/genital-estetik-uygulamalari/perine-estetigi-dogum-izi-onarimi-ankara/'],
				['Klitoris Üstü Deri Estetiği (Hudoplasti)', '/hizmetlerim/genital-estetik-uygulamalari/klitoris-ustu-deri-estetigi-hudoplasti-ankara/'],
				['G Noktası Dolgusu', '/hizmetlerim/genital-estetik-uygulamalari/g-noktasi-dolgusu-ankara/'],
				['Genital Bölge Beyazlatma', '/hizmetlerim/genital-estetik-uygulamalari/genital-bolge-beyazlatma-ankara/'],
				['Genital Dolgu Uygulamaları', '/hizmetlerim/genital-estetik-uygulamalari/genital-dolgu-uygulamalari-ankara/'],
				['O-Shot Uygulaması', '/hizmetlerim/genital-estetik-uygulamalari/o-shot-uygulamasi-ankara/'],
			],
		]],
		['Jinekolojik Cerrahi', '/hizmetlerim/jinekolojik-cerrahi/', [
			[
				['Histeroskopi', '/hizmetlerim/jinekolojik-cerrahi/histeroskopi-ankara/'],
				['Rahim Alma Ameliyatı', '/hizmetlerim/jinekolojik-cerrahi/rahim-alma-ameliyati-ankara/'],
				['Kapalı Rahim Alma Ameliyatı', '/hizmetlerim/jinekolojik-cerrahi/kapali-rahim-alma-ameliyati-ankara/'],
				['Rahim İçi Polip Tedavisi', '/hizmetlerim/jinekolojik-cerrahi/rahim-ici-polip-tedavisi-ankara/'],
				['Kapalı Tüp Bağlama Ameliyatı', '/hizmetlerim/jinekolojik-cerrahi/kapali-tup-baglama-ameliyati-ankara/'],
				['Kapalı Yumurtalık Kisti Ameliyatı', '/hizmetlerim/jinekolojik-cerrahi/kapali-yumurtalik-kisti-ameliyati-ankara/'],
				['Miyom Ameliyatı', '/hizmetlerim/jinekolojik-cerrahi/miyom-ameliyati-ankara/'],
				['Yumurtalık Kisti Takibi ve Tedavisi', '/hizmetlerim/jinekolojik-cerrahi/yumurtalik-kisti-takibi-ve-tedavisi-ankara/'],
				['Laparoskopik Cerrahi', '/hizmetlerim/jinekolojik-cerrahi/laparoskopik-cerrahi-ankara/'],
				['Yumurtalık Kisti Ameliyatı', '/hizmetlerim/jinekolojik-cerrahi/yumurtalik-kisti-ameliyati-ankara/'],
			],
		]],
		['Kadın Hastalıkları', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/', [
			[
				['Cinsel İsteksizlik Danışmanlığı', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/cinsel-isteksizlik-danismanligi-ankara/'],
				['Genital Siğil Tedavisi ve HPV Takibi', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/genital-sigil-tedavisi-ve-hpv-takibi-ankara/'],
				['Kızlık Zarı Değerlendirmesi', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/kizlik-zari-degerlendirmesi-ankara/'],
				['Kızlık Zarı Onarımı (Himenoplasti)', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/kizlik-zari-onarimi-himenoplasti-ankara/'],
				['Adet Düzensizliği Tedavisi', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/adet-duzensizligi-tedavisi-ankara/'],
				['HPV Aşısı', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/hpv-asisi-ankara/'],
				['Kolposkopi', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/kolposkopi-ankara/'],
				['Kadın Kanserleri Taramaları', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/kadin-kanserleri-taramalari-ankara/'],
				['Endometriozis ve Çikolata Kisti', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/endometriozis-ve-cikolata-kisti-ankara/'],
				['Adenomyozis', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/adenomyozis-ankara/'],
				['Ergenlik Dönemi Jinekolojik Sorunları', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/ergenlik-donemi-jinekolojik-sorunlari-ankara/'],
				['Hormonlu Spiral', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/hormonlu-spiral-ankara/'],
			],
			[
				['Orgazm Sorunları ve Danışmanlık', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/orgazm-sorunlari-ve-danismanlik-ankara/'],
				['Spiral Takılması ve Çıkarılması', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/spiral-takilmasi-ve-cikarilmasi-ankara/'],
				['Vajinismus Tedavisi', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/vajinismus-tedavisi-ankara/'],
				['Jinekolojik Muayene', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/jinekolojik-muayene-ankara/'],
				['Ağrılı Adet Görme', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/agrili-adet-gorme-ankara/'],
				['Aşırı ve Uzun Süren Adet Kanaması', '/soru-cevap/asiri-adet-kanamasi-menoraji-tehlikeli-midir/'],
				['Polikistik Over Sendromu (PKOS) Tedavisi', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/polikistik-over-sendromu-pkos-tedavisi-ankara/'],
				['Vajinal Akıntı ve Kaşıntı Tedavisi', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/vajinal-akinti-ve-kasinti-tedavisi-ankara/'],
				['Vajinal Kuruluk Tedavisi', '/hizmetlerim/kadin-hastaliklari-ve-cinsel-saglik/vajinal-kuruluk-tedavisi-ankara/'],
			],
		]],
		['Kısırlık ve Tüp Bebek', '/hizmetlerim/kisirlik-ve-tup-bebek-tedavileri/', [
			[
				['Tüp Bebek Tedavisi', '/hizmetlerim/kisirlik-ve-tup-bebek-tedavileri/tup-bebek-tedavisi-ankara/'],
				['Yumurtlama Takibi', '/hizmetlerim/kisirlik-ve-tup-bebek-tedavileri/yumurtlama-takibi-ankara/'],
				['Aşılama Tedavisi (IUI)', '/hizmetlerim/kisirlik-ve-tup-bebek-tedavileri/asilama-tedavisi-iui-ankara/'],
				['Rahim Filmi (HSG)', '/hizmetlerim/kisirlik-ve-tup-bebek-tedavileri/rahim-filmi-hsg-ankara/'],
				['Kısırlık Değerlendirmesi ve Tedavisi', '/hizmetlerim/kisirlik-ve-tup-bebek-tedavileri/kisirlik-degerlendirmesi-ve-tedavisi-ankara/'],
				['Yumurtalık Rezervi Değerlendirmesi', '/hizmetlerim/kisirlik-ve-tup-bebek-tedavileri/yumurtalik-rezervi-degerlendirmesi-ankara/'],
				['Tekrarlayan Gebelik Kaybı Değerlendirmesi', '/hizmetlerim/kisirlik-ve-tup-bebek-tedavileri/tekrarlayan-gebelik-kaybi-degerlendirmesi-ankara/'],
			],
		]],
		['Menopoz', '/hizmetlerim/menopoz-ve-ileri-yas-kadin-sagligi/', [
			[
				['Menopoz Takibi ve Tedavisi', '/hizmetlerim/menopoz-ve-ileri-yas-kadin-sagligi/menopoz-takibi-ve-tedavisi-ankara/'],
				['Menopozda Hormon Tedavisi Değerlendirmesi', '/hizmetlerim/menopoz-ve-ileri-yas-kadin-sagligi/menopozda-hormon-tedavisi-degerlendirmesi-ankara/'],
				['Menopozda Biyoözdeş Hormon Tedavisi', '/hizmetlerim/menopoz-ve-ileri-yas-kadin-sagligi/menopozda-biyoozdes-hormon-tedavisi-ankara/'],
				['Menopoz Sonrası Kanama', '/hizmetlerim/menopoz-ve-ileri-yas-kadin-sagligi/menopoz-sonrasi-kanama-ankara/'],
				['Menopozda Sıcak Basması Tedavisi', '/hizmetlerim/menopoz-ve-ileri-yas-kadin-sagligi/menopozda-sicak-basmasi-tedavisi-ankara/'],
			],
		]],
		['Ürojinekoloji', '/hizmetlerim/urojinekoloji-ve-pelvik-taban/', [
			[
				['İdrar Kaçırma', '/hizmetlerim/urojinekoloji-ve-pelvik-taban/idrar-kacirma-ankara/'],
				['İdrar Kaçırma Ameliyatı (TOT)', '/hizmetlerim/urojinekoloji-ve-pelvik-taban/idrar-kacirma-ameliyati-tot-ankara/'],
				['İdrar Kaçırmada Lazer Uygulamaları', '/hizmetlerim/urojinekoloji-ve-pelvik-taban/idrar-kacirmada-lazer-uygulamalari-ankara/'],
				['Rahim Sarkması Tedavisi', '/hizmetlerim/urojinekoloji-ve-pelvik-taban/rahim-sarkmasi-tedavisi-ankara/'],
				['Rahim ve Vajina Sarkması', '/hizmetlerim/urojinekoloji-ve-pelvik-taban/rahim-ve-vajina-sarkmasi-ankara/'],
				['Pelvik Ağrı', '/hizmetlerim/urojinekoloji-ve-pelvik-taban/pelvik-agri-ankara/'],
				['Pelvik Taban Değerlendirmesi', '/hizmetlerim/urojinekoloji-ve-pelvik-taban/pelvik-taban-degerlendirmesi-ankara/'],
			],
		]],
	];
}
