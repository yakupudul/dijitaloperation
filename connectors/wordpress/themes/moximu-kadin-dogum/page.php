<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
$id = get_the_ID();

if (bk_hizmet_sayfasi_mi($id)) :
	$icerik = bk_icerik_hazirla($id, true);
	?>
	<div class="kap bk-hizmet-duzen">
		<article class="bk-ana-sutun bk-golge">
			<div class="bk-hizmet-ust">
				<div class="bk-hizmet-baslik">
					<?php bk_kirinti('acik'); ?>
					<h1><?php the_title(); ?></h1>
				</div>
				<div class="bk-hizmet-gorsel">
					<?php if (has_post_thumbnail()) {
						the_post_thumbnail('medium_large', ['loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width: 767px) 100vw, 360px']);
					} ?>
				</div>
			</div>
			<?php echo bk_guven_karti(); ?>
			<p class="bk-yazar"><?php echo bk_ikon('kisi'); ?> <a href="<?php echo esc_url(bk_url('/hakkimda/')); ?>" rel="author"><?php echo esc_html(bk_ayar('hekim')); ?></a> <span>· Son güncelleme: <time datetime="<?php echo esc_attr(get_the_modified_date('Y-m-d')); ?>"><?php echo esc_html(get_the_modified_date('j F Y')); ?></time></span></p>
			<?php bk_icindekiler($icerik['toc']); ?>
			<div class="bk-icerik"><?php echo $icerik['html']; ?></div>
			<div class="bk-uyari">
				<p>Bu içerik, kadın sağlığı konusunda anlaşılır ve doğru bilgi sunmak amacıyla hazırlanmış; tıbbi doğruluk ve güncellik açısından Prof. Dr. Burcu Kısa tarafından incelenmiş ve onaylanmıştır. Paylaşılan bilgiler genel bilgilendirme niteliğindedir; hekim muayenesinin, kişiye özel tanı ve tedavi önerilerinin yerine geçmez. Her kişinin sağlık durumu ve ihtiyaçları farklı olduğundan, değerlendirme ve tedavi süreci bireysel olarak planlanmalıdır.</p>
			</div>
		</article>
		<aside class="bk-yan">
			<?php bk_hekim_karti('hizmet'); ?>
			<?php bk_iletisim_kutusu(); ?>
		</aside>
	</div>
	<?php
	$diger = bk_diger_hizmetler($id);
	if ($diger) :
		$k = '';
		foreach ($diger as $p) {
			$k .= bk_hizmet_karti($p->ID);
		}
		?>
		<section class="bk-bolum bk-diger">
			<div class="kap">
				<h2 class="bk-h2 siyah">Diğer Hizmetlerim</h2>
				<?php echo bk_serit($k, 'bk-hizmet-serit', 'Diğer hizmetler'); ?>
			</div>
		</section>
	<?php endif;
else :
	bk_sayfa_bandi(get_the_title());
	?>
	<div class="kap bk-duz-sayfa">
		<div class="bk-icerik"><?php the_content(); ?></div>
	</div>
	<?php
endif;
get_footer();
