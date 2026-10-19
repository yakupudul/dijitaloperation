<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.19.0: "Akıllı tarihle yayınla" in the post editor (block and classic).
 *
 * The blog of a client site should never show empty weeks. The box finds the publishing days (Monday, Wednesday,
 * Friday) of the last 60 days that have no post yet, nearest to today first; when none is left it offers the next free
 * publishing day ahead. A day is taken by a published or scheduled post, or by a draft that already carries its own
 * date (MoxDOP's dated drafts), in the same Polylang language. The time is between 09:00 and 11:30, different per post.
 * Clicking the button only fills the editor's own date and presses its own Publish / Schedule: the person editing
 * publishes, nothing happens from outside. A text that mentions this year, a price or a campaign is flagged before a
 * past date. Not yet published language versions of the post get the same date when it is published.
 * Off with the `moxdop_connector_smart_date` filter.
 */
final class MoxDOP_Connector_Smart_Date
{
    const DAYS_BACK = 60;

    const DAYS_AHEAD = 60;

    const SHOWN = 6;

    const PICKED_META = '_moxdop_smart_date';

    public function register()
    {
        add_action('add_meta_boxes_post', [$this, 'box']);
        add_action('transition_post_status', [$this, 'translations'], 20, 3);
        add_action('wp_ajax_moxdop_smart_date_pick', [$this, 'pick']);
    }

    /** The button marks the post just before it publishes, so its translations follow only that click. */
    public function pick()
    {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (! check_ajax_referer('moxdop_smart_date', 'nonce', false) || $post_id <= 0 || ! current_user_can('publish_post', $post_id)) {
            wp_send_json_error(null, 403);
        }
        update_post_meta($post_id, self::PICKED_META, (string) time());
        wp_send_json_success();
    }

    public static function enabled()
    {
        return (bool) apply_filters('moxdop_connector_smart_date', true);
    }

    /** @return int[] ISO weekdays (1 = Monday) posts go out on */
    public static function weekdays()
    {
        $days = apply_filters('moxdop_connector_smart_date_weekdays', [1, 3, 5]);

        return array_values(array_filter(array_map('intval', (array) $days), static function ($d) {
            return $d >= 1 && $d <= 7;
        }));
    }

    public function box($post)
    {
        if (! self::enabled() || ! in_array($post->post_status, ['draft', 'pending', 'auto-draft'], true) || ! current_user_can('publish_post', $post->ID)) {
            return;
        }
        add_meta_box('moxdop-smart-date', 'MoxDOP · Akıllı tarih', [$this, 'render'], 'post', 'side', 'high');
    }

    /**
     * Free publishing days for the post: past ones (nearest first) then, when none, the next ones ahead.
     *
     * @return array<int, array{date: string, label: string, past: bool}>
     */
    public static function slots($post_id, $now = null)
    {
        $tz = wp_timezone();
        $today = (new DateTimeImmutable('@'.($now ?? time())))->setTimezone($tz)->setTime(0, 0);
        $taken = self::taken($post_id, $today);
        $weekdays = self::weekdays();
        $out = [];
        for ($i = 0; $i <= self::DAYS_BACK && count($out) < self::SHOWN; $i++) {
            $day = $today->modify('-'.$i.' days');
            if (in_array((int) $day->format('N'), $weekdays, true) && ! isset($taken[$day->format('Y-m-d')])) {
                $out[] = self::slot($post_id, $day, true, $now);
            }
        }
        if ($out === []) {
            for ($i = 1; $i <= self::DAYS_AHEAD && count($out) < self::SHOWN; $i++) {
                $day = $today->modify('+'.$i.' days');
                if (in_array((int) $day->format('N'), $weekdays, true) && ! isset($taken[$day->format('Y-m-d')])) {
                    $out[] = self::slot($post_id, $day, false, $now);
                }
            }
        }

        return $out;
    }

    /** @return array{date: string, label: string, past: bool} */
    private static function slot($post_id, DateTimeImmutable $day, $past, $now)
    {
        // 09:00–11:30, the same for the same post and day (what the box shows is what is published).
        $minutes = 9 * 60 + (abs(crc32($post_id.'|'.$day->format('Y-m-d'))) % 151);
        $at = $day->setTime(intdiv($minutes, 60), $minutes % 60);
        $current = (new DateTimeImmutable('@'.($now ?? time())))->setTimezone(wp_timezone());
        if ($past && $at > $current) {
            // Today, before the picked hour: published now.
            $at = $current;
        }
        $names = ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
        $months = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

        return [
            'date' => $at->format('Y-m-d\TH:i:s'),
            'label' => $names[(int) $at->format('N')].' '.(int) $at->format('j').' '.$months[(int) $at->format('n')].', '.$at->format('H:i'),
            'past' => (bool) $past,
        ];
    }

    /** @return array<string, true> days (Y-m-d, site time) that already have a post of the post's language */
    private static function taken($post_id, DateTimeImmutable $today)
    {
        $args = [
            'post_type' => 'post', 'post_status' => ['publish', 'future', 'draft', 'pending', 'private'], 'numberposts' => 500, 'fields' => 'ids',
            'post__not_in' => [(int) $post_id], 'suppress_filters' => false, 'orderby' => 'date', 'order' => 'DESC',
            'date_query' => [['after' => $today->modify('-'.(self::DAYS_BACK + 1).' days')->format('Y-m-d'), 'before' => $today->modify('+'.(self::DAYS_AHEAD + 1).' days')->format('Y-m-d'), 'inclusive' => true]],
        ];
        if (function_exists('pll_get_post_language')) {
            $language = pll_get_post_language($post_id, 'slug');
            $args['lang'] = $language ? $language : (function_exists('pll_default_language') ? (string) pll_default_language('slug') : '');
        }
        $days = [];
        foreach ((array) get_posts($args) as $id) {
            $post = get_post($id);
            if (! $post) {
                continue;
            }
            // A draft counts only when it was given its own date (a dated MoxDOP draft); an undated draft has none.
            if (in_array($post->post_status, ['draft', 'pending'], true) && ($post->post_date_gmt === '' || strpos($post->post_date_gmt, '0000-00-00') === 0)) {
                continue;
            }
            $days[substr((string) $post->post_date, 0, 10)] = true;
        }

        return $days;
    }

    /** Reasons a past date would mislead: this or next year, a price, a campaign. @return string[] */
    public static function warnings($post)
    {
        $text = wp_strip_all_tags((string) $post->post_title.' '.(string) $post->post_content);
        $year = (int) current_time('Y');
        $out = [];
        if (preg_match('/\b('.$year.'|'.($year + 1).')\b/u', $text)) {
            $out[] = 'yıl ('.$year.')';
        }
        if (preg_match('/(\d[\d.,]*\s?(TL|₺|€|£|\$|EUR|USD|GBP)\b)|((₺|€|£|\$)\s?\d)|\bfiyat/iu', $text)) {
            $out[] = 'fiyat';
        }
        if (preg_match('/\b(kampanya|indirim|fırsat|son gün|campaign|discount)/iu', $text)) {
            $out[] = 'kampanya';
        }

        return $out;
    }

    public function render($post)
    {
        $slots = self::slots($post->ID);
        if ($slots === []) {
            echo '<p>Önümüzdeki '.(int) self::DAYS_AHEAD.' günde boş yayın günü yok.</p>';

            return;
        }
        $warnings = self::warnings($post);
        echo '<p style="margin-top:0">'.($slots[0]['past']
            ? 'Son '.(int) self::DAYS_BACK.' günün boş Pazartesi, Çarşamba ve Cuma günleri, bugüne en yakın olandan başlayarak.'
            : 'Son '.(int) self::DAYS_BACK.' günde boş yayın günü kalmadı; sıradaki boş günler.').'</p>';
        echo '<p><label for="moxdop-smart-date-slot" class="screen-reader-text">Yayın tarihi</label><select id="moxdop-smart-date-slot" style="width:100%">';
        foreach ($slots as $slot) {
            echo '<option value="'.esc_attr($slot['date']).'" data-past="'.($slot['past'] ? '1' : '0').'">'.esc_html($slot['label']).'</option>';
        }
        echo '</select></p>';
        if ($warnings !== [] && $slots[0]['past']) {
            echo '<p style="color:#b45309">Yazıda '.esc_html(implode(', ', $warnings)).' geçiyor; geçmiş tarih okuyanı yanıltabilir. Gerekirse “Şimdi yayınla”yı kullan.</p>';
        }
        echo '<p><button type="button" class="button button-primary" id="moxdop-smart-date-go" style="width:100%">'.($slots[0]['past'] ? 'Bu tarihle yayınla' : 'Bu tarihe zamanla').'</button></p>';
        echo '<p class="description">Düğme yalnız tarihi doldurup yayınlar; yazının henüz yayında olmayan çevirileri de aynı tarihi alır.</p>';
        $this->script($post->ID);
    }

    /** Fills the editor's own date and presses its own publish (block editor: core/editor; classic: the timestamp fields). */
    private function script($post_id)
    {
        $config = ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('moxdop_smart_date'), 'post' => (int) $post_id];
        ?>
<script>
(function () {
    var config = <?php echo wp_json_encode($config); ?>;
    var button = document.getElementById('moxdop-smart-date-go');
    var select = document.getElementById('moxdop-smart-date-slot');
    if (!button || !select) { return; }
    select.addEventListener('change', function () {
        button.textContent = select.options[select.selectedIndex].getAttribute('data-past') === '1' ? 'Bu tarihle yayınla' : 'Bu tarihe zamanla';
    });
    button.addEventListener('click', function () {
        var date = select.value;
        button.disabled = true;
        var body = new URLSearchParams({ action: 'moxdop_smart_date_pick', nonce: config.nonce, post_id: String(config.post) });
        var mark = window.fetch ? fetch(config.ajax, { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {}) : Promise.resolve();
        mark.then(function () {
            if (window.wp && wp.data && wp.data.select('core/editor')) {
                wp.data.dispatch('core/editor').editPost({ date: date, status: 'publish' });
                wp.data.dispatch('core/editor').savePost().then(function () { window.location.reload(); });
                return;
            }
            var parts = date.split(/[-T:]/);
            var fields = { aa: parts[0], mm: parts[1], jj: parts[2], hh: parts[3], mn: parts[4] };
            Object.keys(fields).forEach(function (id) {
                var el = document.getElementById(id);
                if (el) { el.value = fields[id]; }
            });
            var publish = document.getElementById('publish');
            if (publish) { publish.click(); } else { button.disabled = false; }
        });
    });
})();
</script>
        <?php
    }

    /**
     * When a post goes out, its not yet published Polylang translations get the same date and go out too (the same
     * person's publish click, the same language group).
     */
    public function translations($new_status, $old_status, $post)
    {
        if (! self::enabled() || ! function_exists('pll_get_post_translations') || $post->post_type !== 'post' || ! in_array($new_status, ['publish', 'future'], true)
            || in_array($old_status, ['publish', 'future'], true) || ! current_user_can('publish_post', $post->ID) || get_post_meta($post->ID, self::PICKED_META, true) === '') {
            return;
        }
        delete_post_meta($post->ID, self::PICKED_META);
        foreach ((array) pll_get_post_translations($post->ID) as $translation_id) {
            $translation = get_post($translation_id);
            if (! $translation || (int) $translation_id === (int) $post->ID || ! in_array($translation->post_status, ['draft', 'pending'], true)
                || ! current_user_can('publish_post', $translation_id)) {
                continue;
            }
            wp_update_post(['ID' => (int) $translation_id, 'post_date' => $post->post_date, 'post_date_gmt' => $post->post_date_gmt, 'edit_date' => true,
                'post_status' => $new_status]);
        }
    }
}
