<?php
if (! defined('ABSPATH')) {
	exit;
}
status_header(404);
get_header();
?>
<main><section class="pg-hero slim"><div class="wrap">
<h1>Sayfa bulunamadı</h1>
<p class="lead">Aradığınız sayfa taşınmış ya da kaldırılmış olabilir.</p>
<div class="actions"><a class="btn btn-navy" href="<?php echo esc_url(home_url('/')); ?>">Ana sayfaya dönün</a></div>
</div></section></main>
<?php
get_footer();
