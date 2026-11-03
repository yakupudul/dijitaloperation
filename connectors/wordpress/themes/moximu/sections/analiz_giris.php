<?php
/**
 * Ücretsiz analiz girişi — solda başlık ve güvence maddeleri, sağda form kartı (panelden düzenlenir).
 * Form, sitedeki diğer analiz formlarıyla aynı alan adlarını kullanır; gönderimi site.js yapar.
 */
defined('ABSPATH') || exit;
?>
<section class="mx an-hero"><div class="wrap an-hero-g">
<div class="an-hero-t"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? null) ?></span>
<h1><?= mx_h($d['baslik'] ?? null) ?></h1><p class="an-lead"><?= mx_h($d['metin'] ?? null) ?></p>
<ul class="an-ticks"><?php foreach (mx_rows($d['liste'] ?? null) as $r): ?><li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg><span><?= mx_h($r['madde'] ?? null) ?></span></li><?php endforeach; ?></ul>
<div class="an-who"><img src="<?= MX_URI ?>/assets/i/mark.webp" alt="" width="40" height="40"/><span><?= mx_h($d['yazi_2'] ?? null) ?></span></div></div>
<div class="an-card" id="form"><div class="an-card-h"><b><?= mx_h($d['alt_baslik'] ?? null) ?></b><span><?= mx_h($d['yazi_3'] ?? null) ?></span></div>
<div class="an-f">
<label class="an-l"><span>Klinik adı</span><span class="cMwGjD"><input id="firstName" name="firstName" autocomplete="organization" placeholder="Örn. Gülüş Diş Kliniği"/></span></label>
<label class="an-l"><span>Şehir ve ilçe</span><span class="cMwGjD"><input id="lastName" name="lastName" autocomplete="address-level2" placeholder="Örn. İzmir, Bornova"/></span></label>
<label class="an-l"><span>Telefon</span><span class="mx-tel"><select id="phoneCode" name="phoneCode" aria-label="Ülke kodu"><option value="+90" selected>TR +90</option><option value="+49">DE +49</option><option value="+44">GB +44</option><option value="+31">NL +31</option><option value="+994">AZ +994</option><option value="+1">US +1</option></select><input id="phoneNumber" name="phoneNumber" type="tel" inputmode="tel" autocomplete="tel-national" placeholder="5xx xxx xx xx"/></span></label>
<label class="an-l"><span>E-posta <em>(isteğe bağlı)</em></span><span class="cMwGjD"><input id="email" name="email" type="email" autocomplete="email" placeholder="ornek@klinik.com"/></span></label>
<input id="mxhp" name="website" tabindex="-1" autocomplete="off" class="an-hp" aria-hidden="true"/>
<span class="iRWFG an-kvkk"><input id="termsAndConditions" name="termsAndConditions" type="checkbox"/><label for="termsAndConditions"><?= mx_h($d['etiket'] ?? null) ?></label></span>
<button class="btn navy an-btn" type="button"><?= mx_h($d['buton_yazisi'] ?? 'Analizi isteyin') ?> <i aria-hidden="true">→</i></button>
<p class="an-or"><?= mx_h($d['yazi_4'] ?? null) ?> <a href="<?= esc_url(mx_wa_url('Merhaba, kliniğim için ücretsiz analiz istiyorum.')) ?>" target="_blank" rel="noopener"><?= mx_wa_icon() ?>WhatsApp</a></p>
</div></div>
</div></section>
