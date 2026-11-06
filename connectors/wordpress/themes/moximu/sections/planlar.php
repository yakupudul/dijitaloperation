<?php
/** Planlar — fiyatlar Moximu ayarları › Fiyatlar'dan gelir; bölümde yalnız başlık ve öne çıkan hizmet seçilir. */
defined('ABSPATH') || exit;
$p = mx_fiyatlar();
$k = mx_kampanya();
$odak = (string) ($d['odak'] ?? 'paket');
$id = sanitize_key((string) ($d['kimlik'] ?? '')) ?: 'fiyat';
$wa = 'https://wa.me/905388610288?text='.rawurlencode('Merhaba, fiyatlar sayfasından yazıyorum. Kliniğim için plan hakkında bilgi almak istiyorum.');
$cta = mx_url('/ucretsiz-klinik-analizi/');
$plans = [
    ['seo', 'Klinik SEO', 'Google aramalarında tedavi ve ilçe sayfalarıyla görünürlük.', $p['seo'], 0,
        ['Teknik düzeltmeler', 'Tedavi × ilçe sayfaları', 'Haftalık içerik, hekim onaylı', 'Aylık tek sayfa rapor']],
    ['paket', 'SEO + İşletme Profili', 'Hem aramada hem haritada. Çoğu klinik için doğru başlangıç.', $p['paket'], $p['paket_liste'],
        ['Klinik SEO\'nun tamamı', 'Google İşletme Profili yönetimi', 'Haftalık gönderi ve yorum cevapları', 'Harita sıralaması takibi']],
    ['yerel', 'Yerel klinik paketi', 'Dört kanal birlikte: arama, harita, Google ve Instagram reklamı.', $p['yerel'], $p['yerel_liste'],
        ['SEO + İşletme Profili', 'Google Ads yönetimi', 'Instagram ve Facebook reklamı', 'Form, arama, WhatsApp ölçümü']],
];
$extras = [
    ['harita', '/hizmetlerimiz/google-isletme-profili/', 'Google İşletme Profili', 'Tek başına, şube başına', mx_tl($p['harita']), '/ay', ''],
    ['ads', '/hizmetlerimiz/google-ads/', 'Google Ads yönetimi', 'Reklam bütçesi ayrıdır', mx_tl($p['ads']), '/ay', 'SEO ile '.mx_tl($p['ads_paket'])],
    ['meta', '/hizmetlerimiz/instagram-facebook-reklamlari/', 'Instagram ve Facebook reklamı', 'Reklam bütçesi ayrıdır', mx_tl($p['meta']), '/ay', 'SEO ile '.mx_tl($p['meta_paket'])],
    ['dil', '/hizmetlerimiz/klinik-seo/', 'Ek dilde SEO', 'Her dil için ayrı sayfalar', '+'.mx_tl($p['seo_dil']), '/ay', ''],
    ['turizm', '/fiyatlar/saglik-turizmi-paketi/', 'Sağlık turizmi ve çok şube', 'Ülke ve şube sayısına göre', 'Görüşmeye bağlı', '', ''],
];
?>
<section class="mx px<?= $k ? ' six' : '' ?>" id="<?= esc_attr($id) ?>"><div class="wrap">
<div class="px-head">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? 'Planlar') ?></span>
<h2><?= mx_h($d['baslik'] ?? '') ?></h2>
<?php if (! empty($d['metin'])): ?><p class="px-lead"><?= mx_h($d['metin']) ?></p><?php endif; ?>
<div class="px-sw" role="group" aria-label="Plan süresi"><button type="button"<?= $k ? '' : ' class="on"' ?> data-px="1" aria-pressed="<?= $k ? 'false' : 'true' ?>">Aylık</button><button type="button"<?= $k ? ' class="on"' : '' ?> data-px="6" aria-pressed="<?= $k ? 'true' : 'false' ?>">6 aylık plan<?php if ($k): ?><em>Web sitesi hediye</em><?php endif; ?></button></div>
</div>
<?php if ($k): $kwa = mx_wa_url('Merhaba, '.($k['etiket'] ?? 'kampanya').' kapsamında 6 aylık planla başlamak istiyorum. Kliniğim: '); ?>
<div class="px-kmp" data-end="<?= (int) $k['ts'] ?>"><div class="px-kmp-t"><b><span class="px-live"></span><?= mx_h($k['etiket'] ?? '') ?> · <?= esc_html(mx_kampanya_tarih()) ?> 23:59'da bitiyor</b><span>6 aylık planla başlayan kliniğe <mark><?= mx_tl($p['web']) ?> değerindeki web sitesi</mark> hediye.</span></div><div class="px-cd" aria-label="Kampanyanın bitmesine kalan süre"><span><b data-u="d">00</b>gün</span><span><b data-u="h">00</b>saat</span><span><b data-u="m">00</b>dk</span><span><b data-u="s">00</b>sn</span></div><a class="px-kmp-go" href="<?= esc_url($kwa) ?>" target="_blank" rel="noopener">Yerimi ayırın <i>→</i></a></div>
<?php endif; ?>
<div class="px-cards">
<?php foreach ($plans as [$key, $name, $desc, $price, $list, $items]): $main = $key === 'paket'; if ($k && ! empty($p[$key.'_sonra'])) { $list = (int) $p[$key.'_sonra']; } $save = max(0, $list - $price); $total = $p['web'] + $save * 6; $pct = $list > 0 ? (int) round($save * 100 / $list) : 0; ?>
<div class="px-card<?= $main ? ' main' : '' ?><?= $odak === $key ? ' odak' : '' ?>">
<?php if ($main): ?><span class="px-tag">Önerilen</span><?php endif; ?>
<h3><?= mx_h($name) ?></h3><p class="px-desc"><?= mx_h($desc) ?></p>
<div class="px-price"><?php if ($save): ?><s><?= mx_tl($list) ?></s><?php if ($k): ?><em class="px-off">%<?= $pct ?> indirim</em><?php endif; ?><i class="px-br"></i><?php endif; ?><b><?= mx_tl($price) ?></b><span>/ay + KDV</span></div>
<?php if ($save && $k): ?><div class="px-save"><span class="px-pill">Her ay <?= mx_tl($save) ?> daha az</span><small class="px-lock"><?= esc_html(mx_kampanya_tarih()) ?>'dan sonra yeni kliniklere <?= mx_tl($list) ?>. Kampanyada başlayanlar bu fiyattan devam eder.</small></div><?php elseif ($save): ?><div class="px-save"><span class="px-pill">Ayrı ayrı <?= mx_tl($list) ?>; her ay <?= mx_tl($save) ?> tasarruf</span></div><?php else: ?><div class="px-save"><span class="px-pill px-pill-g">Tek kanal, aylık</span></div><?php endif; ?>
<?php if ($k): ?><div class="px-gift is-kmp"><span class="px-gift-h"><?= mx_h($k['etiket'] ?? 'Kampanya') ?>na özel</span>
<span class="px-gift-r"><i></i><span>6 aylık planda <b>klinik web sitesi hediye</b> <s><?= mx_tl($p['web']) ?></s></span></span>
<span class="px-gift-t">6 ayda toplam avantajınız <b><?= mx_tl($total) ?></b></span></div><?php endif; ?>
<ul><?php foreach ($items as $it): ?><li><?= mx_h($it) ?></li><?php endforeach; ?></ul>
<a class="btn<?= $main ? '' : ' navy' ?>" href="<?= $k ? esc_url(mx_wa_url('Merhaba, '.$name.' planıyla başlamak istiyorum ('.($k['etiket'] ?? '').'). Kliniğim: ')) : $cta ?>"<?= $k ? ' target="_blank" rel="noopener"' : '' ?>><?= $k ? 'Bu planla başlayın' : 'Teklif alın' ?></a>
<small class="px-no">Taahhüt zorunlu değil, aylık da başlayabilirsiniz.</small>
</div>
<?php endforeach; ?>
</div>
<div class="px-web<?= $odak === 'web' ? ' odak' : '' ?>">
<div class="px-web-t"><span class="px-chip">Klinik web sitesi</span><h3>Tedavi sayfaları kurulu, <mark>hızlı açılan</mark> klinik sitesi.</h3><p>Hekim ve tedavi sayfaları, randevu formu, WhatsApp ve ölçüm kurulumu dahil. Her dil için sayfalar ayrı hazırlanır.</p>
<div class="px-web-l"><span>Tek dil <b><?= mx_tl($p['web']) ?></b></span><span>Ek dil <b>+<?= mx_tl($p['web_dil']) ?></b></span><span>Tek sefer, KDV hariç</span></div></div>
<div class="px-web-p"><div class="px-web-s"><?php if ($k): ?><s><?= mx_tl($p['web']) ?></s><b>Hediye</b><span><?= esc_html(mx_kampanya_tarih()) ?>'a kadar 6 aylık planla başlayan kliniklere</span><?php else: ?><b><?= mx_tl($p['web']) ?></b><span>Tek sefer, KDV hariç</span><?php endif; ?></div><a class="rf-go" href="<?= mx_url('/hizmetlerimiz/klinik-web-sitesi/') ?>">Siteyi inceleyin <i>→</i></a></div>
</div>
<div class="px-ex" id="tek-hizmet"><h3 class="px-ex-h">Tek hizmet ve ekler</h3>
<?php foreach ($extras as [$key, $url, $name, $note, $price, $per, $deal]): ?>
<a class="px-row<?= $odak === $key ? ' odak' : '' ?>" href="<?= mx_url($url) ?>"><b><?= mx_h($name) ?></b><span><?= mx_h($note) ?></span><?php if ($deal): ?><em class="px-deal"><?= mx_h($deal) ?></em><?php else: ?><em></em><?php endif; ?><strong><?= mx_h($price) ?><small><?= mx_h($per) ?></small></strong><i>→</i></a>
<?php endforeach; ?>
</div>
<p class="px-foot">Fiyatlara KDV dahil değildir. Reklam bütçesi ücrete dahil değildir; klinik tarafından doğrudan Google ve Meta'ya ödenir. 6 aylık plan, dönem başında tek faturayla başlar. <a href="<?= esc_url($wa) ?>" target="_blank" rel="noopener">WhatsApp'tan sorun</a></p>
</div>
<script>(function(s){var q=function(x){return s.querySelectorAll(x)};q('.px-sw button').forEach(function(b){b.addEventListener('click',function(){q('.px-sw button').forEach(function(o){o.classList.toggle('on',o===b);o.setAttribute('aria-pressed',o===b)});s.classList.toggle('six',b.dataset.px==='6')})});var k=s.querySelector('.px-kmp');if(!k)return;var end=+k.dataset.end*1000,u=function(n){return(n<10?'0':'')+n};function t(){var r=Math.max(0,end-Date.now());if(!r){k.remove();return}var sec=Math.floor(r/1e3);k.querySelector('[data-u=d]').textContent=u(Math.floor(sec/86400));k.querySelector('[data-u=h]').textContent=u(Math.floor(sec%86400/3600));k.querySelector('[data-u=m]').textContent=u(Math.floor(sec%3600/60));k.querySelector('[data-u=s]').textContent=u(sec%60);setTimeout(t,1000)}t()})(document.currentScript.parentNode);</script>
</section>
