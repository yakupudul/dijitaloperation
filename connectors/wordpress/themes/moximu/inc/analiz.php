<?php

defined('ABSPATH') || exit;

/**
 * "Ücretsiz analiz" form: works on cached pages (no nonce), guarded by a hidden field and a per-IP limit. Each request
 * is kept as a private "Analiz talebi" record and mailed to the address in Moximu ayarları.
 */
add_action('init', static function (): void {
    register_post_type('mx_talep', [
        'label' => 'Analiz talepleri', 'public' => false, 'show_ui' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-email-alt',
        'supports' => ['title', 'editor'], 'capability_type' => 'post', 'map_meta_cap' => true, 'menu_position' => 26,
        'capabilities' => ['create_posts' => 'do_not_allow'],
    ]);
});

/**
 * Sends a form mail without ever breaking the form: a host without mail() (or a failing SMTP plugin) throws an Error
 * that wp_mail does not catch. The request is already stored as an "Analiz talebi"; the last mail problem is kept in
 * the option mx_mail_last_error so it can be read from the panel.
 */
function mx_send_mail(string $subject, string $body): bool
{
    $to = function_exists('get_field') ? (string) get_field('talep_eposta', 'option') : '';
    $to = $to !== '' ? $to : (string) get_option('admin_email');
    $failed = static function ($error): void {
        update_option('mx_mail_last_error', gmdate('c').' '.(is_wp_error($error) ? $error->get_error_message() : (string) $error), false);
    };
    add_action('wp_mail_failed', $failed);
    try {
        $sent = wp_mail($to, $subject, $body);
    } catch (Throwable $error) {
        $failed(get_class($error).': '.$error->getMessage());
        $sent = false;
    }
    remove_action('wp_mail_failed', $failed);

    return (bool) $sent;
}

function mx_analiz_handle(): void
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
    $data = ['Klinik' => $field('klinik'), 'Şehir' => $field('sehir'), 'E-posta' => sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))),
        'Telefon' => $field('telefon', 40), 'Sayfa' => esc_url_raw(wp_unslash((string) ($_POST['sayfa'] ?? '')))];
    if ($data['Klinik'] === '' || ($data['E-posta'] === '' && strlen(preg_replace('/\D/', '', $data['Telefon'])) < 10)) {
        wp_send_json_error(['message' => 'Klinik adı ve e-posta ya da telefon gerekli.'], 422);
    }
    if (empty($_POST['kvkk'])) {
        wp_send_json_error(['message' => 'Lütfen aydınlatma metnini onaylayın.'], 422);
    }
    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    $body = '';
    foreach ($data as $label => $value) {
        $body .= $label.': '.$value."\n";
    }
    wp_insert_post(['post_type' => 'mx_talep', 'post_status' => 'private', 'post_title' => $data['Klinik'].' · '.$data['Şehir'], 'post_content' => $body]);
    mx_lead_to_moxdop($data, esc_url_raw(wp_unslash((string) ($_POST['ref'] ?? ''))));
    mx_send_mail('Yeni ön değerlendirme talebi: '.$data['Klinik'], $body);
    wp_send_json_success(['message' => 'Teşekkürler. 24 saat içinde dönüyoruz.']);
}

/**
 * 0.6.2: the request also goes to MoxDOP (Satış), server to server, with the endpoint and token MoxDOP wrote into the
 * option mx_lead_form ("Site formu › Bağla"). The token never reaches the browser. A failure never breaks the form: the
 * request is stored and mailed anyway; the last problem is kept in mx_lead_form_last_error.
 */
function mx_lead_to_moxdop(array $data, string $referrer = ''): void
{
    $link = get_option('mx_lead_form');
    if (! is_array($link) || empty($link['endpoint']) || empty($link['token'])) {
        return;
    }
    $page = (string) $data['Sayfa'];
    $query = [];
    parse_str((string) wp_parse_url($page, PHP_URL_QUERY), $query);
    $pick = static fn (string $k): string => isset($query[$k]) && is_string($query[$k]) ? mb_substr(sanitize_text_field($query[$k]), 0, 200) : '';
    $body = [
        'form' => 'analiz', 'clinic' => $data['Klinik'], 'city' => $data['Şehir'], 'email' => $data['E-posta'], 'phone' => $data['Telefon'],
        'page' => $page, 'referrer' => $referrer, 'service' => (string) get_the_title((int) url_to_postid($page)),
        'utm_source' => $pick('utm_source'), 'utm_medium' => $pick('utm_medium'), 'utm_campaign' => $pick('utm_campaign'), 'utm_term' => $pick('utm_term'),
        'gclid' => $pick('gclid'), 'fbclid' => $pick('fbclid'),
    ];
    try {
        $answer = wp_remote_post((string) $link['endpoint'], [
            'timeout' => 6, 'headers' => ['X-Moxdop-Token' => (string) $link['token'], 'Accept' => 'application/json'], 'body' => $body,
        ]);
        $code = is_wp_error($answer) ? 0 : (int) wp_remote_retrieve_response_code($answer);
        if ($code < 200 || $code >= 300) {
            update_option('mx_lead_form_last_error', gmdate('c').' '.(is_wp_error($answer) ? $answer->get_error_message() : 'HTTP '.$code), false);
        }
    } catch (Throwable $error) {
        update_option('mx_lead_form_last_error', gmdate('c').' '.get_class($error).': '.$error->getMessage(), false);
    }
}

add_action('wp_ajax_mx_analiz', 'mx_analiz_handle');
add_action('wp_ajax_nopriv_mx_analiz', 'mx_analiz_handle');
