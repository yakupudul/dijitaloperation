<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
?>
<section class="bk-404">
	<div class="kap">
		<p class="bk-404-sayi">404</p>
		<h1>Sayfa bulunamadı!</h1>
		<p>Aradığınız sayfa artık mevcut değil. Belki site ana sayfasına dönerek devam edebilirsiniz.</p>
		<a class="bk-btn altin buyuk" href="<?php echo esc_url(bk_url('/')); ?>">Ana Sayfa</a>
	</div>
</section>
<?php
get_footer();
