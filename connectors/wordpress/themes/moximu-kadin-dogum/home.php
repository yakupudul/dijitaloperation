<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
bk_sayfa_bandi('Blog İçerikleri');
get_template_part('parts/yazi-izgarasi');
get_footer();
