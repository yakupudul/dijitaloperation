<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
$wa = bk_ayar('whatsapp_link');
$hakkimda = bk_url('/hakkimda/');
// Ana sayfa kartları: eski sitedeki sıra. Son kart "Tümünü Keşfedin".
$kartlar = [10003, 10018, 10044, 12828, 10029, 10069, 10084, 10035, 10055, 10042, 12829, 10041, 10068, 10080, 10083];
$kartlar = array_values(array_filter($kartlar, fn($id) => get_post_status($id) === 'publish'));
?>
<section class="bk-hero">
	<div class="kap">
		<div class="bk-hero-metin">
			<?php echo bk_gorsel('2026/09/beyazikon.png', 'thumbnail', ['alt' => '', 'class' => 'bk-hero-ikon', 'width' => '65', 'height' => '52', 'loading' => 'eager']); ?>
			<p class="bk-hero-ust">Maidan kadın doğum uzmanı</p>
			<h1>Ankara Kadın Doğum Uzmanı | Prof. Dr. Burcu Kısa</h1>
			<p class="bk-hero-p">Ankara Çankaya’daki Maidan İş Merkezi’nde bulunan muayenehanemde gebelik ve doğum, genital estetik uygulamaları, HPV, siğil tedavileri ve kolposkopi, menopozda biyoözdeş hormon tedavileri, miyom, kist ve diğer jinekolojik operasyonlar, aşılama (IUI), idrar kaçırma ve rahim sarkması tedavileri, PKOS ve adet düzensizliği tedavisi ile kadın kanserleri taramaları alanlarında hizmet veriyorum. Takip planınızı kişisel sağlık öykünüz ve muayene bulgularınız doğrultusunda oluşturuyorum.</p>
			<div class="bk-btn-grup">
				<a class="bk-btn buyuk yesil" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo bk_ikon('wa'); ?> Randevu Al</a>
				<a class="bk-btn buyuk acik-altin" href="<?php echo esc_url($hakkimda); ?>">Hakkımda</a>
			</div>
		</div>
		<div class="bk-hero-gorsel">
			<?php echo bk_gorsel('2026/11/WhatsApp-Image-2026-10-28-at-10.13.56.jpeg', 'large', ['alt' => 'Prof. Dr. Burcu Kısa, Maidan İş Merkezi\'ndeki muayenehanesinde', 'loading' => 'eager', 'fetchpriority' => 'high', 'data-no-lazy' => '1', 'sizes' => '(max-width: 767px) 100vw, 568px']); ?>
		</div>
	</div>
</section>

<section class="bk-bolum bk-hizmetler">
	<div class="kap">
		<h2 class="bk-h2">Kadın Sağlığı Hizmetlerim</h2>
		<p class="bk-giris">Öncelikli hizmet başlıklarım ve diğer kadın sağlığı alanları hakkında bilgilere aşağıdaki kartlardan ulaşabilirsiniz. Değerlendirme ve takip gereksinimleri, kişinin sağlık durumu ve muayene bulgularına göre belirlenir.</p>
		<hr class="bk-ayrac">
		<div class="bk-kart-izgara">
			<?php foreach ($kartlar as $id) {
				echo bk_hizmet_karti($id);
			} ?>
			<a class="bk-hkart bk-hkart-tumu" href="<?php echo esc_url(bk_url('/hizmetlerim/')); ?>">
				<?php echo bk_gorsel('2026/09/IMG_5329-scaled.jpg', 'medium_large', ['alt' => '', 'class' => 'bk-hkart-img']); ?>
				<span class="bk-hkart-ic"><span class="bk-btn altin">Tümünü Keşfedin</span></span>
			</a>
		</div>
	</div>
</section>

<section class="bk-bolum bk-ana-hakkimda">
	<div class="kap">
		<div class="bk-ana-hakkimda-kutu">
			<p class="bk-h4">Hakkımda</p>
			<h2 class="bk-h2">Prof. Dr. Burcu Kısa</h2>
			<p>2005 yılında Gazi Üniversitesi Tıp Fakültesi’nden mezun olarak tıp doktoru unvanını aldı. Kadın Hastalıkları ve Doğum alanındaki uzmanlık eğitimini 2006–2011 yılları arasında Başkent Üniversitesi’nde tamamladı.</p>
			<p>Kadın Hastalıkları ve Doğum Uzmanı Prof. Dr. Burcu Kısa, kadın sağlığının farklı dönemlerinde koruyucu, tanısal ve tedavi edici yaklaşımları bir arada ele alan; bilimsel gelişmeleri klinik deneyimle buluşturan bir anlayışla çalışmalarını Ankara Çankaya Maidan iş merkezindeki kliniğinde sürdürmektedir.</p>
			<a class="bk-devam" href="<?php echo esc_url($hakkimda); ?>">Devamını oku</a>
			<div class="bk-btn-grup">
				<a class="bk-btn buyuk yesil" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo bk_ikon('wa'); ?> Randevu Al</a>
				<a class="bk-btn buyuk acik-altin" href="<?php echo esc_url(bk_url('/hizmetlerim/')); ?>">Hizmetlerim</a>
			</div>
		</div>
	</div>
</section>

<section class="bk-bolum bk-galeri-bolum">
	<div class="kap">
		<h2 class="bk-h2">Fotoğraf Galerisi</h2>
		<?php
		$g = '';
		foreach (['2026/11/WhatsApp-Image-2026-10-28-at-10.13.56.jpeg', '2026/11/WhatsApp-Image-2026-10-28-at-10.14.09.jpeg', '2026/09/IMG_5330-scaled.jpg', '2026/09/IMG_5331-scaled.jpg', '2026/09/IMG_5332-scaled.jpg', '2026/09/IMG_5327-scaled.jpg', '2026/09/IMG_5325-scaled.jpg', '2026/09/IMG_5328-scaled.jpg', '2026/09/IMG_5329-scaled.jpg'] as $y) {
			$g .= '<a class="bk-galeri-oge" href="' . esc_url(bk_gorsel_url($y, 'full')) . '" data-buyut>' . bk_gorsel($y, 'medium_large', ['alt' => 'Prof. Dr. Burcu Kısa kliniğinden fotoğraf', 'sizes' => '(max-width: 767px) 80vw, 212px']) . '</a>';
		}
		echo bk_serit($g, 'bk-galeri-serit', 'Fotoğraf galerisi');
		?>
	</div>
</section>

<section class="bk-bolum bk-cta-bolum">
	<div class="kap">
		<div class="bk-cta">
			<div>
				<h2>Randevu Talebi Oluşturun</h2>
				<p>Gebelik takibi, doğum, kadın sağlığı ve genital estetik danışmanlığı için bize ulaşın.</p>
			</div>
			<div class="bk-cta-btn">
				<a class="bk-btn buyuk yesil" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo bk_ikon('wa'); ?> Whatsapp</a>
				<a class="bk-btn buyuk acik-altin" href="<?php echo esc_url(bk_ayar('telefon_link')); ?>">Kliniği Arayın</a>
				<a class="bk-btn buyuk beyaz" href="<?php echo esc_url(bk_url('/randevu-olustur/')); ?>">Form Doldurun</a>
			</div>
		</div>
	</div>
</section>

<section class="bk-bolum bk-ozellik">
	<div class="kap">
		<h2 class="bk-h2 orta">Kadın Sağlığında Güvenilir ve<br>Özenli Bir Dokunuş</h2>
		<div class="bk-ozellik-izgara">
			<div class="bk-ozellik-sutun sag">
				<?php foreach ([['Kişiye Özel Takip Planı', 'Muayene ve kontrol takviminizi yaşamınıza, sağlığınıza ve beklentilerinize göre hazırlıyoruz.'], ['4D Ultrason ve Tarama', 'Bebeğinizin gelişimini detaylı ultrason ve güncel tarama yöntemleriyle yakından izliyoruz.'], ['Doğuma Kadar Aynı Hekim', 'Gebeliğinizin başından itibaren, doğumunuzda da yanınızdayız.']] as [$b, $m]) : ?>
					<div class="bk-ikon-kutu"><div><h3><?php echo esc_html($b); ?></h3><p><?php echo esc_html($m); ?></p></div><?php echo bk_ikon('yildiz'); ?></div>
				<?php endforeach; ?>
			</div>
			<div class="bk-ozellik-gorsel"><?php echo bk_gorsel('2026/11/WhatsApp-Image-2026-10-28-at-10.14.09.jpeg', 'large', ['alt' => 'Prof. Dr. Burcu Kısa', 'sizes' => '(max-width: 767px) 100vw, 380px']); ?></div>
			<div class="bk-ozellik-sutun sol">
				<?php foreach ([['Mahremiyet ve Güven', 'Tüm görüşmeler ve uygulamalar gizlilik esasına göre yürütülür.'], ['Doğal Estetik Sonuçlar', 'Genital estetikte hem konfor hem doğal görünüm hedeflenir.'], ['Şeffaf Bilgilendirme', 'Her aşama, seçenekleri ve riskleriyle birlikte açıkça anlatılır.']] as [$b, $m]) : ?>
					<div class="bk-ikon-kutu"><?php echo bk_ikon('yildiz'); ?><div><h3><?php echo esc_html($b); ?></h3><p><?php echo esc_html($m); ?></p></div></div>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="bk-iki-metin">
			<div>
				<h3 class="bk-h4b">Gebelik Takibi</h3>
				<p>Gebeliğin başlangıcından doğum öncesi döneme kadar muayeneler, tarama testleri ve takip planı hakkında bilgi edinin. Kontroller, anne adayının sağlık durumu ve gebeliğin seyrine göre planlanır.</p>
				<p><strong><a class="bk-pembe" href="<?php echo esc_url(bk_url('/hizmetlerim/gebelik-ve-dogum/gebelik-takibi-ankara/')); ?>">Detaylı bilgi</a></strong></p>
			</div>
			<div>
				<h3 class="bk-h4b">Vajina Daraltma ve Vajinal Gevşeklik Değerlendirmesi</h3>
				<p>Vajinal gevşeklik şikâyetinin değerlendirilmesi, vajinoplasti, tedavi alternatifleri ve iyileşme süreci hakkında bilgi edinin. İşlem gereksinimi ve uygun yöntem, kişisel değerlendirme sonucunda belirlenir.</p>
				<p><strong><a class="bk-pembe" href="<?php echo esc_url(bk_url('/hizmetlerim/genital-estetik-uygulamalari/vajina-daraltma-ameliyati-vajinoplasti-ankara/')); ?>">Detaylı Bilgi</a></strong></p>
			</div>
		</div>
	</div>
</section>

<?php $yorum = bk_yorumlar_html(); ?>
<section class="bk-yorumlar">
	<div class="kap">
		<h2>Değerli misafirlerim</h2>
		<?php echo $yorum; ?>
	</div>
</section>

<section class="bk-bolum bk-ana-blog">
	<div class="kap">
		<?php
		$yazilar = get_posts(['numberposts' => 10, 'post_status' => 'publish', 'suppress_filters' => false]);
		$b = '';
		foreach ($yazilar as $p) {
			$b .= bk_blog_karti($p);
		}
		?>
		<div class="bk-serit-baslik"><h2 class="bk-h2">Blog Yazılarım</h2></div>
		<?php echo bk_serit($b, 'bk-blog-serit ust-ok', 'Blog yazıları'); ?>
	</div>
</section>
<?php
get_footer();
