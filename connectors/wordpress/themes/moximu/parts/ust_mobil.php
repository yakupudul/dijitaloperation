<?php
/**
 * Mobil menü: the same groups and links as the desktop mega menu (Görünüm › Moximu ayarları › Üst menü), then the
 * top links, a contact card (WhatsApp any time, phone on weekdays) and one call to action.
 */
defined('ABSPATH') || exit;
$d = mx_opt('ust_mobil');
$m = mx_opt('ust_menu');
$tabs = mx_rows(($m['liste_2'] ?? null));
$groups = array_map(static fn ($tab) => mb_strtolower(trim(wp_strip_all_tags((string) ($tab['metin'] ?? '')))), $tabs);
$def = mx_rows(($m['liste_3'] ?? null));
$phone = trim(wp_strip_all_tags((string) ($m['yazi_2'] ?? ''))) ?: '0538 861 02 88';
$tel = '+90'.ltrim(preg_replace('/\D/', '', $phone), '0');
$wa = 'https://wa.me/'.ltrim($tel, '+');
$cta_url = mx_url(($m['baglanti_2'] ?? null)) ?: home_url('/ucretsiz-klinik-analizi/');
$cta = trim(wp_strip_all_tags((string) ($m['yazi_3'] ?? ''))) ?: 'Ücretsiz klinik analizi';
?>
<div aria-hidden="true" aria-label="Mobil menü" aria-modal="true" class="sc-18e319-0 kauKoP" id="mobile-navigation-menu" inert="" role="dialog"><nav aria-label="Mobile navigation" class="sc-18e319-1 gUqVKX"><a aria-label="Moximu ana sayfa" href="<?= esc_url(home_url('/')) ?>"><span class="mxlogo"><img loading="eager" alt="" class="mxlogo-m" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><img loading="eager" alt="Moximu" class="mxlogo-w" src="<?= MX_URI ?>/assets/i/wm-logo.png"/></span></a><button aria-label="Close mobile menu" class="sc-18e319-3 bhVIpe" type="button"><img alt="" aria-hidden="true" decoding="async" height="24" loading="eager" src="<?= MX_URI ?>/assets/i/ee636d803a.svg" width="24"/></button></nav>
<div class="mx-mm">
<?php foreach ($tabs as $t => $tab) :
    $items = mx_rows(($tab['alt'] ?? null)) ?: ($t === 0 ? $def : []);
    $name = trim(wp_strip_all_tags((string) ($tab['metin'] ?? '')));
    if ($name === '') {
        continue;
    } ?>
<details class="mx-mm-g"><summary><?= esc_html($name) ?><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></summary><ul>
<?php foreach ($items as $it) : $label = trim(wp_strip_all_tags((string) ($it['metin'] ?? ''))); if ($label === '') { continue; } ?>
<li><a href="<?= mx_url(($it['baglanti'] ?? null)) ?>"><?= mx_svc_icon((string) ($it['baglanti'] ?? ''), $label) ?><?= esc_html($label) ?></a></li>
<?php endforeach; ?>
<?php if (! empty($tab['baglanti'])) : ?><li class="mx-mm-all"><a href="<?= mx_url($tab['baglanti']) ?>">Tümünü gör →</a></li><?php endif; ?>
</ul></details>
<?php endforeach; ?>
<div class="mx-mm-links">
<?php foreach (mx_rows(($m['liste'] ?? null)) as $row) : if (! preg_match('~href="([^"]*)"[^>]*>(.*?)</a>~s', (string) ($row['madde'] ?? ''), $hit) || in_array(mb_strtolower(trim(wp_strip_all_tags($hit[2]))), $groups, true)) { continue; } ?>
<a href="<?= mx_url($hit[1]) ?>"><?= esc_html(wp_strip_all_tags($hit[2])) ?></a>
<?php endforeach; ?>
<a href="<?= esc_url(home_url('/iletisim/')) ?>">İletişim</a>
</div>
<div class="mx-mm-contact"><p class="mx-mm-k">Bize ulaşın</p>
<a class="mx-mm-c mx-mm-wa" href="<?= esc_url($wa) ?>" rel="noopener" target="_blank"><svg aria-hidden="true" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.3.8 3.2.7a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"/></svg><span><b>WhatsApp'tan yazın</b><small>Dilediğiniz saatte yazabilirsiniz</small></span></a>
<a class="mx-mm-c" href="tel:<?= esc_attr($tel) ?>"><svg aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg><span><b><?= esc_html($phone) ?></b><small>Hafta içi 09:00–18:00 arayabilirsiniz</small></span></a>
<a class="mx-mm-mail" href="mailto:info@moximu.com">info@moximu.com</a>
</div>
</div>
<div class="mx-mm-foot"><a class="mx-mm-cta" href="<?= esc_url($cta_url) ?>"><?= esc_html($cta) ?><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a></div>
</div>
