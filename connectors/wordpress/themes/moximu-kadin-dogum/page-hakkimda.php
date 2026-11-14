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
			<p>Uzmanlık eğitiminin ardından mecburi hizmetini Batman Kadın Doğum ve Çocuk Hastalıkları Hastanesi’nde gerçekleştirdi. 2012–2019 yılları arasında Zekai Tahir Burak Kadın Sağlığı Eğitim ve Araştırma Hastanesi’nde görev yaptı. Mesleki kariyerinin devamında Ankara Bilkent Şehir Hastanesi ve Medical Park Ankara Batıkent Hastanesi bünyesinde çalışmalarını sürdürerek çok sayıda hastanın tanı, tedavi, gebelik ve doğum süreçlerinde yer aldı. Yayınları ve çalışmalarıyla doçent, 2024 yılında profesör unvanını aldı.</p>
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
		<div class="bk-ozgecmis">
			<h2>Eğitim ve görevler</h2>
			<ul class="bk-zaman">
				<li><span>1998–2005</span> Gazi Üniversitesi Tıp Fakültesi, tıp doktorluğu eğitimi</li>
				<li><span>2006–2011</span> Başkent Üniversitesi, Kadın Hastalıkları ve Doğum uzmanlık eğitimi (tez danışmanı Prof. Dr. Ali Ayhan)</li>
				<li><span>2011–2012</span> Batman Kadın Doğum ve Çocuk Hastalıkları Hastanesi, mecburi hizmet</li>
				<li><span>2012–2019</span> Zekai Tahir Burak Kadın Sağlığı Eğitim ve Araştırma Hastanesi: doğum ünitesi, yüksek riskli gebelikler, jinekoloji, laparoskopi, histeroskopi, ürojinekoloji ve aile planlaması bölümleri. Bu dönemdeki yayın ve çalışmalarıyla doçent unvanı.</li>
				<li><span>2019</span> Ankara Bilkent Şehir Hastanesi</li>
				<li><span>2019–2026</span> Medical Park Ankara Batıkent Hastanesi</li>
				<li><span>2024</span> Profesör unvanı</li>
				<li><span>Bugün</span> Maidan İş Merkezi C Blok’taki muayenehanesi, Çankaya / Ankara</li>
			</ul>
		</div>
		<div class="bk-yayin">
			<h2>Bilimsel yayınlar</h2>
			<p>Uluslararası hakemli dergilerde yayımlanmış ve PubMed’de kayıtlı çalışmalarından seçilenler. Çalışmaların çoğu gebelik şekerinin erken öngörülmesi ve gebelik sonuçları üzerinedir.</p>
			<ol>
				<?php foreach (bk_yayinlar() as [$yazarlar, $baslik, $dergi, $yil, $pmid, $doi, $konu]) : ?>
					<li><span class="konu"><?php echo esc_html($konu); ?></span>
					<?php echo preg_replace('/K[ıi]sa B(?=,|$)/u', '<b>$0</b>', esc_html($yazarlar)); ?>. <?php echo esc_html($baslik); ?>. <i><?php echo esc_html($dergi); ?></i>, <?php echo esc_html((string) $yil); ?>.
					<a href="<?php echo esc_url('https://pubmed.ncbi.nlm.nih.gov/' . $pmid . '/'); ?>" target="_blank" rel="noopener">PubMed <?php echo esc_html($pmid); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</div>
		<style>.bk-ozgecmis,.bk-yayin{margin-top:50px}.bk-ozgecmis h2,.bk-yayin h2{font-size:25px;font-weight:600;color:#000;margin:0 0 16px}.bk-zaman{list-style:none;margin:0;padding:0;border-top:1px solid var(--kenar)}.bk-zaman li{display:grid;grid-template-columns:110px 1fr;gap:16px;padding:12px 0;border-bottom:1px solid var(--kenar);font-size:15px;line-height:1.55;color:#000}.bk-zaman span{font-weight:600;color:var(--altin)}.bk-hak-devam .bk-yayin>p{font-size:15px;line-height:1.6;margin:0 0 14px}.bk-yayin ol{margin:0;padding-left:20px}.bk-yayin li{font-size:14px;line-height:1.6;color:#000;margin-bottom:14px}.bk-yayin li b{font-weight:600}.bk-yayin .konu{display:block;font-size:15px;font-weight:600;margin-bottom:2px}.bk-yayin a{color:var(--altin);text-decoration:underline}@media (max-width:767px){.bk-zaman li{grid-template-columns:1fr;gap:2px}}</style>
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
		<h2>Muayenehaneden Fotoğraflar</h2>
		<div class="bk-foto-izgara">
			<?php foreach (['IMG_5331', 'IMG_5332', 'IMG_5330', 'IMG_5327', 'IMG_5325', 'IMG_5329', 'IMG_5337', 'IMG_5328'] as $g) :
				$y = '2026/09/' . $g . '-scaled.jpg'; ?>
				<a href="<?php echo esc_url(bk_gorsel_url($y, 'full')); ?>" data-buyut><?php echo bk_gorsel($y, 'medium_large', ['alt' => 'Prof. Dr. Burcu Kısa muayenehanesi, Maidan İş Merkezi', 'sizes' => '(max-width: 767px) 50vw, 290px']); ?></a>
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
