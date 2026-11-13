<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
bk_sayfa_bandi(wp_strip_all_tags(get_the_archive_title()));
$aciklama = get_the_archive_description();
if ($aciklama) {
	echo '<div class="kap bk-arsiv-aciklama">' . wp_kses_post($aciklama) . '</div>';
}
get_template_part('parts/yazi-izgarasi');
get_footer();
