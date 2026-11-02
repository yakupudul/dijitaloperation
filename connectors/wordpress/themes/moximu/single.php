<?php
/**
 * A guide post (Rehber). The sections around it come from the private page "Rehber yazısı şablonu"; the breadcrumb,
 * the article head, the article itself and the related guides are built from the post.
 */
defined('ABSPATH') || exit;
get_header();
while (have_posts()) {
    the_post();
    $template = get_page_by_path('rehber-yazisi-sablonu', OBJECT, 'page');
    $rows = ($template && function_exists('get_field')) ? (array) (get_field('bolumler', $template->ID) ?: []) : [];
    if ($rows === []) {
        get_template_part('parts/plain');
        continue;
    }
    foreach ($rows as $index => $d) {
        $GLOBALS['mx_section_index'] = $index;
        $layout = sanitize_key((string) ($d['acf_fc_layout'] ?? ''));
        $own = MX_DIR.'/parts/rehber-'.$layout.'.php';
        $file = is_file($own) ? $own : MX_DIR.'/sections/'.$layout.'.php';
        if ($layout !== '' && is_file($file)) {
            include $file;
        }
    }
    $GLOBALS['mx_section_index'] = 99;
}
get_footer();
