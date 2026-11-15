<?php
/**
 * Sorular ve Cevaplar ana sayfası (/sorular-cevaplar/): giriş ve arama, başlıklara atlama dizini,
 * her başlık için öne çıkan sorular ve kategori sayfasındaki konu bölümlerine bağlantılar.
 */
if (! defined('ABSPATH')) {
	exit;
}
add_action('wp_enqueue_scripts', function () {
	$css = BK_DIR . '/assets/sorular-ana.css';
	wp_enqueue_style('bk-sorular-ana', BK_URI . '/assets/sorular-ana.css', ['bk-site'], BK_VER . '.' . (file_exists($css) ? filemtime($css) : 0));
}, 10000); // inc/temizlik.php izin listesinden (9999) sonra
get_header();
bk_sayfa_bandi('Sıkça Sorulan Sorular', '2026/09/IMG_5329-scaled.jpg', '', true);

$basliklar = [];
$toplam = 0;
foreach (bk_soru_kategorileri() as $k) {
	$duzen = function_exists('bk_kategori_duzeni')
		? bk_kategori_duzeni($k->term_id)
		: ['ayri' => bk_kategori_sorulari($k->term_id), 'konular' => [], 'toplam' => (int) $k->count];
	$toplam += (int) $duzen['toplam'];
	$basliklar[] = [$k, $duzen];
}
$sayi = fn(int $n): string => number_format_i18n($n);
?>
<section class="bk-sss2">
	<div class="kap">
		<div class="bk-sss2-giris">
			<div class="bk-sss2-giris-metin">
				<p class="bk-sss2-ozet">Gebelikten menopoza, kadın sağlığıyla ilgili sık sorulan soruları <?php echo esc_html($sayi(count($basliklar))); ?> başlıkta topladık. Başlığınızı seçin ya da sorunuzu yazın.</p>
				<?php get_template_part('parts/soru-ara'); ?>
				<p class="bk-sss2-toplam"><?php echo esc_html($sayi($toplam) . ' soru ve yanıt'); ?></p>
			</div>
			<div class="bk-sss2-hekim">
				<?php echo bk_gorsel('2026/09/prof-dr-burcu-kisa-1.png', 'medium', ['alt' => bk_ayar('hekim'), 'class' => 'bk-sss2-hekim-foto', 'width' => '96', 'height' => '96']); ?>
				<div>
					<p class="bk-sss2-hekim-ad">Prof. Dr. Burcu Kısa</p>
					<p class="bk-sss2-hekim-unvan">Kadın Hastalıkları ve Doğum Uzmanı, Ankara Çankaya</p>
					<p class="bk-sss2-hekim-not">Yanıtlar genel bilgilendirme içindir. Size özel değerlendirme muayenede yapılır.</p>
					<a class="bk-sss2-hekim-link" href="<?php echo esc_url(bk_url('/randevu-olustur/')); ?>">Randevu oluşturun <?php echo bk_ikon('sag'); ?></a>
				</div>
			</div>
		</div>

		<nav class="bk-sss2-dizin" aria-label="Soru başlıkları">
			<ul>
				<?php foreach ($basliklar as [$k, $duzen]) : ?>
					<li><a href="#<?php echo esc_attr($k->slug); ?>"><?php echo esc_html($k->name); ?> <span><?php echo esc_html($sayi((int) $duzen['toplam'])); ?></span></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<?php foreach ($basliklar as [$k, $duzen]) :
			$adres = get_term_link($k);
			$adres = is_wp_error($adres) ? '' : $adres;
			$one = array_slice($duzen['ayri'], 0, 5);
			?>
			<section class="bk-sss2-kat" id="<?php echo esc_attr($k->slug); ?>" aria-labelledby="baslik-<?php echo esc_attr($k->slug); ?>">
				<div class="bk-sss2-kat-sol">
					<h2 id="baslik-<?php echo esc_attr($k->slug); ?>"><a href="<?php echo esc_url($adres); ?>"><?php echo esc_html($k->name); ?></a></h2>
					<p class="bk-sss2-kat-sayi">
						<?php
						echo esc_html($sayi((int) $duzen['toplam']) . ' soru');
						if ($duzen['konular']) {
							echo esc_html(', ' . $sayi(count($duzen['konular'])) . ' konu');
						}
						?>
					</p>
					<a class="bk-btn cizgi" href="<?php echo esc_url($adres); ?>">Tüm soruları görün</a>
				</div>
				<div class="bk-sss2-kat-sag">
					<?php if ($one) : ?>
						<h3>Öne çıkan sorular</h3>
						<ul class="bk-sss2-sorular">
							<?php foreach ($one as $p) : ?>
								<li><a href="<?php echo esc_url(get_permalink($p)); ?>"><span><?php echo esc_html(get_the_title($p)); ?></span><?php echo bk_ikon('sag'); ?></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ($duzen['konular']) : ?>
						<h3>Konular</h3>
						<ul class="bk-sss2-konular">
							<?php if (count($duzen['ayri']) > count($one)) : ?>
								<li><a href="<?php echo esc_url($adres . '#en-cok-aranan'); ?>">En çok aranan sorular <span><?php echo esc_html($sayi(count($duzen['ayri']))); ?></span></a></li>
							<?php endif; ?>
							<?php foreach ($duzen['konular'] as $i => [$ad, $liste]) : ?>
								<li><a href="<?php echo esc_url($adres . '#konu-' . ($i + 1)); ?>"><?php echo esc_html($ad); ?> <span><?php echo esc_html($sayi(count($liste))); ?></span></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</section>
		<?php endforeach; ?>
	</div>
</section>
<?php
get_footer();
