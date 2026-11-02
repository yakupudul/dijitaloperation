<?php
/** Müşteri logoları — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<div class="mx lp-logos"><div class="wrap rv"><div class="cap"><?= mx_h(($d['yazi'] ?? null)) ?></div></div><div class="marq2"><div class="track" style="animation-duration: 150s;"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><?php if (!empty(($r1['logo'] ?? null))): ?><img src="<?= mx_img_src(($r1['logo'] ?? null)) ?>" alt="<?= mx_img_alt(($r1['ad'] ?? null)) ?>" loading="lazy" decoding="async"><?php else: ?><span><?= mx_h(($r1['ad'] ?? null)) ?></span><?php endif; ?><?php endforeach; ?></div></div></div>
