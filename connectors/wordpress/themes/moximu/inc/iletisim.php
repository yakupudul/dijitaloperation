<?php

defined('ABSPATH') || exit;

/**
 * İletişim formu (İletişim, Referans programı, İş ortaklığı sayfaları). Like the analysis form it works on cached pages
 * (no nonce): a hidden field and a per-IP limit guard it, each message is kept as a private "Analiz talebi" record,
 * mailed to the address in Moximu ayarları and, when the site is linked, passed on to the sales inbox.
 */
function mx_iletisim_konular(): array
{
    return ['teklif' => 'Teklif ve fiyat', 'analiz' => 'Ücretsiz ön değerlendirme', 'referans' => 'Referans programı',
        'ortaklik' => 'İş ortaklığı', 'musteri' => 'Mevcut müşteriyim', 'diger' => 'Diğer'];
}

function mx_iletisim_phone(): string
{
    $phone = function_exists('get_field') ? trim((string) get_field('telefon', 'option')) : '';

    return $phone !== '' ? $phone : '+90 538 861 02 88';
}

function mx_iletisim_mail(): string
{
    $mail = function_exists('get_field') ? trim((string) get_field('talep_eposta', 'option')) : '';

    return $mail !== '' ? $mail : 'info@moximu.com';
}

/** The form itself; $konu preselects the subject, $firma asks "Firma" instead of "Klinik". */
function mx_iletisim_form(string $konu = 'teklif', string $buton = 'Mesajı gönderin', bool $firma = false): string
{
    static $n = 0;
    $n++;
    $id = 'mxf'.$n;
    ob_start(); ?>
<form class="mxf" id="<?= $id ?>" novalidate data-ajax="<?= esc_url(admin_url('admin-ajax.php')) ?>">
<div class="mxf-g">
<label class="mxf-f"><span>Ad soyad</span><input name="ad" autocomplete="name" required></label>
<label class="mxf-f"><span><?= $firma ? 'Firma' : 'Klinik' ?></span><input name="klinik" autocomplete="organization" placeholder="<?= $firma ? 'Firma adı' : 'Klinik adı (varsa)' ?>"></label>
<label class="mxf-f"><span>Telefon</span><input name="telefon" type="tel" inputmode="tel" autocomplete="tel" placeholder="05xx xxx xx xx"></label>
<label class="mxf-f"><span>E-posta</span><input name="email" type="email" autocomplete="email" placeholder="ornek@klinik.com"></label>
<label class="mxf-f mxf-w"><span>Konu</span><select name="konu"><?php foreach (mx_iletisim_konular() as $key => $label) : ?><option value="<?= esc_attr($key) ?>"<?= $key === $konu ? ' selected' : '' ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
<label class="mxf-f mxf-w"><span>Mesajınız</span><textarea name="mesaj" rows="4" placeholder="Kısaca yazın; şehir ve hizmet bilgisi işimizi hızlandırır."></textarea></label>
</div>
<input class="mxf-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
<label class="mxf-k"><input type="checkbox" name="kvkk" value="1"><span><a href="<?= esc_url(home_url('/kvkk-aydinlatma-metni/')) ?>" target="_blank">KVKK aydınlatma metni</a>ni okudum, anlıyorum.</span></label>
<div class="mxf-b"><button class="btn" type="submit"><?= esc_html($buton) ?> <i>→</i></button><small>Hafta içi aynı gün, hafta sonu ilk iş günü dönüyoruz.</small></div>
<p class="mxf-m" role="status" aria-live="polite"></p>
</form>
<script>(function(f){f.addEventListener('submit',function(e){e.preventDefault();var m=f.querySelector('.mxf-m'),b=f.querySelector('button'),v=function(k){var x=f.elements[k];return x?(x.type==='checkbox'?(x.checked?'1':''):x.value.trim()):''};
if(!v('ad')){m.textContent='Lütfen adınızı yazın.';m.className='mxf-m err';return}if(v('telefon').replace(/\D/g,'').length<10&&v('email').indexOf('@')<1){m.textContent='Telefon ya da e-posta yazın; size buradan dönelim.';m.className='mxf-m err';return}if(!v('kvkk')){m.textContent='Lütfen aydınlatma metnini onaylayın.';m.className='mxf-m err';return}
var d=new FormData;['ad','klinik','telefon','email','konu','mesaj','kvkk','website'].forEach(function(k){d.append(k,v(k))});d.append('action','mx_iletisim');d.append('sayfa',location.href);d.append('ref',document.referrer||'');b.disabled=true;m.textContent='Gönderiliyor…';m.className='mxf-m';
fetch(f.dataset.ajax,{method:'POST',body:d,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(j&&j.success){f.classList.add('ok');m.textContent=j.data.message;m.className='mxf-m ok';if(window.dataLayer)dataLayer.push({event:'mx_iletisim_form',konu:v('konu')})}else{m.textContent=(j&&j.data&&j.data.message)||'Gönderilemedi, WhatsApp\'tan yazabilirsiniz.';m.className='mxf-m err';b.disabled=false}}).catch(function(){m.textContent='Bağlantı sorunu. WhatsApp\'tan yazabilirsiniz.';m.className='mxf-m err';b.disabled=false})})})(document.getElementById('<?= $id ?>'));</script>
<?php
    return (string) ob_get_clean();
}

function mx_iletisim_handle(): void
{
    $field = static fn (string $k, int $max = 120): string => mb_substr(trim(sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')))), 0, $max);
    if ($field('website') !== '') {
        wp_send_json_success(['message' => 'Teşekkürler.']);
    }
    $ip = preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $key = 'mx_talep_'.md5($ip);
    $count = (int) get_transient($key);
    if ($count >= 5) {
        wp_send_json_error(['message' => 'Çok fazla deneme. Lütfen biraz sonra tekrar deneyin.'], 429);
    }
    $konular = mx_iletisim_konular();
    $konu = $konular[$field('konu', 20)] ?? 'Diğer';
    $message = mb_substr(trim(sanitize_textarea_field(wp_unslash((string) ($_POST['mesaj'] ?? '')))), 0, 3000);
    $data = ['Ad' => $field('ad'), 'Klinik' => $field('klinik'), 'Şehir' => '', 'E-posta' => sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))),
        'Telefon' => $field('telefon', 40), 'Konu' => $konu, 'Mesaj' => $message, 'Sayfa' => esc_url_raw(wp_unslash((string) ($_POST['sayfa'] ?? '')))];
    if ($data['Ad'] === '' || ($data['E-posta'] === '' && strlen(preg_replace('/\D/', '', $data['Telefon'])) < 10)) {
        wp_send_json_error(['message' => 'Ad ve telefon ya da e-posta gerekli.'], 422);
    }
    if (empty($_POST['kvkk'])) {
        wp_send_json_error(['message' => 'Lütfen aydınlatma metnini onaylayın.'], 422);
    }
    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    $body = '';
    foreach ($data as $label => $value) {
        if ($value !== '') {
            $body .= $label.': '.$value."\n";
        }
    }
    $who = $data['Klinik'] !== '' ? $data['Klinik'].' · '.$data['Ad'] : $data['Ad'];
    wp_insert_post(['post_type' => 'mx_talep', 'post_status' => 'private', 'post_title' => $konu.': '.$who, 'post_content' => $body]);
    if (function_exists('mx_lead_to_moxdop')) {
        $lead = $data;
        $lead['Klinik'] = $data['Klinik'] !== '' ? $data['Klinik'] : $data['Ad'];
        $lead['Şehir'] = $konu;
        mx_lead_to_moxdop($lead, esc_url_raw(wp_unslash((string) ($_POST['ref'] ?? ''))));
    }
    mx_send_mail('Yeni mesaj ('.$konu.'): '.$who, $body);
    wp_send_json_success(['message' => 'Teşekkürler, mesajınız bize ulaştı. Hafta içi aynı gün dönüyoruz.']);
}
add_action('wp_ajax_mx_iletisim', 'mx_iletisim_handle');
add_action('wp_ajax_nopriv_mx_iletisim', 'mx_iletisim_handle');
