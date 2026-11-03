<?php
/**
 * Öne çıkan vakalar — ilk satır büyük fotoğraflı kart, sonraki iki satır yanında; kalan satırlar koyu sonuç kartları
 * (panelden düzenlenir). Her satır: görsel (stok klinik fotoğrafı), logo, ad, küçük not, rakamlar, metin, kaynak, bağlantı.
 */
defined('ABSPATH') || exit;
$mx_rows = mx_rows($d['liste'] ?? null);
$mx_kpis = static function (array $r): string {
    $out = '';
    foreach (mx_rows($r['liste'] ?? null) as $k) {
        $out .= '<div class="rf-kpi">'.mx_h($k['yazi'] ?? null).'</div>';
    }

    return $out;
};
$mx_logo = static function (array $r, string $class): string {
    $src = mx_img_src($r['logo'] ?? 0);

    return $src !== '' ? '<span class="'.$class.'"><img src="'.$src.'" alt="'.esc_attr((string) ($r['ad'] ?? '')).'" loading="lazy" decoding="async"/></span>' : '';
};
$mx_big = $mx_rows[0] ?? null;
$mx_side = array_slice($mx_rows, 1, 2);
$mx_more = array_slice($mx_rows, 3);
?>
<section class="mx rf-cases" id="vakalar"><div class="wrap">
<div class="rf-head"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? null) ?></span><h2><?= mx_h($d['baslik'] ?? null) ?></h2><?php if (! empty($d['metin'])): ?><p><?= mx_h($d['metin']) ?></p><?php endif; ?></div>
<?php if ($mx_big): ?><div class="rf-feat">
<a class="rf-big" href="<?= mx_url($mx_big['baglanti'] ?? '') ?>"><?php $bg = mx_img_src($mx_big['gorsel'] ?? 0); if ($bg !== ''): ?><img class="rf-big-bg" src="<?= $bg ?>" alt="" loading="lazy" decoding="async"/><?php endif; ?>
<span class="rf-big-in"><?= $mx_logo($mx_big, 'rf-chip') ?><small><?= mx_h($mx_big['kucuk_not'] ?? null) ?></small><b class="rf-big-t"><?= mx_h($mx_big['metin'] ?? null) ?></b><span class="rf-kpis"><?= $mx_kpis($mx_big) ?></span><em><?= mx_h($mx_big['vurgu'] ?? null) ?></em><span class="rf-go"><?= mx_h($mx_big['baglanti_yaz'] ?? 'Vakayı okuyun') ?> <i aria-hidden="true">→</i></span></span></a>
<div class="rf-side"><?php foreach ($mx_side as $r): ?><a class="rf-sm" href="<?= mx_url($r['baglanti'] ?? '') ?>"><?= $mx_logo($r, 'rf-logo') ?><small><?= mx_h($r['kucuk_not'] ?? null) ?></small><p><?= mx_h($r['metin'] ?? null) ?></p><span class="rf-kpis"><?= $mx_kpis($r) ?></span><em><?= mx_h($r['vurgu'] ?? null) ?></em><span class="rf-go"><?= mx_h($r['baglanti_yaz'] ?? 'Vakayı okuyun') ?> <i aria-hidden="true">→</i></span></a><?php endforeach; ?></div>
</div><?php endif; ?>
<?php if ($mx_more): ?><?php if (! empty($d['yazi_2'])): ?><h3 class="rf-sub"><?= mx_h($d['yazi_2']) ?></h3><?php endif; ?><div class="rf-more"><?php foreach ($mx_more as $r): ?><div class="rf-res"><?= $mx_logo($r, 'rf-chip') ?><span class="rf-kpis"><?= $mx_kpis($r) ?></span><em><?= mx_h($r['vurgu'] ?? null) ?></em></div><?php endforeach; ?></div><?php endif; ?>
</div></section>
