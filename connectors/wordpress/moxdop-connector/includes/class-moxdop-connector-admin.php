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
        $notice = isset($_GET['moxdop_notice']) ? sanitize_key(wp_unslash($_GET['moxdop_notice'])) : '';
        ?>
        <div class="wrap">
            <h1>MoxDOP Website Connector</h1>
            <p>This read-only connector shares CMS inventory with MoxDOP. It does not expose users, passwords, comments, media binaries, or write operations.</p>
            <?php if ($notice === 'paired') : ?>
                <div class="notice notice-success"><p>Connector paired successfully.</p></div>
            <?php elseif ($notice === 'disconnected') : ?>
                <div class="notice notice-success"><p>Connector disconnected and its local credential removed.</p></div>
            <?php elseif ($notice === 'failed') : ?>
                <div class="notice notice-error"><p>Pairing failed. Confirm the HTTPS MoxDOP URL and one-time code, then try again.</p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width: 760px; margin: 18px 0;">
                <tbody>
                    <tr><th>Status</th><td><?php echo $paired ? 'Paired' : 'Not paired'; ?></td></tr>
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

            <?php if ($paired) : ?>
                <hr>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="moxdop_connector_disconnect">
                    <?php wp_nonce_field('moxdop_connector_disconnect'); ?>
                    <?php submit_button('Disconnect', 'delete', 'submit', false); ?>
                </form>
            <?php endif; ?>
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

    private function redirect($notice)
    {
        wp_safe_redirect(add_query_arg('moxdop_notice', $notice, admin_url('options-general.php?page=moxdop-connector')));
        exit;
    }
}
