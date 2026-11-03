<?php
/**
 * Klinik türüne göre referanslar — süzgeçli kart ızgarası (panelden düzenlenir). Satır: logo, ad, tür, bağlantı (vaka varsa).
 * Süzgeç düğmeleri satırlardaki tür adlarından, ilk görüldükleri sırayla oluşur.
 */
defined('ABSPATH') || exit;
$mx_rows = mx_rows($d['liste'] ?? null);
$mx_types = [];
foreach ($mx_rows as $r) {
    $t = trim((string) ($r['tur'] ?? ''));
    if ($t !== '' && ! in_array($t, $mx_types, true)) {
        $mx_types[] = $t;
    }
}
?>
<section class="mx rf-list" id="klinikler"><div class="wrap">
<div class="rf-head"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? null) ?></span><h2><?= mx_h($d['baslik'] ?? null) ?></h2><?php if (! empty($d['metin'])): ?><p><?= mx_h($d['metin']) ?></p><?php endif; ?></div>
<div class="rf-chips" role="toolbar" aria-label="Klinik türü"><button type="button" class="on" data-t="">Tümü <span><?= count($mx_rows) ?></span></button><?php foreach ($mx_types as $t): $n = count(array_filter($mx_rows, static fn ($r) => trim((string) ($r['tur'] ?? '')) === $t)); ?><button type="button" data-t="<?= esc_attr($t) ?>"><?= esc_html($t) ?> <span><?= $n ?></span></button><?php endforeach; ?></div>
<div class="rf-grid"><?php foreach ($mx_rows as $r): $src = mx_img_src($r['logo'] ?? 0); $link = trim((string) ($r['baglanti'] ?? '')); $tag = $link !== '' ? 'a' : 'div'; ?>
<<?= $tag ?> class="rf-card<?= $link !== '' ? ' has-case' : '' ?>" data-t="<?= esc_attr(trim((string) ($r['tur'] ?? ''))) ?>"<?= $link !== '' ? ' href="'.mx_url($link).'"' : '' ?>><small><?= esc_html((string) ($r['tur'] ?? '')) ?></small><span class="rf-card-l"><?php if ($src !== ''): ?><img src="<?= $src ?>" alt="<?= esc_attr((string) ($r['ad'] ?? '')) ?>" loading="lazy" decoding="async"/><?php else: ?><b><?= esc_html((string) ($r['ad'] ?? '')) ?></b><?php endif; ?></span><span class="rf-card-n"><?= esc_html((string) ($r['ad'] ?? '')) ?><?php if ($link !== ''): ?><i>Vakayı okuyun →</i><?php endif; ?></span></<?= $tag ?>>
<?php endforeach; ?></div>
<?php if (! empty($d['kucuk_not'])): ?><p class="rf-note"><?= mx_h($d['kucuk_not']) ?></p><?php endif; ?>
</div></section>
<script>(function(){var s=document.currentScript.previousElementSibling;if(!s)return;var b=s.querySelectorAll(".rf-chips button"),c=s.querySelectorAll(".rf-card");[].forEach.call(b,function(x){x.addEventListener("click",function(){var t=x.getAttribute("data-t");[].forEach.call(b,function(y){y.classList.toggle("on",y===x);y.setAttribute("aria-pressed",y===x?"true":"false")});[].forEach.call(c,function(k){k.hidden=!!t&&k.getAttribute("data-t")!==t})})})})();</script>
