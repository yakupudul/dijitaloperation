<?php
/** Program girişi (Referans programı, İş ortaklığı): solda başlık ve maddeler, sağda koyu ödül kartı (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx ort-hero"><div class="wrap">
<nav class="np-crumb" aria-label="breadcrumb"><a href="<?= esc_url(home_url('/')) ?>">Ana sayfa</a> › <span><?= esc_html(get_the_title()) ?></span></nav>
<div class="ort-g">
<div>
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['ust'] ?? '') ?></span>
<h1><?= mx_h($d['baslik'] ?? '') ?></h1>
<?php if (! empty($d['metin'])) : ?><p class="ort-lead"><?= mx_h($d['metin']) ?></p><?php endif; ?>
<?php if ($mx_l = mx_np_lines($d['etiketler'] ?? '')) : ?><ul class="ort-tk"><?php foreach ($mx_l as $mx_t) : ?><li><?= mx_h($mx_t) ?></li><?php endforeach; ?></ul><?php endif; ?>
<div class="ort-cta"><?php if (! empty($d['buton_yazisi'])) : $mx_u = (string) ($d['buton_link'] ?? '#basvuru'); ?><a class="btn" href="<?= mx_url($mx_u) ?>"<?= mx_np_target($mx_u) ?>><?= mx_h($d['buton_yazisi']) ?></a><?php endif; ?>
<a class="btn outline lp-wa-o" href="<?= esc_url(mx_wa_url((string) ($d['wa_metni'] ?? ''))) ?>" target="_blank" rel="noopener"><?= mx_wa_icon() ?>WhatsApp'tan sorun</a></div>
</div>
<aside class="ort-card"><small><?= mx_h($d['odul_ust'] ?? '') ?></small><b><?= mx_h($d['odul'] ?? '') ?></b><span><?= mx_h($d['odul_alt'] ?? '') ?></span>
<?php if ($mx_l = mx_np_lines($d['odul_satirlar'] ?? '')) : ?><ul><?php foreach ($mx_l as $mx_t) : [$mx_a, $mx_b] = array_pad(mx_np_pair($mx_t), 2, ''); ?><li><span><?= mx_h($mx_a) ?></span><em><?= mx_h($mx_b) ?></em></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (! empty($d['odul_not'])) : ?><p><?= mx_h($d['odul_not']) ?></p><?php endif; ?></aside>
</div>
</div></section>
