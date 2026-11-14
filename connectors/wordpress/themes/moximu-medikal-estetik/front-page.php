<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
$mme_page = mme_current();
if ($mme_page) {
	mme_render($mme_page);
} else {
	get_template_part('parts/yedek');
}
get_footer();
