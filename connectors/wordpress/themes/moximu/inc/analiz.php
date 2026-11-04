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
    mx_send_mail('Yeni ön değerlendirme talebi: '.$data['Klinik'], $body);
    wp_send_json_success(['message' => 'Teşekkürler. 24 saat içinde dönüyoruz.']);
}
add_action('wp_ajax_mx_analiz', 'mx_analiz_handle');
add_action('wp_ajax_nopriv_mx_analiz', 'mx_analiz_handle');
