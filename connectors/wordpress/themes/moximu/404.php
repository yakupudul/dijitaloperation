<?php
defined('ABSPATH') || exit;
get_header(); ?>
<section class="mx lp-hero"><div class="wrap"><h1>Aradığınız sayfa bulunamadı</h1><p class="lead">Adres değişmiş olabilir. <a href="<?= esc_url(home_url('/')) ?>">Ana sayfaya dönün</a>.</p></div></section>
<?php get_footer();
