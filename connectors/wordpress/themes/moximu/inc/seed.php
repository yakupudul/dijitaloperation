<?php
defined('ABSPATH') || exit;

/**
 * First content: the theme carries the approved prototype content (seed/*.json, seed/img). On a new seed revision it
 * fills header/footer settings and each page's sections, but never over something a person changed since the last
 * seed (each seeded value is remembered by its hash). Images go to the media library once. Work is split across
 * requests (about 15 s each) so a slow host never times out.
 */
add_action('init', 'mx_seed_run', 99);

function mx_seed_rev(): string
{
    return trim((string) @file_get_contents(MX_DIR.'/seed/rev.txt'));
}

function mx_seed_run(): void
{
    if (! function_exists('update_field') || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    $rev = mx_seed_rev();
    if ($rev === '' || get_option('mx_seed_rev') === $rev || get_transient('mx_seed_lock')) {
        return;
    }
    set_transient('mx_seed_lock', 1, 60);
    $started = microtime(true);
    $state = get_option('mx_seed_state');
    if (! is_array($state) || ($state['rev'] ?? '') !== $rev) {
        $state = ['rev' => $rev, 'done' => [], 'log' => []];
    }
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/image.php';

    $finished = true;
    $options = json_decode((string) file_get_contents(MX_DIR.'/seed/options.json'), true) ?: [];
    $options['__genel'] = ['telefon' => '+90 538 861 02 88', 'talep_eposta' => 'info@moximu.com'];
    foreach ($options as $name => $values) {
        if (! empty($state['done']['opt:'.$name])) {
            continue;
        }
        if (microtime(true) - $started > 15) {
            $finished = false;
            break;
        }
        if ($name === '__genel') {
            if ((string) get_field('telefon', 'option') === '') {
                update_field('field_mx_g_telefon', $values['telefon'], 'option');
            }
            if ((string) get_field('talep_eposta', 'option') === '') {
                update_field('field_mx_g_eposta', $values['talep_eposta'], 'option');
            }
        } else {
            $hash_key = 'mx_seed_hash_opt_'.$name;
            $current = mx_seed_hash(get_field($name, 'option', false));
            $stored = (string) get_option($hash_key);
            if ($stored === $current || mx_seed_empty(get_field($name, 'option', false))) {
                update_field($name, mx_seed_media($values), 'option');
                update_option($hash_key, mx_seed_hash(get_field($name, 'option', false)), false);
                $state['log'][] = 'ayar: '.$name;
            } else {
                $state['log'][] = 'ayar elle değişmiş, dokunulmadı: '.$name;
            }
        }
        $state['done']['opt:'.$name] = 1;
    }
    if ($finished) {
        foreach (json_decode((string) file_get_contents(MX_DIR.'/seed/pages.json'), true) ?: [] as $page) {
            $key = 'page:'.$page['key'];
            if (! empty($state['done'][$key]) || empty($page['path'])) {
                continue;
            }
            if (microtime(true) - $started > 15) {
                $finished = false;
                break;
            }
            $id = mx_seed_find($page);
            if (! $id && $page['key'] === 'rehber') {
                // The article template: a private page whose sections frame every guide post (single.php).
                $id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Rehber yazısı şablonu',
                    'post_name' => 'rehber-yazisi-sablonu', 'post_content' => 'Rehber yazılarının çevresindeki bölümler (yazının kendisi her yazıdan gelir).']);
            }
            if (! $id && ! empty($page['title'])) {
                $id = mx_seed_create($page);
                if ($id) {
                    $state['log'][] = 'oluşturuldu: '.$page['path'];
                }
            }
            if (! $id) {
                $state['log'][] = 'sayfa yok: '.$page['path'];
                $state['done'][$key] = 1;
                continue;
            }
            mx_seed_text($id, $page);
            if (! empty($page['rows'])) {
                $current = get_field('bolumler', $id, false);
                $stored = (string) get_post_meta($id, '_mx_seed_hash', true);
                // Never seeded by this theme: anything in the field came from an earlier build, not a person working
                // with this theme. Keep a copy, then seed.
                if ($stored === '' && ! mx_seed_empty($current) && get_post_meta($id, '_mx_seed_backup', true) === '') {
                    update_post_meta($id, '_mx_seed_backup', wp_slash(wp_json_encode($current)));
                }
                if ($stored === '' || $stored === mx_seed_hash($current) || mx_seed_empty($current)) {
                    update_field('field_mx_bolumler', mx_seed_media($page['rows']), $id);
                    update_post_meta($id, '_mx_seed_hash', mx_seed_hash(get_field('bolumler', $id, false)));
                    $state['log'][] = 'sayfa: '.$page['path'];
                } else {
                    $state['log'][] = 'sayfa elle değişmiş, dokunulmadı: '.$page['path'];
                }
            }
            if (! empty($page['service']) && empty(get_field('hizmet', $id)['ad'] ?? '')) {
                update_field('field_mx_h_grp', $page['service'], $id);
            }
            foreach (['_seopress_titles_title' => $page['seo_title'] ?? '', '_seopress_titles_desc' => $page['seo_desc'] ?? '',
                '_mx_seo_title' => $page['seo_title'] ?? '', '_mx_seo_desc' => $page['seo_desc'] ?? ''] as $meta => $value) {
                if ($value !== '' && (string) get_post_meta($id, $meta, true) === '') {
                    update_post_meta($id, $meta, $value);
                }
            }
            if (! empty($page['noindex']) && get_post_meta($id, '_mx_noindex', true) === '') {
                update_post_meta($id, '_mx_noindex', '1');
                update_post_meta($id, '_seopress_robots_index', 'yes');
            }
            foreach ((array) ($page['meta'] ?? []) as $meta => $value) {
                if (get_post_meta($id, $meta, true) === '') {
                    update_post_meta($id, $meta, $value);
                }
            }
            if (! empty($page['thumb']) && ! has_post_thumbnail($id)) {
                $media = mx_seed_image((string) $page['thumb'], (string) ($page['thumb_alt'] ?? ''));
                if ($media) {
                    set_post_thumbnail($id, $media);
                }
            }
            $state['done'][$key] = 1;
        }
    }
    if ($finished && empty($state['done']['site'])) {
        mx_seed_site();
        $state['done']['site'] = 1;
    }
    $state['log'] = array_slice($state['log'], -60);
    update_option('mx_seed_state', $state, false);
    if ($finished) {
        update_option('mx_seed_rev', $rev, false);
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache(true);
        }
        do_action('litespeed_purge_all');
    }
    delete_transient('mx_seed_lock');
}

function mx_seed_hash($value): string
{
    return md5((string) wp_json_encode($value));
}

function mx_seed_empty($value): bool
{
    if (is_array($value)) {
        foreach ($value as $v) {
            if (! mx_seed_empty($v)) {
                return false;
            }
        }

        return true;
    }

    return $value === null || $value === '' || $value === false;
}

/** The record a seed entry describes: a page by path, a guide post or a client by slug. */
function mx_seed_find(array $page): int
{
    $type = (string) ($page['type'] ?? 'page');
    if ($type === 'page') {
        return mx_seed_post_id((string) $page['path']);
    }
    $slug = basename(trim((string) $page['path'], '/'));
    $found = get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids']);

    return $found ? (int) $found[0] : 0;
}

/** Creates a missing page (under its parent path), guide post or client from its seed entry. */
function mx_seed_create(array $page): int
{
    $type = (string) ($page['type'] ?? 'page');
    $path = trim((string) $page['path'], '/');
    $parent = 0;
    if ($type === 'page' && str_contains($path, '/')) {
        $parent = mx_seed_post_id('/'.dirname($path).'/');
        if (! $parent) {
            return 0;
        }
    }
    $slug = $path === '' ? 'ana-sayfa' : basename($path);
    $cats = [];
    foreach ((array) ($page['cats'] ?? []) as $cat) {
        $term = get_category_by_slug((string) $cat);
        if ($term) {
            $cats[] = (int) $term->term_id;
        }
    }
    $args = ['post_type' => $type, 'post_status' => (string) ($page['status'] ?? 'publish'), 'post_title' => (string) $page['title'],
        'post_name' => $slug, 'post_parent' => $parent, 'menu_order' => (int) ($page['order'] ?? 0), 'post_content' => '', 'post_excerpt' => '',
        'comment_status' => 'closed', 'ping_status' => 'closed'];
    if (! empty($page['date'])) {
        $args['post_date'] = (string) $page['date'];
    }
    if ($cats) {
        $args['post_category'] = $cats;
    }
    $id = wp_insert_post(wp_slash($args), true);

    return is_wp_error($id) ? 0 : (int) $id;
}

/** Title, excerpt and body from the seed, kept up to date until a person edits them (hash check). */
function mx_seed_text(int $id, array $page): void
{
    if (! isset($page['content']) && ! isset($page['excerpt'])) {
        return;
    }
    $post = get_post($id);
    if (! $post) {
        return;
    }
    $now = md5($post->post_title."\n".$post->post_excerpt."\n".$post->post_content);
    $stored = (string) get_post_meta($id, '_mx_seed_chash', true);
    $fresh = $post->post_content === '' && $post->post_excerpt === '';
    if (! $fresh && $stored !== $now) {
        return;
    }
    $update = ['ID' => $id];
    if (isset($page['content'])) {
        $update['post_content'] = (string) $page['content'];
    }
    if (isset($page['excerpt'])) {
        $update['post_excerpt'] = (string) $page['excerpt'];
    }
    if (! empty($page['title'])) {
        $update['post_title'] = (string) $page['title'];
    }
    kses_remove_filters();
    wp_update_post(wp_slash($update));
    kses_init_filters();
    $post = get_post($id);
    update_post_meta($id, '_mx_seed_chash', md5($post->post_title."\n".$post->post_excerpt."\n".$post->post_content));
}

/**
 * First settings of a fresh site (home page still showing posts): front and guide pages, permalinks, guide topics,
 * site name. A site that already has a front page keeps its settings.
 */
function mx_seed_site(): void
{
    $site = json_decode((string) @file_get_contents(MX_DIR.'/seed/site.json'), true);
    if (! is_array($site) || get_option('mx_site_setup')) {
        return;
    }
    foreach ((array) ($site['kategoriler'] ?? []) as $cat) {
        if (! get_category_by_slug((string) $cat['slug'])) {
            wp_insert_term((string) $cat['ad'], 'category', ['slug' => (string) $cat['slug'], 'description' => (string) ($cat['aciklama'] ?? '')]);
        }
    }
    $fresh = get_option('show_on_front') === 'posts' && ! (int) get_option('page_on_front');
    if ($fresh) {
        $front = mx_seed_post_id_by_slug('ana-sayfa');
        $blog = mx_seed_post_id('/rehber/');
        if ($front) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $front);
        }
        if ($blog) {
            update_option('page_for_posts', $blog);
        }
        foreach ((array) ($site['ayarlar'] ?? []) as $name => $value) {
            update_option((string) $name, $value);
        }
        if (! empty($site['kalici_baglanti'])) {
            global $wp_rewrite;
            $wp_rewrite->set_permalink_structure((string) $site['kalici_baglanti']);
            $wp_rewrite->set_category_base((string) ($site['kategori_tabani'] ?? ''));
        }
        $uncategorized = get_category((int) get_option('default_category'));
        if ($uncategorized && $uncategorized->slug === 'uncategorized' && ! empty($site['varsayilan_kategori'])) {
            wp_update_term($uncategorized->term_id, 'category', ['name' => $site['varsayilan_kategori']['ad'], 'slug' => $site['varsayilan_kategori']['slug']]);
        }
        foreach (['hello-world' => 'post', 'sample-page' => 'page'] as $slug => $type) {
            $sample = get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1]);
            if ($sample && $sample[0]->post_modified === $sample[0]->post_date) {
                wp_trash_post($sample[0]->ID);
            }
        }
        // Posts created before the topics existed get theirs now.
        foreach (json_decode((string) file_get_contents(MX_DIR.'/seed/pages.json'), true) ?: [] as $page) {
            if (($page['type'] ?? 'page') === 'post' && ! empty($page['cats'])) {
                $id = mx_seed_find($page);
                $ids = array_filter(array_map(static fn ($s) => (int) (get_category_by_slug((string) $s)->term_id ?? 0), (array) $page['cats']));
                if ($id && $ids) {
                    wp_set_post_categories($id, $ids);
                }
            }
        }
    }
    update_option('mx_site_setup', 1);
    // Taxonomies registered this request still carry the old category base: rebuild the rules on the next request.
    update_option('mx_flush_rewrite', 1);
}

add_action('init', static function (): void {
    if (get_option('mx_flush_rewrite')) {
        delete_option('mx_flush_rewrite');
        flush_rewrite_rules(false);
    }
}, 100);

function mx_seed_post_id_by_slug(string $slug): int
{
    $page = get_page_by_path($slug);

    return $page ? (int) $page->ID : 0;
}

function mx_seed_post_id(string $path): int
{
    if ($path === '/') {
        return (int) get_option('page_on_front');
    }
    $id = url_to_postid(home_url($path));
    if (! $id) {
        $page = get_page_by_path(trim($path, '/'));
        $id = $page ? $page->ID : 0;
    }

    return (int) $id;
}

/** Replaces {"seed": file, "alt": text} with an attachment id, uploading the theme's copy once. */
function mx_seed_media($value)
{
    if (! is_array($value)) {
        return $value;
    }
    if (isset($value['seed']) && is_string($value['seed']) && count($value) <= 2) {
        return mx_seed_image($value['seed'], (string) ($value['alt'] ?? ''));
    }
    foreach ($value as $k => $v) {
        $value[$k] = mx_seed_media($v);
    }

    return $value;
}

function mx_seed_image(string $file, string $alt): int
{
    $file = basename($file);
    $map = get_option('mx_seed_media');
    $map = is_array($map) ? $map : [];
    if (! empty($map[$file]) && get_post($map[$file])) {
        return (int) $map[$file];
    }
    $source = MX_DIR.'/seed/img/'.$file;
    if (! is_file($source)) {
        return 0;
    }
    $tmp = wp_tempnam($file);
    copy($source, $tmp);
    $svg = static fn (array $m): array => $m + ['svg' => 'image/svg+xml'];
    $check = static function ($data, $f, $name) {
        if (str_ends_with(strtolower((string) $name), '.svg')) {
            return ['ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false];
        }

        return $data;
    };
    add_filter('upload_mimes', $svg);
    add_filter('wp_check_filetype_and_ext', $check, 10, 3);
    $id = media_handle_sideload(['name' => $file, 'tmp_name' => $tmp], 0, pathinfo($file, PATHINFO_FILENAME));
    remove_filter('upload_mimes', $svg);
    remove_filter('wp_check_filetype_and_ext', $check, 10);
    if (is_wp_error($id)) {
        @unlink($tmp);

        return 0;
    }
    if ($alt !== '') {
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
    }
    update_post_meta($id, '_mx_seed_file', $file);
    $map[$file] = (int) $id;
    update_option('mx_seed_media', $map, false);

    return (int) $id;
}
