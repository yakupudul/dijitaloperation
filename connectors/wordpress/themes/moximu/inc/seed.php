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
    $options['__genel'] = ['telefon' => '+90 538 861 02 88'];
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
            $id = mx_seed_post_id($page['path']);
            if (! $id && $page['key'] === 'rehber') {
                // The article template: a private page whose sections frame every guide post (single.php).
                $id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Rehber yazısı şablonu',
                    'post_name' => 'rehber-yazisi-sablonu', 'post_content' => 'Rehber yazılarının çevresindeki bölümler (yazının kendisi her yazıdan gelir).']);
            }
            if (! $id) {
                $state['log'][] = 'sayfa yok: '.$page['path'];
                $state['done'][$key] = 1;
                continue;
            }
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
                if (! empty($page['service']) && get_post_type($id) === 'page' && empty(get_field('hizmet', $id)['ad'] ?? '')) {
                    update_field('field_mx_h_grp', $page['service'], $id);
                }
                foreach (['_seopress_titles_title' => $page['seo_title'] ?? '', '_seopress_titles_desc' => $page['seo_desc'] ?? ''] as $meta => $value) {
                    if ($value !== '' && (string) get_post_meta($id, $meta, true) === '') {
                        update_post_meta($id, $meta, $value);
                    }
                }
                $state['log'][] = 'sayfa: '.$page['path'];
            } else {
                $state['log'][] = 'sayfa elle değişmiş, dokunulmadı: '.$page['path'];
            }
            $state['done'][$key] = 1;
        }
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
