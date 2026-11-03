<?php
defined('ABSPATH') || exit;

/*
 * Prices live in one place: Moximu ayarları › Fiyatlar. Text anywhere on the site writes {fiyat:seo} and gets
 * "12.000 ₺"; package prices that are sums (SEO + İşletme Profili, the four-channel package) are worked out here,
 * so a crossed-out "ayrı ayrı" price is always the real sum of the single prices.
 */
function mx_fiyatlar(): array
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $defaults = ['seo' => 12000, 'harita' => 6000, 'paket' => 14000, 'ads' => 9000, 'ads_paket' => 6000,
        'meta' => 9000, 'meta_paket' => 6000, 'web' => 30000, 'web_dil' => 8000, 'seo_dil' => 4000];
    $p = $defaults;
    foreach (mx_opt('fiyatlar') as $key => $value) {
        if (isset($defaults[$key]) && is_numeric($value) && (int) $value > 0) {
            $p[$key] = (int) $value;
        }
    }
    $p['paket_liste'] = $p['seo'] + $p['harita'];
    $p['yerel'] = $p['paket'] + $p['ads_paket'] + $p['meta_paket'];
    $p['yerel_liste'] = $p['seo'] + $p['harita'] + $p['ads'] + $p['meta'];

    return $p;
}

function mx_tl(int $amount): string
{
    return number_format($amount, 0, ',', '.').' ₺';
}

/** The running campaign (Moximu ayarları › Kampanya), or null when it is off or its end time has passed. */
function mx_kampanya(): ?array
{
    static $k = false;
    if ($k !== false) {
        return $k;
    }
    $d = mx_opt('kampanya');
    $end = (string) ($d['bitis'] ?? '');
    $k = null;
    if (! empty($d['aktif']) && $end !== '') {
        try {
            $ts = (new DateTimeImmutable($end, wp_timezone()))->getTimestamp();
        } catch (Exception $e) {
            $ts = 0;
        }
        if ($ts > time()) {
            $k = $d + ['ts' => $ts];
        }
    }

    return $k;
}

/** "30 Kasım" for the campaign end, Turkish month names whatever the site language. */
function mx_kampanya_tarih(): string
{
    $k = mx_kampanya();
    if (! $k) {
        return '';
    }
    $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $dt = (new DateTimeImmutable('@'.$k['ts']))->setTimezone(wp_timezone());

    return $dt->format('j').' '.$months[(int) $dt->format('n') - 1];
}

/** {fiyat:seo} → "12.000 ₺"; {kampanya:tarih} → "30 Kasım". Unknown keys stay as they are. */
function mx_fiyat_tokens(string $text): string
{
    if (! str_contains($text, '{fiyat:') && ! str_contains($text, '{kampanya:')) {
        return $text;
    }
    $p = mx_fiyatlar();

    return (string) preg_replace_callback('~\{(fiyat|kampanya):([a-z_]+)\}~', static function (array $m) use ($p): string {
        if ($m[1] === 'kampanya') {
            return $m[2] === 'tarih' ? mx_kampanya_tarih() : $m[0];
        }

        return isset($p[$m[2]]) ? mx_tl((int) $p[$m[2]]) : $m[0];
    }, $text);
}

add_filter('the_content', 'mx_fiyat_tokens', 9);
add_filter('the_excerpt', 'mx_fiyat_tokens', 9);
