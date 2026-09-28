<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.5.0 (ADR-076): "Import sonrası Polylang eşleştir".
 *
 * The WordPress importer does not keep Polylang languages or translation links. Posts from a MoxDOP WXR file carry
 * `_moxdop_translation_key` (same value on every language version of one article) and `_moxdop_language` (Polylang
 * slug). Tools › MoxDOP Polylang shows what would change (dry run) and, after a site admin confirms, sets each post's
 * language and links the versions as translations. Running it again changes nothing that is already linked. Terms of
 * the post without a language get the post's language. Only local; nothing is sent to MoxDOP.
 */
final class MoxDOP_Connector_Translation_Pairing
{
    const PAGE = 'moxdop-polylang-pairing';

    const LAST_RUN_OPTION = 'moxdop_connector_pairing_last_run';

    const MAX_POSTS = 2000;

    public function register()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_moxdop_polylang_pair', [$this, 'apply']);
    }

    public function menu()
    {
        add_management_page('MoxDOP Polylang', 'MoxDOP Polylang', 'manage_options', self::PAGE, [$this, 'render']);
    }

    /**
     * Translation groups found in imported posts, with the planned action.
     *
     * @return array<string, array{posts: array<int, array<string, mixed>>, status: string, reason: string}>
     */
    public function plan()
    {
        $ids = get_posts([
            'post_type' => 'any', 'post_status' => ['draft', 'pending', 'future', 'private', 'publish'], 'meta_key' => '_moxdop_translation_key',
            'numberposts' => self::MAX_POSTS, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'lang' => '', 'suppress_filters' => false,
        ]);
        $groups = [];
        foreach ((array) $ids as $post_id) {
            $key = sanitize_text_field((string) get_post_meta($post_id, '_moxdop_translation_key', true));
            if ($key === '') {
                continue;
            }
            $groups[$key]['posts'][] = [
                'id' => (int) $post_id,
                'title' => get_the_title($post_id),
                'language' => sanitize_key((string) get_post_meta($post_id, '_moxdop_language', true)),
                'current' => function_exists('pll_get_post_language') ? (string) pll_get_post_language($post_id, 'slug') : '',
            ];
        }
        foreach ($groups as $key => $group) {
            $groups[$key] += $this->status($group['posts']);
        }
        ksort($groups, SORT_STRING);

        return $groups;
    }

    /** @return array{status: string, reason: string} */
    private function status(array $posts)
    {
        if (! MoxDOP_Connector_Drafts::polylang_active()) {
            return ['status' => 'skip', 'reason' => 'Polylang is not active / Polylang etkin değil'];
        }
        $languages = [];
        foreach ($posts as $post) {
            if (! MoxDOP_Connector_Drafts::valid_language($post['language'])) {
                return ['status' => 'skip', 'reason' => 'Unknown language "'.$post['language'].'" / Bilinmeyen dil'];
            }
            if (isset($languages[$post['language']])) {
                return ['status' => 'skip', 'reason' => 'Two posts in the same language / Aynı dilde iki yazı'];
            }
            $languages[$post['language']] = $post['id'];
        }
        $linked = function_exists('pll_get_post_translations') ? array_map('intval', (array) pll_get_post_translations($posts[0]['id'])) : [];
        foreach ($posts as $post) {
            if ($post['current'] !== $post['language'] || ($linked[$post['language']] ?? 0) !== $post['id']) {
                return ['status' => 'pair', 'reason' => count($posts) > 1 ? 'Will be linked / Eşleştirilecek' : 'Language will be set / Dil atanacak'];
            }
        }

        return ['status' => 'done', 'reason' => 'Already linked / Zaten eşli'];
    }

    public function render()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $groups = $this->plan();
        $pending = count(array_filter($groups, static function ($group) {
            return $group['status'] === 'pair';
        }));
        $notice = isset($_GET['moxdop_notice']) ? sanitize_key(wp_unslash($_GET['moxdop_notice'])) : '';
        $last = get_option(self::LAST_RUN_OPTION, []);
        ?>
        <div class="wrap">
            <h1>MoxDOP Polylang — Import sonrası eşleştir</h1>
            <p>Posts imported from a MoxDOP export file carry a translation key. This page sets each post's Polylang language and links the language versions of one article as translations. Nothing is linked until you press the button. / MoxDOP dışa aktarma dosyasından içe aktarılan yazıların dilini atar ve aynı makalenin dil sürümlerini çeviri olarak eşleştirir.</p>
            <?php if ($notice === 'paired') { ?>
                <div class="notice notice-success"><p><?php echo esc_html(sprintf('%d group(s) linked. / %d grup eşleştirildi.', (int) ($last['groups'] ?? 0), (int) ($last['groups'] ?? 0))); ?></p></div>
            <?php } ?>
            <?php if (! MoxDOP_Connector_Drafts::polylang_active()) { ?>
                <div class="notice notice-warning"><p>Polylang is not active. / Polylang etkin değil.</p></div>
            <?php } ?>
            <table class="widefat striped" style="max-width: 980px; margin: 18px 0;">
                <thead><tr><th>Key / Anahtar</th><th>Posts / Yazılar</th><th>Action / İşlem</th></tr></thead>
                <tbody>
                <?php if ($groups === []) { ?>
                    <tr><td colspan="3">No imported MoxDOP posts found. / İçe aktarılmış MoxDOP yazısı yok.</td></tr>
                <?php } ?>
                <?php foreach ($groups as $key => $group) { ?>
                    <tr>
                        <td><code><?php echo esc_html($key); ?></code></td>
                        <td>
                            <?php foreach ($group['posts'] as $post) { ?>
                                <div>#<?php echo (int) $post['id']; ?> <?php echo esc_html($post['title']); ?> — <strong><?php echo esc_html($post['language'] ?: '?'); ?></strong><?php if ($post['current'] !== '' && $post['current'] !== $post['language']) { ?> (now / şu an: <?php echo esc_html($post['current']); ?>)<?php } ?></div>
                            <?php } ?>
                        </td>
                        <td><?php echo esc_html($group['reason']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <?php if ($pending > 0) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="moxdop_polylang_pair">
                    <?php wp_nonce_field('moxdop_polylang_pair'); ?>
                    <?php submit_button(sprintf('Link %d group(s) / %d grubu eşleştir', $pending, $pending)); ?>
                </form>
            <?php } ?>
        </div>
        <?php
    }

    public function apply()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('moxdop_polylang_pair');
        $count = 0;
        foreach ($this->plan() as $group) {
            if ($group['status'] !== 'pair') {
                continue;
            }
            $this->pair($group['posts']);
            $count++;
        }
        update_option(self::LAST_RUN_OPTION, ['groups' => $count, 'at' => gmdate('c'), 'user_id' => get_current_user_id()], false);
        wp_safe_redirect(add_query_arg('moxdop_notice', 'paired', admin_url('tools.php?page='.self::PAGE)));
        exit;
    }

    /** Language of every post (and of its terms that have none), then one merged translation group. */
    private function pair(array $posts)
    {
        $group = [];
        foreach ($posts as $post) {
            pll_set_post_language($post['id'], $post['language']);
            $existing = function_exists('pll_get_post_translations') ? (array) pll_get_post_translations($post['id']) : [];
            foreach ($existing as $language => $id) {
                if (! isset($group[$language])) {
                    $group[$language] = (int) $id;
                }
            }
            $this->term_languages($post['id'], $post['language']);
        }
        foreach ($posts as $post) {
            $group[$post['language']] = $post['id'];
        }
        if (count($group) > 1) {
            pll_save_post_translations(array_map('intval', $group));
        }
    }

    private function term_languages($post_id, $language)
    {
        if (! function_exists('pll_get_term_language') || ! function_exists('pll_set_term_language')) {
            return;
        }
        foreach (['category', 'post_tag'] as $taxonomy) {
            $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            foreach (is_wp_error($terms) ? [] : (array) $terms as $term_id) {
                if ((string) pll_get_term_language((int) $term_id, 'slug') === '') {
                    pll_set_term_language((int) $term_id, $language);
                }
            }
        }
    }
}
