<?php
defined('ABSPATH') || exit;

/** Inline HTML from a field: relative links point at this site, only post-safe tags survive. */
function mx_h($value): string
{
    if (! is_string($value) && ! is_numeric($value)) {
        return '';
    }
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (strpos($value, '="/') !== false) {
        $home = esc_url(home_url('/'));
        $value = preg_replace('~\b(href|src)="/(?!/)~', '$1="'.$home, $value);
    }

    return strpbrk($value, '<&') === false ? esc_html($value) : wp_kses_post($value);
}

/** A link field: "/path/" is a page of this site, "#id" stays, anything else is escaped. */
function mx_url($value): string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return '#';
    }
    if ($value[0] === '#') {
        return esc_attr($value);
    }
    if ($value[0] === '/' && ($value[1] ?? '') !== '/') {
        return esc_url(home_url($value));
    }

    return esc_url($value);
}

function mx_rows($value): array
{
    return is_array($value) ? $value : [];
}

function mx_img_id($value): int
{
    if (is_array($value)) {
        return (int) ($value['ID'] ?? $value['id'] ?? 0);
    }

    return (int) $value;
}

function mx_img_src($value): string
{
    $id = mx_img_id($value);

    return $id ? esc_url((string) wp_get_attachment_image_url($id, 'full')) : '';
}

function mx_img_alt($value): string
{
    $id = mx_img_id($value);

    return $id ? esc_attr((string) get_post_meta($id, '_wp_attachment_image_alt', true)) : '';
}

/** width/height (unless the template fixes them) and a srcset so phones download a small copy. */
function mx_img_wh($value, bool $dimensions = true): string
{
    $id = mx_img_id($value);
    if (! $id) {
        return '';
    }
    $out = '';
    $meta = wp_get_attachment_metadata($id);
    if ($dimensions && ! empty($meta['width']) && ! empty($meta['height'])) {
        $out .= 'width="'.(int) $meta['width'].'" height="'.(int) $meta['height'].'" ';
    }
    $srcset = wp_get_attachment_image_srcset($id, 'full');
    if ($srcset) {
        $out .= 'srcset="'.esc_attr($srcset).'" sizes="(max-width: 768px) 100vw, 50vw" ';
    }

    return $out;
}

/** Images of the first two sections load at once (they are what the visitor sees first); the rest lazily. */
function mx_loading(): string
{
    return ($GLOBALS['mx_section_index'] ?? 99) <= 1 ? 'eager' : 'lazy';
}

/** A group of Moximu ayarları (header, footer). */
function mx_opt(string $name): array
{
    static $cache = [];
    if (! array_key_exists($name, $cache)) {
        $cache[$name] = function_exists('get_field') ? (array) (get_field($name, 'option') ?: []) : [];
    }

    return $cache[$name];
}

/** Prints the page's sections; a page without sections shows its title and content. */
function mx_sections(?int $post_id = null): bool
{
    $rows = function_exists('get_field') ? get_field('bolumler', $post_id ?: false) : null;
    if (! is_array($rows) || $rows === []) {
        return false;
    }
    $GLOBALS['mx_drawn'] = true;
    foreach ($rows as $index => $d) {
        $GLOBALS['mx_section_index'] = $index;
        $layout = sanitize_key((string) ($d['acf_fc_layout'] ?? ''));
        $file = MX_DIR.'/sections/'.$layout.'.php';
        if ($layout !== '' && is_file($file)) {
            include $file;
        }
    }
    $GLOBALS['mx_section_index'] = 99;

    return true;
}

/** Client logos for the rays animation (Moximu ayarları › Müşteri logoları) as the script's JSON. */
function mx_ray_logos(): string
{
    $out = [];
    foreach (mx_rows(function_exists('get_field') ? get_field('musteri_logolari', 'option') : []) as $row) {
        $src = mx_img_src($row['logo'] ?? 0);
        $out[] = $src !== '' ? ['img', $src, (string) ($row['ad'] ?? '')] : ['txt', '', (string) ($row['ad'] ?? '')];
    }

    return (string) wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Line icon for a mega menu group, chosen by the group's link (falls back to a dot). */
function mx_menu_icon(string $url): string
{
    $paths = [
        'hizmet' => '<path d="m12 3 9 4.5-9 4.5-9-4.5z"/><path d="m3 12 9 4.5 9-4.5"/><path d="m3 16.5 9 4.5 9-4.5"/>',
        'klinik' => '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/>',
        'sehir' => '<path d="M12 21s7-6 7-11.5a7 7 0 1 0-14 0C5 15 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'sonuc' => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'arac' => '<rect x="5" y="2.5" width="14" height="19" rx="2.5"/><path d="M8.5 6.5h7M8.5 11h.01M12 11h.01M15.5 11h.01M8.5 14.5h.01M12 14.5h.01M15.5 14.5h.01M8.5 18h7"/>',
        'rehber' => '<path d="M3 5h6a3 3 0 0 1 3 3v12a2.5 2.5 0 0 0-2.5-2.5H3z"/><path d="M21 5h-6a3 3 0 0 0-3 3v12a2.5 2.5 0 0 1 2.5-2.5H21z"/>',
    ];
    $inner = '<circle cx="12" cy="12" r="3"/>';
    foreach ($paths as $key => $svg) {
        if (str_contains($url, $key)) {
            $inner = $svg;
            break;
        }
    }

    return '<svg class="mx-ic" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'.$inner.'</svg>';
}
