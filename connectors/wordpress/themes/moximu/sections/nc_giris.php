<?php
/** Çalışma düzeni girişi: solda başlık, maddeler ve butonlar; sağda "bu hafta" iş panosu; altta dört rakam (panelden düzenlenir). */
defined('ABSPATH') || exit;
$mx_dur = ['tamam' => 'Tamamlandı', 'suruyor' => 'Sürüyor', 'onay' => 'Onayınızda', 'plan' => 'Planlandı'];
?>
<section class="mx nc-hero"><div class="wrap">
<nav class="np-crumb" aria-label="breadcrumb"><a href="<?= esc_url(home_url('/')) ?>">Ana sayfa</a> › <span><?= esc_html(get_the_title()) ?></span></nav>
<div class="nc-g">
<div>
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['ust'] ?? '') ?></span>
<h1><?= mx_h($d['baslik'] ?? '') ?></h1>
<?php if (! empty($d['metin'])) : ?><p class="nc-lead"><?= mx_h($d['metin']) ?></p><?php endif; ?>
<?php if ($mx_l = mx_np_lines($d['etiketler'] ?? '')) : ?><ul class="ort-tk nc-tk"><?php foreach ($mx_l as $mx_t) : ?><li><?= mx_h($mx_t) ?></li><?php endforeach; ?></ul><?php endif; ?>
<div class="ort-cta"><?php if (! empty($d['buton_yazisi'])) : $mx_u = (string) ($d['buton_link'] ?? '#basvuru'); ?><a class="btn" href="<?= mx_url($mx_u) ?>"<?= mx_np_target($mx_u) ?>><?= mx_h($d['buton_yazisi']) ?> <i>→</i></a><?php endif; ?>
<a class="btn outline lp-wa-o" href="<?= esc_url(mx_wa_url((string) ($d['wa_metni'] ?? ''))) ?>" target="_blank" rel="noopener"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a></div>
</div>
<div class="nc-vis" aria-hidden="true">
<div class="nc-board">
<div class="nc-bh"><div><b><?= mx_h($d['pano_baslik'] ?? '') ?></b><small><?= mx_h($d['pano_alt'] ?? '') ?></small></div><em>Örnek</em></div>
<?php foreach (mx_np_lines($d['pano_satirlar'] ?? '') as $mx_t) : [$mx_g, $mx_i, $mx_s] = array_pad(array_map('trim', explode('|', $mx_t)), 3, ''); $mx_s = isset($mx_dur[$mx_s]) ? $mx_s : 'plan'; ?>
<div class="nc-row"><span class="nc-day"><?= mx_h($mx_g) ?></span><span class="nc-job"><?= mx_h($mx_i) ?></span><span class="nc-st nc-<?= $mx_s ?>"><?= $mx_dur[$mx_s] ?></span></div>
<?php endforeach; ?>
<?php if (! empty($d['pano_not'])) : ?><div class="nc-bf"><span></span><?= mx_h($d['pano_not']) ?></div><?php endif; ?>
</div>
<?php if (! empty($d['balon'])) : ?><div class="nc-bubble"><i><?= mx_wa_icon() ?></i><div><small>Moximu · Pazartesi 09:12</small><p><?= mx_h($d['balon']) ?></p></div></div><?php endif; ?>
</div>
</div>
<?php if ($mx_l = mx_np_lines($d['sayilar'] ?? '')) : ?><div class="nc-nums"><?php foreach ($mx_l as $mx_t) : [$mx_a, $mx_b] = array_pad(mx_np_pair($mx_t), 2, ''); ?><div><b><?= mx_h($mx_a) ?></b><span><?= mx_h($mx_b) ?></span></div><?php endforeach; ?></div><?php endif; ?>
</div></section>
