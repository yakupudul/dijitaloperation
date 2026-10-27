<?php

defined('ABSPATH') || exit;

final class MoxDOP_Connector_Admin
{
    private $secrets;

    public function __construct(MoxDOP_Connector_Secrets $secrets)
    {
        $this->secrets = $secrets;
    }

    public function register()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_moxdop_connector_pair', [$this, 'pair']);
        add_action('admin_post_moxdop_connector_disconnect', [$this, 'disconnect']);
        add_action('admin_post_moxdop_connector_management', [$this, 'save_management']);
        add_action('admin_post_moxdop_connector_redirect_remove', [$this, 'remove_redirect']);
    }

    public function menu()
    {
        MoxDOP_Connector_Admin_UI::add('moxdop-connector', 'Genel', [$this, 'render'], 0);
        MoxDOP_Connector_Admin_UI::add('moxdop-redirects', 'Yönlendirmeler', [$this, 'render_redirects'], 50);
    }

    /**
     * 1.12.0: every 301 MoxDOP writes is kept by the connector and listed here (Araçlar › MoxDOP yönlendirmeleri).
     * The old page is a draft; removing a row here stops that redirect at once.
     */
    public function render_redirects()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $redirects = MoxDOP_Connector_Fixes::own_redirects();
        ksort($redirects);
        $removed = isset($_GET['moxdop_notice']) && sanitize_key(wp_unslash($_GET['moxdop_notice'])) === 'redirect_removed';
        MoxDOP_Connector_Admin_UI::open('moxdop-redirects', 'Yönlendirmeler', 'MoxDOP\'ta onaylanan bütün 301 yönlendirmeleri burada tutulur; SEO eklentisine yazılmaz. Eski sayfa taslağa alınır, ziyaretçi ve Google yeni sayfaya gider. Bir satırı kaldırırsan o yönlendirme hemen durur.');
        if ($removed) {
            echo MoxDOP_Connector_Admin_UI::notice('Yönlendirme kaldırıldı.');
        }
        ?>
            <div class="mxa-tablewrap">
            <table class="mxa-table">
                <thead><tr><th>Eski adres</th><th>301 → Yeni adres</th><th></th></tr></thead>
                <tbody>
                <?php if ($redirects === []) { ?>
                    <tr><td colspan="3">Henüz yönlendirme yok.</td></tr>
                <?php } ?>
                <?php foreach ($redirects as $from => $to) { ?>
                    <tr>
                        <td><a href="<?php echo esc_url(home_url($from)); ?>" target="_blank" rel="noopener"><?php echo esc_html($from); ?></a></td>
                        <td><a href="<?php echo esc_url($to); ?>" target="_blank" rel="noopener"><?php echo esc_html($to); ?></a></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Bu yönlendirme kaldırılsın mı?');">
                                <input type="hidden" name="action" value="moxdop_connector_redirect_remove">
                                <input type="hidden" name="from" value="<?php echo esc_attr($from); ?>">
                                <?php wp_nonce_field('moxdop_connector_redirect_remove'); ?>
                                <?php submit_button('Kaldır', 'secondary', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            </div>
        <?php
        MoxDOP_Connector_Admin_UI::close();
    }

    public function remove_redirect()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('moxdop_connector_redirect_remove');
        MoxDOP_Connector_Fixes::remove_own_redirect(isset($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : '');
        wp_safe_redirect(add_query_arg('moxdop_notice', 'redirect_removed', MoxDOP_Connector_Admin_UI::url('moxdop-redirects')));
        exit;
    }

    public function render()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $paired = is_array($this->secrets->read());
        $delivery = (new MoxDOP_Connector_Events)->status();
        $notice = isset($_GET['moxdop_notice']) ? sanitize_key(wp_unslash($_GET['moxdop_notice'])) : '';
        $app = (string) get_option('moxdop_connector_app_url');
        MoxDOP_Connector_Admin_UI::open('moxdop-connector', $paired ? 'Site MoxDOP\'a <mark>bağlı</mark>' : 'Siteyi MoxDOP\'a <mark>bağla</mark>',
            'Bu eklenti sitenin sayfalarını, SEO alanlarını ve sağlığını MoxDOP\'a bildirir. Şifre, yorum, form mesajı ya da dosya göndermez. Aşağıdaki izinler sen açmadıkça kapalıdır; açtığın işler de MoxDOP\'ta onaylanmadan yapılmaz.');
        $messages = ['paired' => ['Site MoxDOP\'a bağlandı.', 'ok'], 'disconnected' => ['Bağlantı kesildi, yerel anahtar silindi.', 'ok'], 'saved' => ['Kaydedildi.', 'ok'],
            'failed' => ['Bağlanamadı. MoxDOP adresini (https) ve tek kullanımlık kodu kontrol edip yeniden dene.', 'error']];
        if (isset($messages[$notice])) {
            echo MoxDOP_Connector_Admin_UI::notice($messages[$notice][0], $messages[$notice][1]);
        }
        $pending = (string) ($delivery['pending'] ?? '—');
        $last = (string) ($delivery['last_ack_at'] ?? '');
        $error = (string) ($delivery['last_error'] ?? '');
        echo '<div class="mxa-grid">';
        echo '<div class="mxa-soft'.($paired ? ' is-accent' : '').'"><small>Bağlantı</small><strong>'.($paired ? 'Bağlı' : 'Bağlı değil').'</strong><small>'.esc_html($app !== '' ? (string) wp_parse_url($app, PHP_URL_HOST) : ($paired ? 'MoxDOP' : 'Aşağıdan bağla')).'</small></div>';
        echo '<div class="mxa-soft"><small>Gönderilmeyi bekleyen</small><strong>'.esc_html($pending).'</strong><small>Değişiklikler kaydedilince hemen gider</small></div>';
        echo '<div class="mxa-soft"><small>Son aktarım</small><strong style="font-size:18px">'.esc_html($last !== '' ? wp_date('j M H:i', strtotime($last)) : 'Henüz yok').'</strong><small>'.esc_html($error !== '' ? 'Son hata: '.$error : 'Hata yok').'</small></div>';
        echo '</div>';

        echo '<div class="mxa-card"><h2>'.($paired ? 'Bağlantıyı yenile' : 'Bağla').'</h2><p>MoxDOP\'ta bu web sitesi için tek kullanımlık bağlantı kodu al ve buraya yaz. Yenilerken eski bağlantı, yenisi kurulana kadar çalışmaya devam eder.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="moxdop_connector_pair">';
        wp_nonce_field('moxdop_connector_pair');
        echo '<div class="mxa-row"><label for="moxdop_app_url">MoxDOP adresi</label><div><input class="mxa-wide" id="moxdop_app_url" name="moxdop_app_url" type="url" required placeholder="https://app.moximu.com" value="'.esc_attr($app).'"></div></div>';
        echo '<div class="mxa-row"><label for="moxdop_pairing_code">Tek kullanımlık kod</label><div><input class="mxa-wide" id="moxdop_pairing_code" name="moxdop_pairing_code" type="text" required autocomplete="off" pattern="MXD-[0-9]+-[A-Za-z2-9]{24}" placeholder="MXD-…"></div></div>';
        echo '<div class="mxa-actions"><button type="submit" class="button button-primary">'.($paired ? 'Bağlantıyı yenile' : 'Bağla').'</button></div></form></div>';

        echo '<div class="mxa-card"><h2>MoxDOP\'un yapabilecekleri</h2><p>Açtığın her iş MoxDOP\'ta bir yönetici onaylamadan yapılmaz ve geri alınabilir.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="moxdop_connector_management">';
        wp_nonce_field('moxdop_connector_management');
        echo '<div class="mxa-row"><label for="moxdop_login_user">Tek tıkla giriş</label><div><select id="moxdop_login_user" name="moxdop_login_user"><option value="0">Kapalı</option>';
        foreach (get_users(['role__in' => ['administrator', 'editor'], 'fields' => ['ID', 'user_login']]) as $user) {
            echo '<option value="'.(int) $user->ID.'"'.selected(MoxDOP_Connector_Management::login_user_id(), (int) $user->ID, false).'>'.esc_html($user->user_login).'</option>';
        }
        echo '</select><small>MoxDOP bu kullanıcıyla tek kullanımlık, 60 saniyelik bir giriş bağlantısı açabilir. Ajans için ayrı bir hesap kullan.</small></div></div>';
        echo '<div class="mxa-row"><span class="mxa-label">İzinler</span><div>';
        echo MoxDOP_Connector_Admin_UI::toggle('moxdop_allow_fixes', MoxDOP_Connector_Fixes::fixes_allowed(), 'SEO düzeltmeleri', 'SEO başlığı ve açıklaması, görsel alt metni, schema, 301 yönlendirme, noindex / canonical ve iç linkler.');
        echo MoxDOP_Connector_Admin_UI::toggle('moxdop_allow_content', MoxDOP_Connector_Fixes::content_allowed(), 'İçerik güncelleme', 'Sayfanın yeni sürümü önce taslak kopya olarak kaydedilir; canlı sayfa ancak ikinci onayla değişir, eski sürüm revizyonda kalır.');
        echo MoxDOP_Connector_Admin_UI::toggle('moxdop_allow_schedule', MoxDOP_Connector_Drafts::scheduling_allowed(), 'Zamanlanmış taslak', 'Tarihi seçilip onaylanan MoxDOP yazısı zamanlanmış olarak kaydedilir; kapalıyken her yazı taslak kalır.');
        echo MoxDOP_Connector_Admin_UI::toggle('moxdop_allow_updates', MoxDOP_Connector_Management::updates_allowed(), 'Onaylı güncellemeler', 'WordPress\'in sunduğu eklenti, tema ve çekirdek güncellemeleri, her biri MoxDOP\'ta onaylandıktan sonra tek tek kurulur.');
        echo MoxDOP_Connector_Admin_UI::toggle('moxdop_allow_self_update', MoxDOP_Connector_Updater::allowed(), 'Bu eklentinin güncellemesi', 'Yalnız bu eklenti, yalnız daha yeni sürüm ve yalnız bağlı MoxDOP\'tan gelen, imzası tutan paket.');
        echo MoxDOP_Connector_Admin_UI::toggle('moxdop_allow_build', MoxDOP_Connector_Builder::allowed(), 'Site kurulumu', 'ACF alanları, sayfa ve yazılar (yayında olarak da), Elementor şablonları, medya, menüler ve birkaç site ayarı. Kurulan sitede aç; tema ve eklenti dosyalarına dokunmaz.');
        echo MoxDOP_Connector_Admin_UI::toggle('moxdop_indexnow', get_option('moxdop_connector_indexnow', '1') === '1', 'IndexNow', 'Yayınlanan ya da değişen sayfayı kaydeder kaydetmez Bing ve Yandex\'e bildirir. "Arama motorlarını engelle" açıkken ve site MoxDOP\'ta bir markaya bağlı değilken çalışmaz.');
        echo '</div></div><div class="mxa-actions"><button type="submit" class="button button-primary">Kaydet</button></div></form></div>';

        echo '<div class="mxa-card"><h2>Teknik bilgiler</h2><div class="mxa-tablewrap"><table class="mxa-table"><tbody>';
        foreach (['Eklenti sürümü' => MOXDOP_CONNECTOR_VERSION, 'Kurulum kimliği' => (string) get_option('moxdop_connector_installation_id'), 'Durum adresi' => rest_url('moxdop/v1/status'),
            'Kayıt boşluğu' => (string) ($delivery['gap_at'] ?? '—'), 'Zamanlama' => 'Değişiklikler kaydedilince hemen gönderilir; WP-Cron yedek olarak çalışır.'] as $label => $value) {
            echo '<tr><th style="width:200px">'.esc_html($label).'</th><td><code>'.esc_html($value).'</code></td></tr>';
        }
        echo '</tbody></table></div>';
        if ($paired) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'MoxDOP bağlantısı kesilsin mi?\');"><input type="hidden" name="action" value="moxdop_connector_disconnect">';
            wp_nonce_field('moxdop_connector_disconnect');
            echo '<div class="mxa-actions"><button type="submit" class="button mxa-danger">Bağlantıyı kes</button></div></form>';
        }
        echo '</div>';
        MoxDOP_Connector_Admin_UI::close();
    }

    public function pair()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('moxdop_connector_pair');

        $app_url = isset($_POST['moxdop_app_url']) ? esc_url_raw(wp_unslash($_POST['moxdop_app_url'])) : '';
        $code = isset($_POST['moxdop_pairing_code']) ? sanitize_text_field(wp_unslash($_POST['moxdop_pairing_code'])) : '';
        if (wp_parse_url($app_url, PHP_URL_SCHEME) !== 'https' || ! preg_match('/^MXD-\d+-[A-Z2-9]{24}$/', strtoupper($code))) {
            $this->redirect('failed');
        }

        $response = wp_remote_post(untrailingslashit($app_url).'/api/connectors/wordpress/pair', [
            'timeout' => 30,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'pairing_code' => strtoupper($code),
                'site_url' => site_url('/'),
                'home_url' => home_url('/'),
                'status_url' => rest_url('moxdop/v1/status'),
                'snapshot_url' => rest_url('moxdop/v1/snapshot'),
                'installation_id' => (string) get_option('moxdop_connector_installation_id'),
                'plugin_version' => MOXDOP_CONNECTOR_VERSION,
            ]),
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 201) {
            $this->redirect('failed');
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        if (! wp_is_uuid((string) ($data['client_id'] ?? '')) || strlen((string) ($data['shared_secret'] ?? '')) < 40) {
            $this->redirect('failed');
        }

        $stored = $this->secrets->store([
            'client_id' => (string) $data['client_id'],
            'shared_secret' => (string) $data['shared_secret'],
            'paired_at' => (string) ($data['paired_at'] ?? gmdate('c')),
        ]);
        if (is_wp_error($stored)) {
            $this->redirect('failed');
        }

        update_option('moxdop_connector_app_url', untrailingslashit($app_url), false);
        $this->redirect('paired');
    }

    public function disconnect()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('moxdop_connector_disconnect');
        delete_option(MoxDOP_Connector_Secrets::OPTION);
        delete_option('moxdop_connector_app_url');
        $this->redirect('disconnected');
    }

    public function save_management()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('moxdop_connector_management');
        $user_id = isset($_POST['moxdop_login_user']) ? absint(wp_unslash($_POST['moxdop_login_user'])) : 0;
        if ($user_id > 0 && ! user_can($user_id, 'edit_posts')) {
            $user_id = 0;
        }
        update_option('moxdop_connector_login_user', $user_id, false);
        update_option('moxdop_connector_allow_updates', ! empty($_POST['moxdop_allow_updates']) ? '1' : '0', false);
        update_option('moxdop_connector_allow_fixes', ! empty($_POST['moxdop_allow_fixes']) ? '1' : '0', false);
        update_option('moxdop_connector_allow_content', ! empty($_POST['moxdop_allow_content']) ? '1' : '0', false);
        update_option('moxdop_connector_allow_build', ! empty($_POST['moxdop_allow_build']) ? '1' : '0', false);
        update_option('moxdop_connector_allow_schedule', ! empty($_POST['moxdop_allow_schedule']) ? '1' : '0', false);
        update_option('moxdop_connector_allow_self_update', ! empty($_POST['moxdop_allow_self_update']) ? '1' : '0', false);
        update_option('moxdop_connector_indexnow', ! empty($_POST['moxdop_indexnow']) ? '1' : '0', false);
        $this->redirect('saved');
    }

    private function redirect($notice)
    {
        wp_safe_redirect(add_query_arg('moxdop_notice', $notice, MoxDOP_Connector_Admin_UI::url('moxdop-connector')));
        exit;
    }
}
