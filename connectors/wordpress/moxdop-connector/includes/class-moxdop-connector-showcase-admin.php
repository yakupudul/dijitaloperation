<?php

defined('ABSPATH') || exit;

/**
 * 1.29.0 MoxDOP › Sorular ve yorumlar screens (yakup 2026-10-27):
 * - Gelen sorular: every question asked on the site with the asker's name and contact; the admin answers it here and
 *   the answer is live at once (MoxDOP is told, so it does not prepare one of its own), edits it or takes it down.
 * - Sorular ve yorumlar: the pages, post types and taxonomies that get questions, the notification e-mail, the
 *   question pages and how to place the block.
 * - Görünüm ve metinler: who answers (name, line, photo), accent colour, font, privacy page and every fixed text a
 *   visitor sees.
 */
final class MoxDOP_Connector_Showcase_Admin
{
    const QUESTIONS = 'moxdop-questions';

    const LOOK = 'moxdop-showcase-look';

    const PER_PAGE = 20;

    public function register()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_moxdop_showcase_save', [$this, 'save_settings']);
        add_action('admin_post_moxdop_showcase_look', [$this, 'save_look']);
        add_action('admin_post_moxdop_question', [$this, 'save_question']);
        add_action('admin_enqueue_scripts', [$this, 'scripts']);
    }

    public function menu()
    {
        MoxDOP_Connector_Admin_UI::add(self::QUESTIONS, 'Gelen sorular', [$this, 'render_questions'], 10);
        MoxDOP_Connector_Admin_UI::add(MoxDOP_Connector_Showcase::PAGE, 'Sorular ve yorumlar', [$this, 'render_settings'], 20);
        MoxDOP_Connector_Admin_UI::add(self::LOOK, 'Görünüm ve metinler', [$this, 'render_look'], 30);
    }

    public function scripts($hook)
    {
        if (isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === self::LOOK) {
            wp_enqueue_media();
        }
    }

    /** Questions waiting for an answer (the menu count). */
    public static function waiting()
    {
        return (int) (new WP_Query(['post_type' => MoxDOP_Connector_Showcase::QUESTION_TYPE, 'post_status' => 'private', 'posts_per_page' => 1, 'fields' => 'ids', 'lang' => '',
            'no_found_rows' => false, 'suppress_filters' => true, 'meta_query' => [['key' => '_moxdop_q_status', 'value' => 'new']]]))->found_posts;
    }

    private static function notice_param()
    {
        return isset($_GET['moxdop_notice']) ? sanitize_key(wp_unslash($_GET['moxdop_notice'])) : '';
    }

    /* ------------------------------------------------------------------ Gelen sorular */

    const FILTERS = ['bekleyen' => ['Bekleyen', ['new']], 'yanitlanan' => ['Yanıtlanan', ['answered', 'local']], 'kaldirilan' => ['Kaldırılan', ['hidden']], 'tumu' => ['Tümü', []]];

    /** The answer the site shows for a question now (the admin's, else MoxDOP's) and the question text it shows. @return array{q: string, a: string} */
    private static function shown($question_id)
    {
        $public = (string) get_post_meta($question_id, '_moxdop_q_public', true);
        $answer = (string) get_post_meta($question_id, '_moxdop_q_answer', true);
        if ($answer !== '') {
            return ['q' => $public !== '' ? $public : (string) get_post_field('post_content', $question_id), 'a' => $answer];
        }
        $target = MoxDOP_Connector_Showcase::parse_key((string) get_post_meta($question_id, '_moxdop_q_target', true));
        $qa = $target ? MoxDOP_Connector_Showcase::qa_post($target) : 0;
        $items = $qa ? get_post_meta($qa, MoxDOP_Connector_Showcase::ITEMS_META, true) : [];
        foreach (is_array($items) ? $items : [] as $item) {
            if ((int) ($item['local_id'] ?? 0) === (int) $question_id) {
                return ['q' => (string) $item['q'], 'a' => (string) $item['a']];
            }
        }

        return ['q' => (string) get_post_field('post_content', $question_id), 'a' => ''];
    }

    public function render_questions()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $filter = isset($_GET['durum']) ? sanitize_key(wp_unslash($_GET['durum'])) : 'bekleyen';
        $filter = isset(self::FILTERS[$filter]) ? $filter : 'bekleyen';
        $paged = max(1, isset($_GET['sayfa']) ? (int) $_GET['sayfa'] : 1);
        $base = ['post_type' => MoxDOP_Connector_Showcase::QUESTION_TYPE, 'post_status' => 'private', 'lang' => '', 'suppress_filters' => true];
        $counts = [];
        foreach (self::FILTERS as $key => $row) {
            $counts[$key] = (int) (new WP_Query($base + ['posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $row[1] ? [['key' => '_moxdop_q_status', 'value' => $row[1], 'compare' => 'IN']] : []]))->found_posts;
        }
        $query = new WP_Query($base + ['posts_per_page' => self::PER_PAGE, 'paged' => $paged, 'orderby' => 'date', 'order' => 'DESC',
            'meta_query' => self::FILTERS[$filter][1] ? [['key' => '_moxdop_q_status', 'value' => self::FILTERS[$filter][1], 'compare' => 'IN']] : []]);

        MoxDOP_Connector_Admin_UI::open(self::QUESTIONS, 'Gelen <mark>sorular</mark>', 'Ziyaretçilerin sitede sorduğu sorular. Buradan yazdığın cevap onay beklemeden hemen sitede yayınlanır ve MoxDOP\'a bildirilir; yazmazsan cevabı MoxDOP hazırlar, onaylanınca yayınlanır. Soranın adı ve iletişim bilgisi yalnız burada görünür, sitede ve MoxDOP\'ta hiç görünmez.');
        $messages = ['answered' => 'Cevap yayınlandı.', 'hidden' => 'Soru sitede gösterilmiyor.', 'deleted' => 'Soru silindi.', 'empty' => 'Cevap boş olamaz.'];
        $notice = self::notice_param();
        if (isset($messages[$notice])) {
            echo MoxDOP_Connector_Admin_UI::notice($messages[$notice], $notice === 'empty' ? 'error' : 'ok');
        }
        if (! get_option(MoxDOP_Connector_Showcase::ENABLED_OPTION)) {
            echo MoxDOP_Connector_Admin_UI::notice('Bu hizmet MoxDOP\'ta kapalı: markada "Sitede yorum ve soru göster" açılınca sorular sitede görünür.', 'warn');
        }
        echo '<nav class="mxa-segs">';
        foreach (self::FILTERS as $key => $row) {
            echo '<a class="mxa-seg'.($key === $filter ? ' is-active' : '').'" href="'.esc_url(MoxDOP_Connector_Admin_UI::url(self::QUESTIONS, ['durum' => $key])).'">'.esc_html($row[0]).' <b>'.(int) $counts[$key].'</b></a>';
        }
        echo '</nav>';
        if (! $query->have_posts()) {
            echo '<div class="mxa-card"><p class="mxa-help" style="margin:0">'.($filter === 'bekleyen' ? 'Cevap bekleyen soru yok.' : 'Bu listede soru yok.').'</p></div>';
        }
        $labels = ['new' => ['MoxDOP\'ta cevap hazırlanıyor', 'is-new'], 'answered' => ['Yayında · MoxDOP cevabı', 'is-ok'], 'local' => ['Yayında · senin cevabın', 'is-ok'], 'hidden' => ['Sitede gösterilmiyor', 'is-off']];
        foreach ($query->posts as $post) {
            $id = (int) $post->ID;
            $status = (string) get_post_meta($id, '_moxdop_q_status', true);
            $label = $labels[$status] ?? $labels['new'];
            $shown = self::shown($id);
            $page_url = (string) get_post_meta($id, '_moxdop_q_page_url', true);
            $contact = [];
            foreach (['name' => '', 'email' => 'mailto:', 'phone' => 'tel:'] as $field => $scheme) {
                $value = (string) get_post_meta($id, '_moxdop_q_'.$field, true);
                if ($value !== '') {
                    $contact[] = $scheme !== '' ? '<a href="'.esc_url($scheme.preg_replace('/\s+/', '', $value)).'">'.esc_html($value).'</a>' : '<b>'.esc_html($value).'</b>';
                }
            }
            echo '<div class="mxa-card mxa-q" id="soru-'.$id.'">';
            echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px"><span class="mxa-pill '.esc_attr($label[1]).'">'.esc_html($label[0]).'</span>'
                .'<span class="mxa-note" style="margin:0">'.esc_html(get_the_date('j F Y H:i', $post)).'</span>'
                .($page_url !== '' ? '<a class="mxa-note" style="margin:0" href="'.esc_url($page_url).'" target="_blank" rel="noopener">'.esc_html((string) get_post_meta($id, '_moxdop_q_page_title', true)).' ↗</a>' : '').'</div>';
            echo '<p style="font-size:16px;font-weight:600;margin:0 0 6px;white-space:pre-line">'.esc_html((string) $post->post_content).'</p>';
            echo '<p class="mxa-note" style="margin:0 0 14px">Soran: '.($contact ? implode(' · ', $contact) : 'iletişim bırakmadı').' <span>(sitede görünmez)</span></p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="moxdop_question"><input type="hidden" name="question_id" value="'.$id.'">'
                .'<input type="hidden" name="back" value="'.esc_attr(MoxDOP_Connector_Admin_UI::url(self::QUESTIONS, ['durum' => $filter, 'sayfa' => $paged])).'">';
            wp_nonce_field('moxdop_question_'.$id);
            echo '<div class="mxa-row"><label for="q-'.$id.'">Sitede görünecek soru</label><div><input type="text" class="mxa-wide" id="q-'.$id.'" name="public" maxlength="300" value="'.esc_attr($shown['q']).'"><small>Ad, telefon ya da kişisel ayrıntı varsa buradan çıkar.</small></div></div>';
            echo '<div class="mxa-row"><label for="a-'.$id.'">Cevap</label><div><textarea class="mxa-wide" id="a-'.$id.'" name="answer" rows="4" maxlength="4000" placeholder="Cevabını yaz; kaydedince hemen sitede yayınlanır.">'.esc_textarea($shown['a']).'</textarea></div></div>';
            echo '<div class="mxa-actions"><button type="submit" name="do" value="answer" class="button button-primary">'.($shown['a'] !== '' ? 'Cevabı güncelle' : 'Cevabı yayınla').'</button>';
            if ($status !== 'hidden' && $status !== 'new') {
                echo '<button type="submit" name="do" value="hide" class="button">Sitede gösterme</button>';
            }
            echo '<button type="submit" name="do" value="delete" class="button mxa-danger" onclick="return confirm(\'Soru silinsin mi? Sitede ve MoxDOP\\\'ta görünmez.\');">Sil</button></div></form>';
            echo '</div>';
        }
        if ($query->max_num_pages > 1) {
            echo '<nav class="mxa-segs">';
            for ($i = 1; $i <= $query->max_num_pages; $i++) {
                echo '<a class="mxa-seg'.($i === $paged ? ' is-active' : '').'" href="'.esc_url(MoxDOP_Connector_Admin_UI::url(self::QUESTIONS, ['durum' => $filter, 'sayfa' => $i])).'">'.$i.'</a>';
            }
            echo '</nav>';
        }
        MoxDOP_Connector_Admin_UI::close();
    }

    public function save_question()
    {
        $id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;
        check_admin_referer('moxdop_question_'.$id);
        if (! current_user_can('manage_options') || get_post_type($id) !== MoxDOP_Connector_Showcase::QUESTION_TYPE) {
            wp_die('Bu soruyu yalnız site yöneticisi yanıtlar.');
        }
        $back = isset($_POST['back']) ? esc_url_raw(wp_unslash($_POST['back'])) : MoxDOP_Connector_Admin_UI::url(self::QUESTIONS);
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $key = (string) get_post_meta($id, '_moxdop_q_target', true);
        $target = MoxDOP_Connector_Showcase::parse_key($key);
        $qa = $target ? MoxDOP_Connector_Showcase::qa_post($target, true) : 0;
        $qa_url = $qa ? (string) get_permalink($qa) : '';
        $page_title = (string) get_post_meta($id, '_moxdop_q_page_title', true);
        $page_url = (string) get_post_meta($id, '_moxdop_q_page_url', true);
        if ($do === 'answer') {
            $public = sanitize_text_field(wp_unslash((string) ($_POST['public'] ?? '')));
            $answer = sanitize_textarea_field(wp_unslash((string) ($_POST['answer'] ?? '')));
            if (trim($answer) === '') {
                wp_safe_redirect(add_query_arg('moxdop_notice', 'empty', $back).'#soru-'.$id);
                exit;
            }
            $public = $public !== '' ? $public : (string) get_post_field('post_content', $id);
            update_post_meta($id, '_moxdop_q_public', $public);
            update_post_meta($id, '_moxdop_q_answer', $answer);
            update_post_meta($id, '_moxdop_q_status', 'local');
            update_post_meta($id, '_moxdop_q_answered_by', get_current_user_id());
            update_post_meta($id, '_moxdop_q_answered_at', gmdate('c'));
            MoxDOP_Connector_Events::answered($id, $key, $page_title, $page_url, $public, $answer);
            MoxDOP_Connector_Showcase::notify_asker($id, $public, $answer, $qa_url);
            $notice = 'answered';
        } elseif ($do === 'hide' || $do === 'delete') {
            update_post_meta($id, '_moxdop_q_status', 'hidden');
            MoxDOP_Connector_Events::hidden($id, $key, $page_title, $page_url);
            if ($do === 'delete') {
                wp_trash_post($id);
            }
            $notice = $do === 'delete' ? 'deleted' : 'hidden';
        } else {
            wp_safe_redirect($back);
            exit;
        }
        if ($qa) {
            MoxDOP_Connector_Showcase::refresh_index($qa);
            MoxDOP_Connector_Showcase::purge_urls(array_filter([$qa_url, $target ? MoxDOP_Connector_Showcase::target_url($target) : '']));
        }
        wp_safe_redirect(add_query_arg('moxdop_notice', $notice, $back).($do === 'answer' ? '#soru-'.$id : ''));
        exit;
    }

    /* ------------------------------------------------------------------ Sorular ve yorumlar (assignment) */

    /** @return array<string, string> public post types an admin can assign (not attachments, not ours) */
    private static function assignable_types()
    {
        $out = [];
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if (! in_array($type->name, ['attachment', MoxDOP_Connector_Showcase::QA_TYPE, MoxDOP_Connector_Showcase::QUESTION_TYPE, 'elementor_library', 'e-landing-page'], true)) {
                $out[$type->name] = $type->labels->name;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function assignable_taxonomies()
    {
        $out = [];
        foreach (get_taxonomies(['public' => true], 'objects') as $tax) {
            if (! in_array($tax->name, ['post_format', 'elementor_library_type', 'language', 'post_translations', 'term_language', 'term_translations'], true)) {
                $out[$tax->name] = $tax->labels->name;
            }
        }

        return $out;
    }

    public function save_settings()
    {
        check_admin_referer('moxdop_showcase_save');
        if (! current_user_can('manage_options')) {
            wp_die('Bu ayarı yalnız site yöneticisi değiştirir.');
        }
        $in = wp_unslash($_POST);
        update_option(MoxDOP_Connector_Showcase::TARGETS_OPTION, [
            'pages' => array_values(array_filter(array_map('intval', (array) ($in['pages'] ?? [])))),
            'post_types' => array_values(array_intersect(array_map('sanitize_key', (array) ($in['post_types'] ?? [])), array_keys(self::assignable_types()))),
            'taxonomies' => array_values(array_intersect(array_map('sanitize_key', (array) ($in['taxonomies'] ?? [])), array_keys(self::assignable_taxonomies()))),
        ], false);
        $email = sanitize_email((string) ($in['notify'] ?? ''));
        update_option(MoxDOP_Connector_Showcase::NOTIFY_OPTION, is_email($email) ? $email : '', false);
        $made = 0;
        foreach (MoxDOP_Connector_Showcase::targets() as $target) {
            $made += MoxDOP_Connector_Showcase::qa_post($target, true) ? 1 : 0;
        }
        wp_safe_redirect(MoxDOP_Connector_Admin_UI::url(MoxDOP_Connector_Showcase::PAGE, ['moxdop_notice' => 'saved', 'made' => $made]));
        exit;
    }

    public function render_settings()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $set = MoxDOP_Connector_Showcase::assignment();
        $enabled = (bool) get_option(MoxDOP_Connector_Showcase::ENABLED_OPTION);
        MoxDOP_Connector_Admin_UI::open(MoxDOP_Connector_Showcase::PAGE, 'Sorular ve <mark>yorumlar</mark>', 'Seçtiğin sayfalarda ziyaretçiler soru sorar, cevaplar sayfada yayınlanır; Google yorumları da istediğin yere gelir. Her seçili sayfanın kendi soru sayfası olur: <code>sayfa-adresi/'.MoxDOP_Connector_Showcase::SLUG.'/</code>.');
        if (self::notice_param() === 'saved') {
            echo MoxDOP_Connector_Admin_UI::notice('Kaydedildi. Soru sayfası olan sayfa: '.(isset($_GET['made']) ? (int) $_GET['made'] : 0).'.');
        }
        $targets = MoxDOP_Connector_Showcase::targets();
        $answered = 0;
        foreach ($targets as $target) {
            $qa = MoxDOP_Connector_Showcase::qa_post($target);
            $answered += $qa ? count(MoxDOP_Connector_Showcase::merged_items($qa, MoxDOP_Connector_Showcase::key($target))) : 0;
        }
        $reviews = get_option(MoxDOP_Connector_Showcase::REVIEWS_OPTION, []);
        echo '<div class="mxa-grid">';
        echo '<div class="mxa-soft'.($enabled ? ' is-accent' : '').'"><small>MoxDOP\'ta</small><strong>'.($enabled ? 'Açık' : 'Kapalı').'</strong><small>'.($enabled ? 'Yorumlar ve cevaplar her gün gelir' : 'Markada "Sitede yorum ve soru göster" ile açılır').'</small></div>';
        echo '<div class="mxa-soft"><small>Soru sayfası</small><strong>'.count($targets).'</strong><small>Seçili sayfa sayısı</small></div>';
        echo '<div class="mxa-soft"><small>Yayındaki soru</small><strong>'.(int) $answered.'</strong><small><a href="'.esc_url(MoxDOP_Connector_Admin_UI::url(self::QUESTIONS)).'">'.self::waiting().' soru cevap bekliyor</a></small></div>';
        echo '<div class="mxa-soft"><small>Google yorumu</small><strong>'.(int) count((array) ($reviews['items'] ?? [])).'</strong><small>'.(! empty($reviews['count']) ? esc_html(number_format_i18n((float) $reviews['rating'], 1).' puan · '.(int) $reviews['count'].' yorum') : 'Henüz gelmedi').'</small></div>';
        echo '</div>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="moxdop_showcase_save">';
        wp_nonce_field('moxdop_showcase_save');
        echo '<div class="mxa-card"><h2>Hangi sayfalarda soru sorulsun?</h2><p>Bir sayfa, bir yazı türünün bütün kayıtları ya da kategori sayfaları seçilebilir.</p>';
        echo '<div class="mxa-row"><span class="mxa-label">Sayfalar</span><div><input type="search" class="mxa-wide" placeholder="Sayfa ara" oninput="var q=this.value.toLocaleLowerCase(\'tr\');this.nextElementSibling.querySelectorAll(\'label\').forEach(function(l){l.style.display=l.textContent.toLocaleLowerCase(\'tr\').indexOf(q)<0?\'none\':\'\'})">'
            .'<div style="max-height:280px;overflow:auto;border:1px solid #ebebe9;border-radius:10px;padding:6px 12px;margin-top:8px">';
        foreach (get_pages(['sort_column' => 'menu_order,post_title', 'lang' => '']) as $page) {
            echo '<label style="display:flex;gap:8px;align-items:center;padding:6px 0"><input type="checkbox" name="pages[]" value="'.(int) $page->ID.'"'.checked(in_array((int) $page->ID, $set['pages'], true), true, false).'> '
                .esc_html(str_repeat('— ', count(get_post_ancestors($page))).$page->post_title).'</label>';
        }
        echo '</div></div></div>';
        echo '<div class="mxa-row"><span class="mxa-label">Yazı türleri</span><div>';
        foreach (self::assignable_types() as $name => $label) {
            echo MoxDOP_Connector_Admin_UI::toggle('post_types[]', in_array($name, $set['post_types'], true), $label, 'Bu türün yayındaki her kaydı soru alır ('.$name.').');
        }
        echo '</div></div><div class="mxa-row"><span class="mxa-label">Sınıflandırmalar</span><div>';
        foreach (self::assignable_taxonomies() as $name => $label) {
            echo MoxDOP_Connector_Admin_UI::toggle('taxonomies[]', in_array($name, $set['taxonomies'], true), $label, 'Her '.mb_strtolower($label).' sayfası soru alır ('.$name.').');
        }
        echo '</div></div></div>';
        echo '<div class="mxa-card"><h2>Yeni soru bildirimi</h2><p>Yeni soru bu adrese, sitenin kendi e-posta (SMTP) ayarıyla gider. Boş bırakılabilir; sorular her durumda Gelen sorular sekmesinde görünür.</p>';
        echo '<div class="mxa-row"><label for="mxs-notify">E-posta</label><div><input type="email" id="mxs-notify" name="notify" class="mxa-wide" value="'.esc_attr((string) get_option(MoxDOP_Connector_Showcase::NOTIFY_OPTION)).'" placeholder="ornek@site.com"></div></div>';
        echo '<div class="mxa-actions"><button type="submit" class="button button-primary">Kaydet</button></div></div></form>';
        // The fields of the toggles above post "post_types[]" with value 1; the real value is its name.
        echo '<script>document.querySelectorAll(\'.mxa input[name="post_types[]"],.mxa input[name="taxonomies[]"]\').forEach(function(i){var c=i.closest(".mxa-toggle").querySelector("small").textContent.match(/\(([^()]+)\)\.$/);if(c)i.value=c[1]});</script>';

        echo '<div class="mxa-card"><h2>Sayfaya nasıl eklenir?</h2><p>Blok kendiliğinden eklenmez; nerede görüneceğine sen karar verirsin.</p><div class="mxa-grid" style="margin-top:4px">';
        echo '<div class="mxa-soft"><small>Elementor</small><b style="display:block;margin:6px 0 4px">MoxDOP Sizden gelenler</b><small>Parçacığı sürükle; Sorular, Google yorumları ya da Google puanı rozeti seç.</small></div>';
        echo '<div class="mxa-soft"><small>Sorular</small><code style="display:inline-block;margin:8px 0 4px">[moxdop_sorular]</code><small>Bulunduğu sayfanın soruları. <code>sayi="6"</code> ile sayı.</small></div>';
        echo '<div class="mxa-soft"><small>Yorumlar</small><code style="display:inline-block;margin:8px 0 4px">[moxdop_yorumlar]</code><small>Rozet için <code>gorunum="rozet"</code>.</small></div>';
        echo '</div></div>';

        if ($targets) {
            echo '<div class="mxa-card"><h2>Soru sayfaları <span style="color:#787774;font-weight:400">'.count($targets).'</span></h2><p>Google\'a '.MoxDOP_Connector_Showcase::INDEX_AT.' cevaplı sorudan sonra açılır; o zamana kadar noindex.</p><div class="mxa-tablewrap"><table class="mxa-table"><thead><tr><th>Sayfa</th><th>Soru sayfası</th><th>Yayında</th></tr></thead><tbody>';
            foreach (array_slice($targets, 0, 200) as $target) {
                $qa = MoxDOP_Connector_Showcase::qa_post($target);
                $n = $qa ? count(MoxDOP_Connector_Showcase::merged_items($qa, MoxDOP_Connector_Showcase::key($target))) : 0;
                echo '<tr><td>'.esc_html(MoxDOP_Connector_Showcase::target_title($target)).'</td><td>'.($qa ? '<a href="'.esc_url(get_permalink($qa)).'" target="_blank" rel="noopener">'.esc_html((string) wp_parse_url((string) get_permalink($qa), PHP_URL_PATH)).'</a>' : '—').'</td>'
                    .'<td><span class="mxa-pill '.($n >= MoxDOP_Connector_Showcase::INDEX_AT ? 'is-ok' : 'is-off').'">'.(int) $n.' soru'.($n < MoxDOP_Connector_Showcase::INDEX_AT ? ' · noindex' : '').'</span></td></tr>';
            }
            echo '</tbody></table></div></div>';
        }
        MoxDOP_Connector_Admin_UI::close();
    }

    /* ------------------------------------------------------------------ Görünüm ve metinler */

    public function save_look()
    {
        check_admin_referer('moxdop_showcase_look');
        if (! current_user_can('manage_options')) {
            wp_die('Bu ayarı yalnız site yöneticisi değiştirir.');
        }
        $in = wp_unslash($_POST);
        if (! empty($in['reset_texts'])) {
            delete_option(MoxDOP_Connector_Showcase::TEXTS_OPTION);
        } else {
            $texts = [];
            foreach (MoxDOP_Connector_Showcase::text_groups() as $fields) {
                foreach ($fields as $key => $field) {
                    $value = trim(sanitize_textarea_field((string) ($in['texts'][$key] ?? '')));
                    if ($value !== '' && $value !== $field[1]) {
                        $texts[$key] = mb_substr($value, 0, 2000);
                    }
                }
            }
            update_option(MoxDOP_Connector_Showcase::TEXTS_OPTION, $texts, true);
        }
        update_option(MoxDOP_Connector_Showcase::LOOK_OPTION, [
            'name' => sanitize_text_field((string) ($in['name'] ?? '')),
            'role' => sanitize_text_field((string) ($in['role'] ?? '')),
            'photo' => (int) ($in['photo'] ?? 0),
            'accent' => (string) sanitize_hex_color((string) ($in['accent'] ?? '')),
            'font' => sanitize_text_field((string) ($in['font'] ?? '')),
            'privacy_page' => (int) ($in['privacy_page'] ?? 0),
        ], true);
        MoxDOP_Connector_Showcase::purge_urls([home_url('/')]);
        wp_safe_redirect(MoxDOP_Connector_Admin_UI::url(self::LOOK, ['moxdop_notice' => 'saved']));
        exit;
    }

    public function render_look()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $saved = get_option(MoxDOP_Connector_Showcase::LOOK_OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $texts = get_option(MoxDOP_Connector_Showcase::TEXTS_OPTION, []);
        $texts = is_array($texts) ? $texts : [];
        $look = MoxDOP_Connector_Showcase::look();
        $photo = (int) ($saved['photo'] ?? 0);
        $photo_url = $photo > 0 ? (string) wp_get_attachment_image_url($photo, 'thumbnail') : '';
        $preview = '';
        foreach (MoxDOP_Connector_Showcase::targets() as $target) {
            $qa = MoxDOP_Connector_Showcase::qa_post($target);
            if ($qa) {
                $preview = (string) get_permalink($qa);
                break;
            }
        }
        MoxDOP_Connector_Admin_UI::open(self::LOOK, 'Görünüm ve <mark>metinler</mark>', 'Soru bölümünde, soru sayfasında ve yorumlarda ziyaretçinin gördüğü her şey. Boş bıraktığın alan varsayılanı (gri yazı) kullanır.');
        if (self::notice_param() === 'saved') {
            echo MoxDOP_Connector_Admin_UI::notice('Kaydedildi. Sayfa önbelleği temizlendi.');
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="moxdop_showcase_look">';
        wp_nonce_field('moxdop_showcase_look');

        echo '<div class="mxa-card"><h2>Yanıt veren</h2><p>Her cevabın başında görünür.</p>';
        echo '<div class="mxa-row"><label for="mxs-name">Ad</label><div><input type="text" id="mxs-name" name="name" class="mxa-wide" value="'.esc_attr((string) ($saved['name'] ?? '')).'" placeholder="'.esc_attr(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)).'"><small>Örneğin "Dt. Ayşe Yılmaz" ya da "Klinik ekibimiz".</small></div></div>';
        echo '<div class="mxa-row"><label for="mxs-role">Altındaki satır</label><div><input type="text" id="mxs-role" name="role" class="mxa-wide" value="'.esc_attr((string) ($saved['role'] ?? '')).'" placeholder="Uzman ekibimiz"><small>Unvan ya da görev.</small></div></div>';
        echo '<div class="mxa-row"><span class="mxa-label">Fotoğraf</span><div style="display:flex;gap:12px;align-items:center"><input type="hidden" name="photo" id="mxs-photo" value="'.(int) $photo.'">'
            .'<span id="mxs-photo-view" style="width:56px;height:56px;border-radius:14px;background:'.esc_attr($look['accent']).' center/cover;display:grid;place-items:center;font-weight:700;font-size:20px;color:'.esc_attr($look['ink']).';'.($photo_url !== '' ? 'background-image:url('.esc_url($photo_url).')' : '').'">'.($photo_url === '' ? esc_html(mb_strtoupper(mb_substr($look['name'], 0, 1))) : '').'</span>'
            .'<button type="button" class="button" id="mxs-photo-pick">Fotoğraf seç</button><button type="button" class="button" id="mxs-photo-clear"'.($photo > 0 ? '' : ' hidden').'>Kaldır</button></div></div>';
        echo '</div>';

        echo '<div class="mxa-card"><h2>Renk ve yazı tipi</h2><p>Boşsa sitenin Elementor ayarlarındaki ana renk ve yazı tipi kullanılır.</p>';
        echo '<div class="mxa-row"><label for="mxs-accent">Vurgu rengi</label><div style="display:flex;gap:10px;align-items:center"><input type="color" value="'.esc_attr($look['accent']).'" oninput="document.getElementById(\'mxs-accent\').value=this.value" style="width:44px;height:36px;padding:2px;border-radius:8px;border:1px solid #d9d9d6">'
            .'<input type="text" id="mxs-accent" name="accent" value="'.esc_attr((string) ($saved['accent'] ?? '')).'" placeholder="'.esc_attr($look['accent']).'" style="width:140px"><small style="margin:0">Butonlar ve başlıktaki işaretli kelime. Yazı rengi kendiliğinden siyah ya da beyaz seçilir.</small></div></div>';
        echo '<div class="mxa-row"><label for="mxs-font">Yazı tipi</label><div><input type="text" id="mxs-font" name="font" class="mxa-wide" value="'.esc_attr((string) ($saved['font'] ?? '')).'" placeholder="'.esc_attr($look['font'] !== '' ? $look['font'].' (sitenin yazı tipi)' : 'Sistem yazı tipi').'"><small>Sitede yüklü bir yazı tipinin adı, örneğin "Poppins". Boşsa sitenin yazı tipi, o da yoksa temiz bir sistem yazı tipi.</small></div></div>';
        echo '<div class="mxa-row"><label for="mxs-privacy">Aydınlatma metni sayfası</label><div><select id="mxs-privacy" name="privacy_page"><option value="0">WordPress gizlilik sayfası</option>';
        foreach (get_pages(['sort_column' => 'post_title', 'lang' => '']) as $page) {
            echo '<option value="'.(int) $page->ID.'"'.selected((int) ($saved['privacy_page'] ?? 0), (int) $page->ID, false).'>'.esc_html($page->post_title).'</option>';
        }
        echo '</select><small>Soru formundaki onay kutusunun yanında bağlantı olarak görünür.</small></div></div>';
        echo '</div>';

        foreach (MoxDOP_Connector_Showcase::text_groups() as $group => $fields) {
            echo '<div class="mxa-card"><h2>'.esc_html($group).'</h2>';
            if ($group === 'Soru bölümü') {
                echo '<p>Başlıkta <code>*kelime*</code> yazarsan o kelime vurgu rengiyle işaretlenir. <code>{n}</code> sayının, <code>{sayfa}</code> sayfa adının yerine geçer.</p>';
            } elseif ($group === 'Soruyu sorana giden e-posta') {
                echo '<p><code>{soru}</code>, <code>{cevap}</code>, <code>{link}</code> (soru sayfası) ve <code>{site}</code> yerine gerçek değer yazılır.</p>';
            }
            foreach ($fields as $key => $field) {
                $value = (string) ($texts[$key] ?? '');
                $long = mb_strlen($field[1]) > 90 || strpos($field[1], "\n") !== false;
                echo '<div class="mxa-row"><label for="t-'.esc_attr($key).'">'.esc_html($field[0]).'</label><div>'
                    .($long ? '<textarea class="mxa-wide" id="t-'.esc_attr($key).'" name="texts['.esc_attr($key).']" rows="'.(strpos($field[1], "\n") !== false ? 7 : 2).'" placeholder="'.esc_attr($field[1]).'">'.esc_textarea($value).'</textarea>'
                        : '<input type="text" class="mxa-wide" id="t-'.esc_attr($key).'" name="texts['.esc_attr($key).']" value="'.esc_attr($value).'" placeholder="'.esc_attr($field[1]).'">')
                    .'</div></div>';
            }
            echo '</div>';
        }
        echo '<div class="mxa-actions" style="position:sticky;bottom:0;background:linear-gradient(transparent,#f0f0f1 30%);padding:18px 0 6px"><button type="submit" class="button button-primary">Kaydet</button>'
            .($preview !== '' ? '<a class="button" href="'.esc_url($preview).'" target="_blank" rel="noopener">Soru sayfasında gör ↗</a>' : '')
            .'<button type="submit" name="reset_texts" value="1" class="button mxa-danger" onclick="return confirm(\'Bütün metinler varsayılana dönsün mü?\');">Metinleri varsayılana döndür</button></div></form>';
        echo '<script>(function(){var p=document.getElementById("mxs-photo-pick"),c=document.getElementById("mxs-photo-clear"),i=document.getElementById("mxs-photo"),v=document.getElementById("mxs-photo-view"),f;'
            .'p.addEventListener("click",function(){if(!window.wp||!wp.media)return;f=f||wp.media({title:"Yanıt verenin fotoğrafı",library:{type:"image"},multiple:false});'
            .'f.off("select").on("select",function(){var a=f.state().get("selection").first().toJSON();i.value=a.id;v.style.backgroundImage="url("+((a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url)+")";v.textContent="";c.hidden=false});f.open()});'
            .'c.addEventListener("click",function(){i.value=0;v.style.backgroundImage="";c.hidden=true})})();</script>';
        MoxDOP_Connector_Admin_UI::close();
    }
}
