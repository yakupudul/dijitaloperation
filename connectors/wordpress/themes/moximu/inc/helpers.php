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
        $out .= 'srcset="'.esc_attr($srcset).'" sizes="(max-width: 768px) 80vw, 40vw" ';
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
        'turizm' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'kurum' => '<path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-5h6v5M12 8v4M10 10h4"/>',
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

/**
 * Small line icon for a service / menu card, picked from its link and title (no setting needed).
 * Used by the mega menu, the mobile menu and the [mx_alt_sayfalar] cards.
 */
function mx_svc_icon(string $url, string $title = ''): string
{
    $k = mb_strtolower($url.' '.$title);
    $paths = [
        'web-sitesi|web sitesi' => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18"/><circle cx="6.5" cy="6.5" r=".6"/><path d="M7 13h6M7 16h4"/>',
        'seo' => '<circle cx="10.5" cy="10.5" r="6"/><path d="m15 15 5 5"/><path d="m8 12 2-2 1.5 1.5L14 9"/>',
        'isletme-profili|işletme profili|harita|yakın' => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21z"/><circle cx="12" cy="10" r="2.4"/>',
        'google-ads|google ads' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1.2"/>',
        'instagram|facebook|meta' => '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="3.8"/><circle cx="17" cy="7" r=".7"/>',
        'saglik-turizmi|sağlık turizmi|ulke|ülke' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.6 3.6 5.4 3.6 8.5s-1 5.9-3.6 8.5c-2.6-2.6-3.6-5.4-3.6-8.5s1-5.9 3.6-8.5z"/>',
        'paket' => '<path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 13 9 5 9-5"/>',
        'dis-klinik|diş klinik|dis-hekim|diş hekim|implant' => '<path d="M7.5 3.5c-2.2 0-3.8 1.8-3.8 4.3 0 2.3 1 3.6 1.6 5.6.7 2.4.9 7.1 2.6 7.1 1.6 0 1.4-4.6 4.1-4.6s2.5 4.6 4.1 4.6c1.7 0 1.9-4.7 2.6-7.1.6-2 1.6-3.3 1.6-5.6 0-2.5-1.6-4.3-3.8-4.3-1.9 0-2.8 1.2-4.5 1.2S9.4 3.5 7.5 3.5z"/>',
        'dermatoloji' => '<path d="M12 3.5s6 6.6 6 10.8a6 6 0 0 1-12 0C6 10.1 12 3.5 12 3.5z"/><path d="M9.5 14.5a2.6 2.6 0 0 0 2.5 2.4"/>',
        'sac-ekimi|saç ekimi' => '<path d="M6 20c0-7 2-12 6-16M12 20c0-6 1-11 4-15M18 20c0-5-.5-9-2-12M4 20h16"/>',
        'fizyoterapi|fizik tedavi' => '<path d="M3 12h4l2.5-6 4 12 2.5-6H21"/>',
        'diyet' => '<path d="M12 7c-4.5-2-8 .7-8 5.2C4 17 7 20.5 9.3 20.5c1.2 0 1.6-.6 2.7-.6s1.5.6 2.7.6C17 20.5 20 17 20 12.2 20 7.7 16.5 5 12 7z"/><path d="M12 7c0-2 1-3.5 3-4"/>',
        'psikolog|psikiyatri' => '<path d="M20 11.5a7.5 7.5 0 0 1-11 6.6L4 19.5l1.4-4.6A7.5 7.5 0 1 1 20 11.5z"/><path d="M9 11.5h.01M12 11.5h.01M15 11.5h.01"/>',
        'sunnet|sünnet|cocuk|çocuk' => '<path d="M12 3.5 19 6v5.5c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'hastane|tip-merkez|tıp merkez' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/>',
        'muayenehane' => '<path d="M6 3.5v5a4 4 0 0 0 8 0v-5"/><path d="M10 12.5v2a5 5 0 0 0 10 0V13"/><circle cx="20" cy="11" r="2"/>',
        'estetik' => '<path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M18.5 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>',
        'sehir|şehir|izmir|ankara|istanbul|antalya|manisa|bursa' => '<path d="M4 21V9l5-3v15M9 21V4l7 4v13M16 21v-8l4 2v6M3 21h18"/>',
        'sonuc|sonuç|vaka' => '<path d="M4 19h16"/><path d="m5 15 4-4 3 3 6-7"/><path d="M14 7h4v4"/>',
        'hesaplayıcı|hesaplayici|kontrol|araç|arac' => '<rect x="5" y="3" width="14" height="18" rx="2.5"/><path d="M8 7h8M8 12h2M14 12h2M8 16h2M14 16h2"/>',
        'rehber|blog' => '<path d="M4 5h6a2 2 0 0 1 2 2v12a2 2 0 0 0-2-2H4zM20 5h-6a2 2 0 0 0-2 2v12a2 2 0 0 1 2-2h6z"/>',
    ];
    $svg = '<path d="M12 3.5 14 10l6.5 2-6.5 2-2 6.5-2-6.5L3.5 12 10 10z"/>';
    foreach ($paths as $keys => $p) {
        foreach (explode('|', $keys) as $needle) {
            if (str_contains($k, $needle)) {
                $svg = $p;
                break 2;
            }
        }
    }

    return '<span class="mx-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.$svg.'</svg></span>';
}

/** WhatsApp link with a ready first message: the site's first contact choice (then the form, then a call). */
function mx_wa_url(string $text = ''): string
{
    return 'https://wa.me/905388610288?text='.rawurlencode($text !== '' ? $text : 'Merhaba, kliniğim için bilgi almak istiyorum.');
}

function mx_wa_icon(): string
{
    return '<svg aria-hidden="true" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.3.8 3.2.7a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"/></svg>';
}
