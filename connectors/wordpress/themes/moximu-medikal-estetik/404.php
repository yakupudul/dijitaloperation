<?php
if (! defined('ABSPATH')) {
	exit;
}
status_header(404);
get_header();
?>
<main id="icerik" class="sec"><div class="wrap">
<h1>Sayfa bulunamadı</h1>
<p class="lead">Aradığınız sayfa taşınmış ya da kaldırılmış olabilir.</p>
<p><a href="<?php echo esc_url(home_url('/')); ?>">Uygulamaların listesine dönün</a></p>
</div></main>
<?php
get_footer();
