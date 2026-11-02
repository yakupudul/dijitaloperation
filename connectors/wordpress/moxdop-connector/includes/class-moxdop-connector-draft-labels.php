<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.35.0 (yakup, 2026-11-02: "taslak olarak görüyorum, ayırt edemiyorum"): in Yazılar / Sayfalar every draft
 * MoxDOP made says what it is, next to its title:
 *   "MoxDOP · Yeni yazı" / "MoxDOP · Çeviri (EN)": a new article waiting to be published;
 *   "MoxDOP · Güncelleme → <page>": a new version of a live page. Publishing it would put a second page on the same
 *     topic live, so WordPress's Publish keeps it a draft; "Canlı sayfaya uygula" puts it on the live page instead;
 *   "MoxDOP · 301 ile birleştirildi": a page merged into another one (its visitors are redirected); not to publish.
 * Views "MoxDOP güncellemeleri" and "MoxDOP yeni yazıları" filter the lists.
 */
final class MoxDOP_Connector_Draft_Labels
{
    const NOTICE = 'moxdop_update_copy_blocked_';

    public function register()
    {
        add_filter('wp_insert_post_data', [$this, 'keep_copy_draft'], 20, 2);
        if (! is_admin()) {
            return;
        }
        add_filter('display_post_states', [$this, 'states'], 20, 2);
        add_action('admin_notices', [$this, 'notice']);
        add_action('enqueue_block_editor_assets', [$this, 'block_editor_notice']);
        add_action('admin_post_moxdop_apply_update_copy', [$this, 'apply']);
        add_action('pre_get_posts', [$this, 'filter_list']);
        foreach (['post', 'page'] as $type) {
            add_filter('views_edit-'.$type, [$this, 'views']);
        }
    }

    /** @return string new | translation | update | merged | '' */
    public static function kind($post)
    {
        $post = get_post($post);
        if (! $post instanceof WP_Post) {
            return '';
        }
        if ((int) get_post_meta($post->ID, '_moxdop_update_of', true) > 0) {
            return 'update';
        }
        if ($post->post_status !== 'publish' && self::merged_to($post) !== '') {
            return 'merged';
        }
        if (! in_array($post->post_status, ['draft', 'pending', 'future'], true)) {
            return '';
        }
        if (get_post_meta($post->ID, '_moxdop_created', true) === '1' || (string) get_post_meta($post->ID, '_moxdop_ai_created', true) !== '') {
            return self::translation_language($post) !== '' ? 'translation' : 'new';
        }

        return '';
    }

    /** Where a 301-merged page sends its visitors (1.35.0 keeps it on the page; older merges are found by its path). */
    public static function merged_to(WP_Post $post)
    {
        $to = (string) get_post_meta($post->ID, '_moxdop_merged_to', true);
        if ($to !== '' || $post->post_status !== 'draft' || $post->post_name === '') {
            return $to;
        }
        static $redirects = null;
        if ($redirects === null) {
            $redirects = (array) get_option(MoxDOP_Connector_Fixes::REDIRECTS_OPTION, []);
        }
        if ($redirects === []) {
            return '';
        }
        if (! function_exists('get_sample_permalink')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }
        $sample = get_sample_permalink($post);
        $url = str_replace(['%postname%', '%pagename%'], (string) ($sample[1] ?? ''), (string) ($sample[0] ?? ''));
        $path = '/'.trim(strtolower(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH))), '/');

        return (string) ($redirects[$path] ?? '');
    }

    private static function translation_language(WP_Post $post)
    {
        if ((string) get_post_meta($post->ID, '_moxdop_translation_key', true) === '' || ! function_exists('pll_get_post_language') || ! function_exists('pll_default_language')) {
            return '';
        }
        $language = (string) pll_get_post_language($post->ID, 'slug');

        return $language !== '' && $language !== (string) pll_default_language('slug') ? $language : '';
    }

    /** WordPress's Publish on an update copy: the copy stays a draft (a second page on the same topic never goes live). */
    public function keep_copy_draft($data, $postarr)
    {
        $id = (int) ($postarr['ID'] ?? 0);
        if ($id < 1 || ! in_array($data['post_status'] ?? '', ['publish', 'future', 'private'], true) || (int) get_post_meta($id, '_moxdop_update_of', true) < 1) {
            return $data;
        }
        $data['post_status'] = 'draft';
        set_transient(self::NOTICE.get_current_user_id(), $id, 300);

        return $data;
    }

    public function states($states, $post)
    {
        $kind = self::kind($post);
        if ($kind === 'update') {
            $target = get_post((int) get_post_meta($post->ID, '_moxdop_update_of', true));
            $states['moxdop'] = 'MoxDOP · Güncelleme → '.($target ? wp_strip_all_tags(get_the_title($target)) : 'silinmiş sayfa');
            unset($states['draft']);
        } elseif ($kind === 'merged') {
            $states['moxdop'] = 'MoxDOP · 301 ile birleştirildi';
            unset($states['draft']);
        } elseif ($kind === 'translation') {
            $states['moxdop'] = 'MoxDOP · Çeviri ('.strtoupper(self::translation_language($post)).')';
        } elseif ($kind === 'new') {
            $states['moxdop'] = 'MoxDOP · Yeni yazı';
        }

        return $states;
    }

    /** Classic editor and lists: what an update copy is, and the result of "Canlı sayfaya uygula". */
    public function notice()
    {
        $done = isset($_GET['moxdop_applied']) ? sanitize_key(wp_unslash($_GET['moxdop_applied'])) : '';
        if ($done !== '') {
            $error = isset($_GET['moxdop_error']) ? sanitize_text_field(wp_unslash($_GET['moxdop_error'])) : '';
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $done === 'ok' ? 'success' : 'error',
                esc_html($done === 'ok' ? 'Yeni sürüm canlı sayfaya uygulandı; taslak kopya çöpe taşındı. Eski hali sayfanın revizyonlarında duruyor.' : 'Uygulanamadı: '.$error));
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || $screen->base !== 'post' || (method_exists($screen, 'is_block_editor') && $screen->is_block_editor())) {
            return;
        }
        $post = get_post(isset($_GET['post']) ? absint($_GET['post']) : 0);
        $message = $post ? $this->message($post) : null;
        if ($message === null) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>'.esc_html($message['title']).'</strong> '.esc_html($message['text']).'</p>';
        if ($message['apply'] !== '') {
            echo '<p><a class="button button-primary" href="'.esc_url($message['apply']).'" onclick="return confirm(\'Canlı sayfanın başlığı ve metni bu sürümle değişsin mi?\')">Canlı sayfaya uygula</a> <a class="button" href="'.esc_url($message['target']).'" target="_blank" rel="noopener">Canlı sayfayı aç</a></p>';
        }
        echo '</div>';
    }

    public function block_editor_notice()
    {
        $post = get_post();
        $message = $post ? $this->message($post) : null;
        if ($message === null) {
            return;
        }
        $actions = [];
        if ($message['apply'] !== '') {
            $actions[] = ['label' => 'Canlı sayfaya uygula', 'url' => $message['apply']];
            $actions[] = ['label' => 'Canlı sayfayı aç', 'url' => $message['target']];
        }
        wp_register_script('moxdop-draft-kind', false, ['wp-data', 'wp-dom-ready', 'wp-notices'], MOXDOP_CONNECTOR_VERSION, true);
        wp_enqueue_script('moxdop-draft-kind');
        wp_add_inline_script('moxdop-draft-kind', sprintf(
            'wp.domReady(function(){wp.data.dispatch("core/notices").createNotice("warning",%s,{id:"moxdop-kind",isDismissible:false,actions:%s});});',
            wp_json_encode($message['title'].' '.$message['text']), wp_json_encode($actions)
        ));
    }

    /** @return array{title: string, text: string, apply: string, target: string}|null */
    private function message(WP_Post $post)
    {
        $kind = self::kind($post);
        if ($kind === 'update') {
            $target = get_post((int) get_post_meta($post->ID, '_moxdop_update_of', true));
            $blocked = (int) get_transient(self::NOTICE.get_current_user_id()) === (int) $post->ID;
            if ($blocked) {
                delete_transient(self::NOTICE.get_current_user_id());
            }
            $title = $target ? wp_strip_all_tags(get_the_title($target)) : 'silinmiş sayfa';
            $can = $target && current_user_can('edit_post', $target->ID) && $post->post_status === 'draft';

            return [
                'title' => 'MoxDOP güncellemesi: «'.$title.'» sayfasının yeni sürümü.',
                'text' => ($blocked ? 'Yayınla bu kopyayı ikinci bir sayfa olarak yayına alırdı; taslakta tutuldu. ' : 'Bunu Yayınla ile yayınlama (aynı konuda ikinci sayfa olur). ')
                    .'Canlı sayfaya uygulamak için aşağıdaki düğmeyi kullan.',
                'apply' => $can ? wp_nonce_url(admin_url('admin-post.php?action=moxdop_apply_update_copy&post='.(int) $post->ID), 'moxdop_apply_update_copy_'.$post->ID) : '',
                'target' => $target ? (string) get_permalink($target) : '',
            ];
        }
        if ($kind === 'merged') {
            return ['title' => 'MoxDOP: bu sayfa 301 ile birleştirildi.', 'text' => 'Ziyaretçileri '.self::merged_to($post).' adresine gidiyor. Yayınlama; geri almak MoxDOP\'tan yapılır.', 'apply' => '', 'target' => ''];
        }
        if ($kind === 'new' || $kind === 'translation') {
            return ['title' => $kind === 'new' ? 'MoxDOP yeni yazısı.' : 'MoxDOP çevirisi.', 'text' => 'Sitede henüz olmayan bir içerik; okuyup yayınlayabilirsin.', 'apply' => '', 'target' => ''];
        }

        return null;
    }

    public function apply()
    {
        $id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        check_admin_referer('moxdop_apply_update_copy_'.$id);
        $copy = get_post($id);
        $target = (int) get_post_meta($id, '_moxdop_update_of', true);
        if (! $copy || $target < 1 || ! current_user_can('edit_post', $target) || ! current_user_can('edit_post', $id)) {
            wp_die('Bu işlem için yetkin yok.');
        }
        $result = (new MoxDOP_Connector_Fixes)->apply_update_copy($copy, 'editor-apply');
        $args = is_wp_error($result) ? ['moxdop_applied' => 'error', 'moxdop_error' => rawurlencode($result->get_error_message())] : ['moxdop_applied' => 'ok'];
        wp_safe_redirect(add_query_arg($args, admin_url('post.php?post='.$target.'&action=edit')));
        exit;
    }

    /** "MoxDOP güncellemeleri (n)" / "MoxDOP yeni yazıları (n)" above the list. */
    public function views($views)
    {
        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        $current = isset($_GET['moxdop_kind']) ? sanitize_key(wp_unslash($_GET['moxdop_kind'])) : '';
        foreach (['update' => 'MoxDOP güncellemeleri', 'new' => 'MoxDOP yeni yazıları'] as $kind => $label) {
            $count = count(get_posts(self::query_args($type, $kind) + ['fields' => 'ids', 'numberposts' => 500]));
            if ($count === 0) {
                continue;
            }
            $url = add_query_arg(['post_type' => $type, 'moxdop_kind' => $kind], admin_url('edit.php'));
            $views['moxdop_'.$kind] = sprintf('<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url($url), $current === $kind ? ' class="current" aria-current="page"' : '', esc_html($label), $count);
        }

        return $views;
    }

    public function filter_list($query)
    {
        if (! $query->is_main_query() || ! isset($_GET['moxdop_kind']) || ! function_exists('get_current_screen') || ! get_current_screen() || get_current_screen()->base !== 'edit') {
            return;
        }
        $kind = sanitize_key(wp_unslash($_GET['moxdop_kind']));
        foreach (self::query_args((string) $query->get('post_type'), $kind) as $key => $value) {
            $query->set($key, $value);
        }
    }

    /** @return array<string, mixed> */
    private static function query_args($type, $kind)
    {
        $base = ['post_type' => $type ?: 'post', 'suppress_filters' => false, 'lang' => ''];
        if ($kind === 'update') {
            return $base + ['post_status' => ['draft', 'pending'], 'meta_query' => [['key' => '_moxdop_update_of', 'compare' => 'EXISTS']]];
        }

        return $base + ['post_status' => ['draft', 'pending', 'future'], 'meta_query' => ['relation' => 'AND', ['key' => '_moxdop_update_of', 'compare' => 'NOT EXISTS'],
            ['relation' => 'OR', ['key' => '_moxdop_created', 'value' => '1'], ['key' => '_moxdop_ai_created', 'compare' => 'EXISTS']]]];
    }
}
