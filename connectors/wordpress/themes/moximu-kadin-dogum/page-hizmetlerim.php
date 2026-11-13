<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
bk_sayfa_bandi('HİZMETLER', '2026/09/IMG_5329-scaled.jpg', '', true);
?>
<section class="bk-bolum bk-tum-hizmetler">
	<div class="kap">
		<div class="bk-kart-izgara">
			<?php foreach (bk_hizmet_basliklari() as $p) {
				echo bk_hizmet_karti($p->ID);
			} ?>
		</div>
	</div>
</section>
<?php
get_footer();
