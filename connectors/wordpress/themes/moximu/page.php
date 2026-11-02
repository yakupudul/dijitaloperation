<?php
defined('ABSPATH') || exit;
get_header();
while (have_posts()) {
    the_post();
    if (! mx_sections()) {
        get_template_part('parts/plain');
    }
}
get_footer();
