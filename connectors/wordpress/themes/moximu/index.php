<?php
defined('ABSPATH') || exit;
get_header();
if (is_singular()) {
    while (have_posts()) {
        the_post();
        if (! mx_sections()) {
            get_template_part('parts/plain');
        }
    }
} else {
    get_template_part('parts/archive');
}
get_footer();
