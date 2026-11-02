<?php
/**
 * Google yorumları — bölüm şablonu. Yorum kartları yalnızca gerçek Google yorumlarından gelir: MoxDOP'un siteye
 * gönderdiği yorumlar (moxdop_showcase_reviews, "Sitede yorum ve soru göster" açıkken) ya da panelde elle girilmiş,
 * yer tutucu olmayan satırlar. Gerçek yorum yoksa kart şeridi hiç basılmaz; puan kutusu ve Google bağlantısı kalır.
 */
defined('ABSPATH') || exit;

$mx_cards = [];
$mx_feed = get_option('moxdop_showcase_reviews', []);
if (get_option('moxdop_showcase_enabled') && is_array($mx_feed) && ! empty($mx_feed['items'])) {
    foreach ((array) $mx_feed['items'] as $it) {
        $text = trim((string) ($it['text'] ?? ''));
        $stars = (int) ($it['stars'] ?? 0);
        if ($text === '' || $stars < 4) {
            continue;
        }
        $name = (string) ($it['name'] ?? '');
        $time = strtotime((string) ($it['date'] ?? ''));
        $mx_cards[] = [
            'av' => mb_strtoupper(mb_substr($name !== '' ? $name : 'G', 0, 1)),
            'name' => $name !== '' ? $name : 'Google kullanıcısı',
            'stars' => $stars,
            'text' => $text,
            'date' => 'Google'.($time ? ' · '.wp_date('F Y', $time) : ''),
        ];
        if (count($mx_cards) >= 12) {
            break;
        }
    }
    if (! empty($mx_feed['count'])) {
        $d['yazi_2'] = number_format_i18n((float) $mx_feed['rating'], 1).'<span>/5</span>';
        $d['yazi_4'] = (int) $mx_feed['count'].' Google yorumu';
        if (! empty($mx_feed['url'])) {
            $d['baglanti'] = (string) $mx_feed['url'];
        }
    }
}
if ($mx_cards === []) {
    foreach (mx_rows($d['liste'] ?? null) as $r1) {
        $text = trim(wp_strip_all_tags((string) ($r1['metin'] ?? '')));
        if ($text === '' || stripos($text, 'yer tutucu') !== false) {
            continue;
        }
        $mx_cards[] = ['av' => (string) ($r1['yazi'] ?? ''), 'html' => (string) ($r1['yazi_2'] ?? ''), 'stars' => 5, 'text' => $text, 'date' => (string) ($r1['kucuk_not'] ?? '')];
    }
}
$mx_star = static fn (int $n): string => str_repeat('★', max(0, min(5, $n)));
?>
<section class="mxrev<?= $mx_cards === [] ? ' mxrev-only' : '' ?>"><div class="mxrev-wrap"><div class="mxrev-head"><div><span class="mxrev-eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/> <?= mx_h(($d['yazi'] ?? null)) ?> </span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p><?= mx_h(($d['metin'] ?? null)) ?></p></div><div class="mxrev-score"><div class="mxrev-num"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="mxrev-stars"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="mxrev-cnt"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><a href="<?= mx_url(($d['baglanti'] ?? null)) ?>" rel="noopener" target="_blank"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div></div>
<?php if ($mx_cards !== []): ?><div class="mxrev-row"><div class="mxrev-track">
<?php foreach ($mx_cards as $c): ?><article class="mxrev-card"><div class="mxrev-top"><span class="mxrev-av"><?= esc_html($c['av']) ?></span><div><?= isset($c['html']) ? mx_h($c['html']) : '<b>'.esc_html($c['name']).'</b><small>Google yorumu</small>' ?></div><span class="mxrev-stars sm"><?= $mx_star((int) $c['stars']) ?></span></div><p class="mxrev-q"><?= esc_html($c['text']) ?></p><small class="mxrev-date"><?= esc_html($c['date']) ?></small></article><?php endforeach; ?>
</div></div><?php endif; ?>
</div></section>
