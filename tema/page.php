<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
$mxp_page = mxp_current();
if ($mxp_page) {
	mxp_part($mxp_page['part'], ['{{CONTENT}}' => mxp_content()]);
} else {
	mxp_part('sade', ['{{TITLE}}' => esc_html(get_the_title()), '{{CONTENT}}' => mxp_content()]);
}
get_footer();
