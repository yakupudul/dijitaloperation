<?php
/** İletişim sayfası: kanallar (telefon, WhatsApp, e-posta, ofis), iletişim formu, harita ve diğer yollar. Telefon ve e-posta Moximu ayarlarından gelir. */
defined('ABSPATH') || exit;
$mx_tel = mx_iletisim_phone();
$mx_mail = mx_iletisim_mail();
$mx_adres = (string) ($d['adres'] ?? '') ?: '1. Anafartalar, Gazi Osman Paşa Cd. No:44, 45010 Şehzadeler/Manisa';
?>
<section class="mx ilt-hero" id="iletisim"><div class="wrap">
<nav class="np-crumb" aria-label="breadcrumb"><a href="<?= esc_url(home_url('/')) ?>">Ana sayfa</a> › <span><?= esc_html(get_the_title()) ?></span></nav>
<div class="ilt-g">
<div class="ilt-l">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['ust'] ?? 'İletişim') ?></span>
<h1><?= mx_h($d['baslik'] ?? '') ?></h1>
<?php if (! empty($d['metin'])) : ?><p class="ilt-lead"><?= mx_h($d['metin']) ?></p><?php endif; ?>
<div class="ilt-ch">
<a class="ilt-c" href="tel:<?= esc_attr(preg_replace('/[^0-9+]/', '', $mx_tel)) ?>"><i class="ic-tel"></i><span><small>Telefon</small><b><?= esc_html($mx_tel) ?></b><em>Hafta içi 09:00–18:00</em></span></a>
<a class="ilt-c ilt-wa" href="<?= esc_url(mx_wa_url('Merhaba, iletişim sayfanızdan yazıyorum.')) ?>" target="_blank" rel="noopener"><i class="ic-wa"><?= mx_wa_icon() ?></i><span><small>WhatsApp</small><b>Hemen yazın</b><em>Her saat; mesai içinde aynı saatte cevap</em></span></a>
<a class="ilt-c" href="mailto:<?= esc_attr(antispambot($mx_mail)) ?>"><i class="ic-mail"></i><span><small>E-posta</small><b><?= esc_html(antispambot($mx_mail)) ?></b><em>Teklif ve belge için</em></span></a>
<a class="ilt-c" href="<?= esc_url(home_url('/ucretsiz-klinik-analizi/')) ?>"><i class="ic-pin"></i><span><small>Klinik sahibiyseniz</small><b>Ücretsiz ön değerlendirme</b><em>Klinik adı ve şehir yeter; 24 saatte bulgular</em></span></a>
</div>
<ul class="ilt-p"><li><b>Aynı gün</b> dönüş, hafta içi</li><li><b>Doğrudan</b> işi yürüten kişiyle görüşürsünüz</li><li><b>4,9</b> Google puanı, 50 yorum</li></ul>
<p class="ilt-legal">Moximu · <?= esc_html($mx_adres) ?></p>
</div>
<div class="ilt-r"><div class="ilt-fc"><h2><?= mx_h($d['form_baslik'] ?? 'Bize yazın') ?></h2><p>Konuyu seçin, kısaca yazın. Ücretsiz ön değerlendirme için klinik adı yeter.</p>
<?= mx_iletisim_form('teklif') ?></div></div>
</div>
</div></section>
<section class="mx ilt-of"><div class="wrap">
<div class="ilt-yol">
<a href="<?= esc_url(home_url('/nasil-calisir/')) ?>"><small>Başlamadan önce</small><b>Nasıl çalışırız</b><span>İlk 90 gün adım adım; aylık rapor ve istekler.</span><i>→</i></a>
<a href="<?= esc_url(home_url('/referans-programi/')) ?>"><small>Tanıdığınız bir klinik varsa</small><b>Referans programı</b><span>Yönlendirdiğiniz klinik başlarsa 1 aylık hizmet bedeliniz bizden.</span><i>→</i></a>
<a href="<?= esc_url(home_url('/is-ortakligi/')) ?>"><small>Kliniklerle çalışan firmalar için</small><b>İş ortaklığı</b><span>Yönlendirdiğiniz kliniğin hizmet bedelinin %10'u, 12 ay boyunca.</span><i>→</i></a>
</div>
</div></section>
