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
    }

    public function menu()
    {
        add_options_page(
            'MoxDOP Connector',
            'MoxDOP Connector',
            'manage_options',
            'moxdop-connector',
            [$this, 'render']
        );
    }

    public function render()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $paired = is_array($this->secrets->read());
        $delivery = (new MoxDOP_Connector_Events)->status();
        $notice = isset($_GET['moxdop_notice']) ? sanitize_key(wp_unslash($_GET['moxdop_notice'])) : '';
        ?>
        <div class="wrap">
            <h1>MoxDOP Website Connector</h1>
            <p>This connector shares CMS inventory and health with MoxDOP. It sends content, SEO and maintenance activity with the acting user ID/name. It never sends passwords, comments, form submissions or media binaries. It can create drafts (never publishes). One-click login and approved updates below are off unless you turn them on.</p>
            <?php if ($notice === 'paired') { ?>
                <div class="notice notice-success"><p>Connector paired successfully.</p></div>
            <?php } elseif ($notice === 'disconnected') { ?>
                <div class="notice notice-success"><p>Connector disconnected and its local credential removed.</p></div>
            <?php } elseif ($notice === 'saved') { ?>
                <div class="notice notice-success"><p>Settings saved.</p></div>
            <?php } elseif ($notice === 'failed') { ?>
                <div class="notice notice-error"><p>Pairing failed. Confirm the HTTPS MoxDOP URL and one-time code, then try again.</p></div>
            <?php } ?>

            <table class="widefat striped" style="max-width: 760px; margin: 18px 0;">
                <tbody>
                    <tr><th>Status</th><td><?php echo $paired ? 'Paired' : 'Not paired'; ?></td></tr>
                    <tr><th>Activity queue / İşlem kuyruğu</th><td><?php echo esc_html((string) ($delivery['pending'] ?? 'Unavailable')); ?></td></tr>
                    <tr><th>Last delivery / Son aktarım</th><td><?php echo esc_html((string) ($delivery['last_ack_at'] ?? 'Not yet delivered')); ?></td></tr>
                    <tr><th>Delivery error / Aktarım hatası</th><td><?php echo esc_html((string) ($delivery['last_error'] ?? '—')); ?></td></tr>
                    <tr><th>Coverage gap / Kayıt boşluğu</th><td><?php echo esc_html((string) ($delivery['gap_at'] ?? '—')); ?></td></tr>
                    <tr><th>Scheduling</th><td>Events are sent in batches through WP-Cron. Low traffic can delay delivery; a host cron is recommended for regular delivery.</td></tr>
                    <tr><th>Plugin version</th><td><?php echo esc_html(MOXDOP_CONNECTOR_VERSION); ?></td></tr>
                    <tr><th>Installation ID</th><td><code><?php echo esc_html((string) get_option('moxdop_connector_installation_id')); ?></code></td></tr>
                    <tr><th>Status endpoint</th><td><code><?php echo esc_html(rest_url('moxdop/v1/status')); ?></code></td></tr>
                </tbody>
            </table>

            <h2><?php echo $paired ? 'Rotate pairing' : 'Pair this site'; ?></h2>
            <p>Generate a one-time pairing code for this Website in MoxDOP, then enter it below. A rotation keeps the current connection active until the new pairing succeeds.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 760px;">
                <input type="hidden" name="action" value="moxdop_connector_pair">
                <?php wp_nonce_field('moxdop_connector_pair'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="moxdop_app_url">MoxDOP HTTPS URL</label></th>
                        <td><input class="regular-text" id="moxdop_app_url" name="moxdop_app_url" type="url" required placeholder="https://app.moximu.com"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="moxdop_pairing_code">One-time pairing code</label></th>
                        <td><input class="regular-text" id="moxdop_pairing_code" name="moxdop_pairing_code" type="text" required autocomplete="off" pattern="MXD-[0-9]+-[A-Za-z2-9]{24}"></td>
                    </tr>
                </table>
                <?php submit_button($paired ? 'Rotate pairing' : 'Pair connector'); ?>
            </form>

            <h2>MoxDOP management / Yönetim</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 760px;">
                <input type="hidden" name="action" value="moxdop_connector_management">
                <?php wp_nonce_field('moxdop_connector_management'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="moxdop_login_user">One-click login user / Tek tık giriş kullanıcısı</label></th>
                        <td>
                            <select id="moxdop_login_user" name="moxdop_login_user">
                                <option value="0">Off / Kapalı</option>
                                <?php foreach (get_users(['role__in' => ['administrator', 'editor'], 'fields' => ['ID', 'user_login']]) as $user) { ?>
                                    <option value="<?php echo (int) $user->ID; ?>" <?php selected(MoxDOP_Connector_Management::login_user_id(), (int) $user->ID); ?>><?php echo esc_html($user->user_login); ?></option>
                                <?php } ?>
                            </select>
                            <p class="description">MoxDOP can open a single-use, 60-second login link as this user. Use a dedicated account for your agency.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Approved updates / Onaylı güncelleme</th>
                        <td><label><input type="checkbox" name="moxdop_allow_updates" value="1" <?php checked(MoxDOP_Connector_Management::updates_allowed()); ?>> Allow MoxDOP to install plugin, theme and WordPress updates that WordPress offers, one at a time, after an admin approves each in MoxDOP.</label></td>
                    </tr>
                    <tr>
                        <th scope="row">SEO fixes / SEO düzeltmeleri</th>
                        <td><label><input type="checkbox" name="moxdop_allow_fixes" value="1" <?php checked(MoxDOP_Connector_Fixes::fixes_allowed()); ?>> Allow MoxDOP to change SEO titles and descriptions, image alt texts, schema, 301 redirects, noindex / canonical and to add internal links, after an admin approves each change in MoxDOP. Every change can be undone from MoxDOP.</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Content updates / İçerik güncelleme</th>
                        <td><label><input type="checkbox" name="moxdop_allow_content" value="1" <?php checked(MoxDOP_Connector_Fixes::content_allowed()); ?>> Allow MoxDOP to save a new version of a page as a draft copy, and to replace the live page with it only after a second approval. WordPress keeps the old version as a revision.</label></td>
                    </tr>
                </table>
                <?php submit_button('Save'); ?>
            </form>

            <?php if ($paired) { ?>
                <hr>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="moxdop_connector_disconnect">
                    <?php wp_nonce_field('moxdop_connector_disconnect'); ?>
                    <?php submit_button('Disconnect', 'delete', 'submit', false); ?>
                </form>
            <?php } ?>
        </div>
        <?php
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
        $this->redirect('saved');
    }

    private function redirect($notice)
    {
        wp_safe_redirect(add_query_arg('moxdop_notice', $notice, admin_url('options-general.php?page=moxdop-connector')));
        exit;
    }
}
