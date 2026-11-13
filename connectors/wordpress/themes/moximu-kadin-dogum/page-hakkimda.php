<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
bk_sayfa_bandi(get_the_title());
?>
<section class="bk-hak-giris" style="background-image:url(<?php echo esc_url(bk_gorsel_url('2026/09/IMG_5331-scaled.jpg', 'full')); ?>)">
	<div class="kap">
		<div class="bk-hak-metin">
			<h2>Prof. Dr. Burcu Kısa</h2>
			<p>Kadın Hastalıkları ve Doğum Uzmanı Prof. Dr. Burcu Kısa, kadın sağlığının farklı dönemlerinde koruyucu, tanısal ve tedavi edici yaklaşımları bir arada ele alan; bilimsel gelişmeleri klinik deneyimle buluşturan bir anlayışla çalışmalarını sürdürmektedir.</p>
			<p>2005 yılında Gazi Üniversitesi Tıp Fakültesi’nden mezun olarak tıp doktoru unvanını aldı. Kadın Hastalıkları ve Doğum alanındaki uzmanlık eğitimini 2006–2011 yılları arasında Başkent Üniversitesi’nde tamamladı.</p>
			<p>Uzmanlık eğitiminin ardından mecburi hizmetini Batman Kadın Doğum ve Çocuk Hastalıkları Hastanesi’nde gerçekleştirdi. 2012–2019 yılları arasında Zekai Tahir Burak Kadın Sağlığı Eğitim ve Araştırma Hastanesi’nde görev yaptı. Mesleki kariyerinin devamında Ankara Şehir Hastanesi ve Medical Park Ankara Hastanesi bünyesinde çalışmalarını sürdürerek çok sayıda hastanın tanı, tedavi, gebelik ve doğum süreçlerinde yer aldı.</p>
		</div>
		<div class="bk-hak-foto"><?php echo bk_gorsel('2026/09/IMG_5024-scaled.jpg', 'large', ['alt' => 'Prof. Dr. Burcu Kısa', 'loading' => 'eager', 'sizes' => '(max-width: 767px) 100vw, 360px']); ?></div>
	</div>
</section>
<section class="bk-bolum bk-hak-devam">
	<div class="kap">
		<p>Prof. Dr. Burcu Kısa; gebelik ve doğum takibi, riskli gebelikler, jinekolojik hastalıklar, menopoz, HPV ve genital enfeksiyonlar, laparoskopik ve histeroskopik cerrahi, kısırlık değerlendirmesi ve genital estetik uygulamalar başta olmak üzere kadın sağlığının birçok alanında hizmet vermektedir.</p>
		<p>Mesleki yaklaşımının temelinde, her hastanın ihtiyaçlarının farklı olduğu anlayışı bulunmaktadır. Tanı ve tedavi süreçlerinde güncel bilimsel verileri esas alırken; hastalarının kendilerini rahatça ifade edebildiği, sorularına anlaşılır yanıtlar alabildiği ve tedavi sürecine aktif olarak katılabildiği bir iletişim yaklaşımını benimsemektedir.</p>
		<p>Akademik çalışmaları ve klinik deneyimini birlikte sürdüren Prof. Dr. Burcu Kısa, kadınların ergenlik döneminden gebeliğe, doğumdan menopoza kadar yaşamlarının farklı dönemlerinde ihtiyaç duydukları sağlık hizmetlerini kişiye özel, bilimsel ve bütüncül bir yaklaşımla sunmayı amaçlamaktadır.</p>
		<p>Prof. Dr. Burcu Kısa, hastalarını Ankara Çankaya Mustafa Kemal Mahallesi, Maidan C Blok’ta bulunan özel muayenehanesinde kabul etmektedir.</p>
		<div class="bk-kitap">
			<div class="bk-kitap-gorsel"><?php echo bk_gorsel('2026/09/gebelikte-mutlu-beslenme.png', 'medium', ['alt' => 'Gebelikte Mutlu Beslenme kitabının kapağı']); ?></div>
			<div class="bk-kitap-metin">
				<p class="bk-kitap-etiket"><span class="bk-halka" aria-hidden="true"></span> Yayınlar</p>
				<h2>Gebelikte Mutlu Beslenme</h2>
				<p><strong>Prof. Dr. Burcu Kısa</strong> ve <strong>Uzm. Dyt. Dilan Dalgıç</strong> tarafından hazırlanan kitap; gebelik döneminde beslenmeyi farklı beslenme tercihleri ve gebeliğin değişen ihtiyaçları çerçevesinde ele alan bir rehber niteliğindedir.</p>
				<p>Vegan, vejetaryen ve glütensiz beslenen gebelerin yanı sıra tüm anne adaylarına yönelik başlıklar içerir.</p>
				<a class="bk-btn altin kare" href="https://www.kitapyurdu.com/kitap/gebelikte-mutlu-beslenme-vegan-vejetaryen-glutensiz-beslenen-gebeler-ve-diger-tum-gebeler-icin/687828.html?manufacturer_id=272070" target="_blank" rel="noopener">Kitabı İncele</a>
			</div>
		</div>
	</div>
</section>
<section class="bk-klinik-foto">
	<div class="kap">
		<h2>Klinikten Fotoğraflar</h2>
		<div class="bk-foto-izgara">
			<?php foreach (['IMG_5331', 'IMG_5332', 'IMG_5330', 'IMG_5327', 'IMG_5325', 'IMG_5329', 'IMG_5337', 'IMG_5328'] as $g) :
				$y = '2026/09/' . $g . '-scaled.jpg'; ?>
				<a href="<?php echo esc_url(bk_gorsel_url($y, 'full')); ?>" data-buyut><?php echo bk_gorsel($y, 'medium_large', ['alt' => 'Prof. Dr. Burcu Kısa kliniği, Maidan İş Merkezi', 'sizes' => '(max-width: 767px) 50vw, 290px']); ?></a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php
$k = '';
foreach (array_slice(bk_diger_hizmetler(0), 0, 12) as $p) {
	$k .= bk_hizmet_karti($p->ID);
}
if ($k) : ?>
	<section class="bk-bolum bk-diger">
		<div class="kap">
			<h2 class="bk-h2 siyah">Sunduğum Tedaviler</h2>
			<?php echo bk_serit($k, 'bk-hizmet-serit', 'Sunduğum tedaviler'); ?>
		</div>
	</section>
<?php endif;
get_footer();
