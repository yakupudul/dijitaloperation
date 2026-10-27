<?php

defined('ABSPATH') || exit;

/**
 * 1.29.0: one "MoxDOP" menu (yakup 2026-10-27: "her sekme ayrı menü olarak ayarlar altında duruyor"). Every screen of
 * the connector is a tab of it, like the settings of a theme or an SEO plugin: a header with the connection state, a
 * tab row, white cards. The look follows the MoxDOP panel (near-black text, one accent, soft cards, pill tabs).
 * Old addresses (Ayarlar › MoxDOP Connector, Araçlar › MoxDOP yönlendirmeleri …) are sent to the new ones.
 */
final class MoxDOP_Connector_Admin_UI
{
    const MENU = 'moxdop-connector';

    /** Screens moved here from Ayarlar / Araçlar: an old link still opens them. */
    const MOVED = ['moxdop-connector', 'moxdop-ai', 'moxdop-showcase', 'moxdop-redirects', 'moxdop-polylang-pairing'];

    public function register()
    {
        add_action('admin_menu', [$this, 'menu'], 5);
        add_action('admin_menu', [$this, 'order'], 999);
        add_action('admin_page_access_denied', [$this, 'moved']);
        add_action('admin_init', [$this, 'moved_parent']);
        add_action('admin_head', [$this, 'styles']);
        add_filter('admin_body_class', [$this, 'body_class']);
    }

    public function menu()
    {
        $icon = 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 16V4h2.6L10 10.2 14.4 4H17v12h-2.6V8.3L10 14.4 5.6 8.3V16z"/></svg>');
        add_menu_page('MoxDOP', 'MoxDOP', 'manage_options', self::MENU, '__return_null', $icon, 59);
    }

    /** @return array<string, array{label: string, count?: int}> tab page => label, in menu order */
    public static function tabs()
    {
        static $tabs = null;
        if ($tabs !== null) {
            return $tabs;
        }
        $tabs = [
            'moxdop-connector' => ['label' => 'Genel'],
            MoxDOP_Connector_Showcase_Admin::QUESTIONS => ['label' => 'Gelen sorular', 'count' => MoxDOP_Connector_Showcase_Admin::waiting()],
            MoxDOP_Connector_Showcase::PAGE => ['label' => 'Sorular ve yorumlar'],
            MoxDOP_Connector_Showcase_Admin::LOOK => ['label' => 'Görünüm ve metinler'],
            MoxDOP_Connector_AI::PAGE => ['label' => 'Claude / ChatGPT'],
            'moxdop-redirects' => ['label' => 'Yönlendirmeler'],
        ];
        if (MoxDOP_Connector_Drafts::polylang_active()) {
            $tabs[MoxDOP_Connector_Translation_Pairing::PAGE] = ['label' => 'Polylang'];
        }

        return $tabs;
    }

    public static function url($page, array $args = [])
    {
        return add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'));
    }

    /** One submenu entry of the MoxDOP menu. */
    public static function add($page, $title, $callback, $position)
    {
        $tabs = self::tabs();
        $label = $tabs[$page]['label'] ?? $title;
        $count = (int) ($tabs[$page]['count'] ?? 0);
        add_submenu_page(self::MENU, $title.' · MoxDOP', $label.($count > 0 ? ' <span class="awaiting-mod">'.$count.'</span>' : ''), 'manage_options', $page, $callback, $position);
    }

    /**
     * The submenu in tab order, once: WordPress copies the top-level item as a first "MoxDOP" entry when another tab is
     * added before Genel, and add_submenu_page positions depend on what was added earlier.
     */
    public function order()
    {
        global $submenu;
        if (empty($submenu[self::MENU])) {
            return;
        }
        $rank = array_flip(array_keys(self::tabs()));
        $items = [];
        foreach ($submenu[self::MENU] as $item) {
            if (! isset($items[$item[2]]) || $item[0] !== 'MoxDOP') {
                $items[$item[2]] = $item;
            }
        }
        uasort($items, function ($a, $b) use ($rank) {
            return ($rank[$a[2]] ?? 99) <=> ($rank[$b[2]] ?? 99);
        });
        $submenu[self::MENU] = array_values($items);
    }

    /** An old address (options-general.php?page=moxdop-ai, tools.php?page=moxdop-redirects) opens the new one. */
    public function moved()
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (! in_array($page, self::MOVED, true) || ! current_user_can('manage_options')) {
            return;
        }
        $args = array_map('sanitize_text_field', wp_unslash($_GET));
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /** A bookmark under Ayarlar / Araçlar whose slug is still known (the Genel tab) would open there; it moves too. */
    public function moved_parent()
    {
        if (in_array($GLOBALS['pagenow'] ?? '', ['options-general.php', 'tools.php'], true)) {
            $this->moved();
        }
    }

    private static function current()
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return array_key_exists($page, self::tabs()) ? $page : '';
    }

    public function body_class($classes)
    {
        return self::current() !== '' ? $classes.' moxdop-admin' : $classes;
    }

    /**
     * Opens a screen: the header with the connection state, the tabs, the title and one line under it.
     * $card = true puts everything up to close() in one white card (screens with their own sections pass false).
     */
    public static function open($page, $title, $intro = '', $card = false)
    {
        $paired = is_array((new MoxDOP_Connector_Secrets)->read());
        $brand = get_option('moxdop_showcase_enabled') ? 'Sorular ve yorumlar açık' : '';
        echo '<div class="wrap mxa">';
        echo '<div class="mxa-top"><div class="mxa-bar"><span class="mxa-logo" aria-hidden="true">M</span><span class="mxa-name">MoxDOP</span>'
            .'<span class="mxa-ver">'.esc_html(MOXDOP_CONNECTOR_VERSION).'</span>'
            .'<span class="mxa-state'.($paired ? ' is-on' : '').'"><i></i>'.($paired ? 'MoxDOP\'a bağlı' : 'Bağlı değil').'</span>'
            .($brand !== '' ? '<span class="mxa-state is-on"><i></i>'.esc_html($brand).'</span>' : '').'</div>';
        echo '<nav class="mxa-tabs" aria-label="MoxDOP">';
        foreach (self::tabs() as $slug => $tab) {
            $count = (int) ($tab['count'] ?? 0);
            echo '<a href="'.esc_url(self::url($slug)).'" class="mxa-tab'.($slug === $page ? ' is-active' : '').'"'.($slug === $page ? ' aria-current="page"' : '').'>'
                .esc_html($tab['label']).($count > 0 ? '<b>'.$count.'</b>' : '').'</a>';
        }
        echo '</nav></div><div class="mxa-body">';
        echo '<h1 class="mxa-h1">'.wp_kses($title, ['mark' => []]).'</h1>';
        if ($intro !== '') {
            echo '<p class="mxa-intro">'.wp_kses_post($intro).'</p>';
        }
        echo '<hr class="wp-header-end">';
        if ($card) {
            echo '<div class="mxa-card mxa-legacy">';
        }
    }

    public static function close($card = false)
    {
        echo ($card ? '</div>' : '').'</div></div>';
    }

    /** A switch (checkbox) row with a title and one line under it. */
    public static function toggle($name, $checked, $title, $help)
    {
        return '<label class="mxa-toggle"><input type="checkbox" name="'.esc_attr($name).'" value="1"'.checked($checked, true, false).'><span class="mxa-switch" aria-hidden="true"></span>'
            .'<span><b>'.esc_html($title).'</b><small>'.esc_html($help).'</small></span></label>';
    }

    public static function notice($text, $tone = 'ok')
    {
        return '<div class="mxa-notice is-'.esc_attr($tone).'" role="status">'.esc_html($text).'</div>';
    }

    public function styles()
    {
        if (self::current() === '') {
            return;
        }
        echo '<style id="moxdop-admin-css">'
            .'.moxdop-admin #wpcontent{padding-left:0}.moxdop-admin #wpbody-content{padding-bottom:40px}.moxdop-admin .notice:not(.mxa-keep){margin:12px 0}'
            .'.mxa{--ink:#191918;--muted:#787774;--line:#ebebe9;--soft:#f7f7f5;--accent:#fdcf3c;--accent-soft:#fff8e2;--ok:#1d7a46;margin:0;color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:14px}'
            .'.mxa *{box-sizing:border-box}'
            .'.mxa-top{background:#fff;border-bottom:1px solid var(--line);padding:16px 32px 0;position:sticky;top:32px;z-index:5}@media(max-width:782px){.mxa-top{top:0;padding:14px 16px 0;position:static}}'
            .'.mxa-bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px}'
            .'.mxa-logo{width:30px;height:30px;border-radius:9px;background:var(--accent);color:var(--ink);display:grid;place-items:center;font-weight:800;font-size:15px;box-shadow:0 2px 6px rgba(253,207,60,.45)}'
            .'.mxa-name{font-size:16px;font-weight:650;letter-spacing:-.01em}.mxa-ver{font-size:12px;color:var(--muted);border:1px solid var(--line);border-radius:7px;padding:1px 7px}'
            .'.mxa-state{display:inline-flex;align-items:center;gap:7px;height:28px;padding:0 11px;border:1px solid var(--line);border-radius:999px;font-size:13px;color:var(--muted);background:#fff}'
            .'.mxa-state:first-of-type{margin-left:auto}.mxa-state i{width:7px;height:7px;border-radius:50%;background:#a6a5a1}.mxa-state.is-on{color:var(--ink)}.mxa-state.is-on i{background:#22a35a;box-shadow:0 0 0 3px rgba(34,163,90,.15)}'
            .'.mxa-tabs{display:flex;gap:2px;margin-top:12px;overflow-x:auto;scrollbar-width:none}'
            .'.mxa-tab{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:10px 12px;border-bottom:2px solid transparent;color:var(--muted);text-decoration:none;font-weight:500;font-size:14px}'
            .'.mxa-tab:hover{color:var(--ink)}.mxa-tab:focus{box-shadow:none;outline:2px solid var(--accent);outline-offset:-2px;border-radius:6px}.mxa-tab.is-active{color:var(--ink);border-color:var(--ink);font-weight:600}'
            .'.mxa-tab b{min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:var(--accent);color:var(--ink);font-size:12px;display:inline-grid;place-items:center}'
            .'.mxa-body{padding:28px 32px;max-width:1120px}@media(max-width:782px){.mxa-body{padding:20px 16px}}'
            .'.mxa-h1{font-size:26px!important;line-height:1.25!important;font-weight:650!important;letter-spacing:-.03em;margin:0!important;padding:0!important;color:var(--ink)}'
            .'.mxa mark{background:var(--accent);color:var(--ink);border-radius:8px;padding:0 .3em;-webkit-box-decoration-break:clone;box-decoration-break:clone}'
            .'.mxa-intro{margin:6px 0 0;max-width:80ch;color:var(--muted);font-size:14px}'
            .'.mxa-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 1px 2px rgba(20,20,20,.04);padding:22px 24px;margin:18px 0 0}'
            .'.mxa-card>h2,.mxa-sec{font-size:17px;font-weight:600;letter-spacing:-.02em;margin:0 0 4px;color:var(--ink)}.mxa-card>h2+p,.mxa-help{color:var(--muted);margin:0 0 14px;max-width:80ch}'
            .'.mxa-legacy h2{font-size:17px;font-weight:600;margin:24px 0 8px}.mxa-legacy h2:first-child{margin-top:0}.mxa-legacy .widefat{border-color:var(--line);border-radius:10px;overflow:hidden}'
            .'.mxa-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-top:18px}'
            .'.mxa-soft{border-radius:16px;background:var(--soft);padding:16px 18px;transition:transform .15s,box-shadow .15s}.mxa-soft:hover{transform:translateY(-2px);box-shadow:0 10px 30px -12px rgba(20,20,40,.18)}'
            .'.mxa-soft.is-accent{background:var(--accent-soft);border:1px solid #fbe3a0}.mxa-soft small{display:block;color:var(--muted);font-size:13px}.mxa-soft strong{display:block;font-size:26px;font-weight:650;letter-spacing:-.03em;margin:6px 0 2px}'
            .'.mxa-row{display:grid;grid-template-columns:220px minmax(0,1fr);gap:8px 24px;padding:16px 0;border-top:1px solid var(--line)}.mxa-row:first-of-type{border-top:0;padding-top:4px}'
            .'@media(max-width:782px){.mxa-row{grid-template-columns:1fr}}.mxa-row>label,.mxa-row>.mxa-label{font-weight:600;padding-top:7px}.mxa-row small,.mxa-note{display:block;color:var(--muted);font-size:13px;margin-top:6px}'
            .'.mxa input[type=text],.mxa input[type=email],.mxa input[type=url],.mxa input[type=search],.mxa select,.mxa textarea{border:1px solid #d9d9d6;border-radius:8px;min-height:36px;padding:6px 11px;font-size:14px;color:var(--ink);box-shadow:none;max-width:100%}'
            .'.mxa input:focus,.mxa select:focus,.mxa textarea:focus{border-color:var(--ink);box-shadow:0 0 0 3px rgba(25,25,24,.08);outline:0}.mxa .mxa-wide{width:100%}.mxa select{padding-right:32px;min-width:160px}.mxa textarea{line-height:1.5}'
            .'.mxa .button{border-radius:8px;min-height:34px;padding:0 14px;border-color:#d9d9d6;color:var(--ink);background:#fff;font-weight:500;box-shadow:0 1px 2px rgba(20,20,20,.04)}.mxa .button:hover{background:var(--soft);border-color:#c9c9c5;color:var(--ink)}'
            .'.mxa .button-primary,.mxa .mxa-primary{background:var(--accent)!important;border-color:transparent!important;color:var(--ink)!important;font-weight:600}.mxa .button-primary:hover{filter:brightness(.96)}'
            .'.mxa .mxa-dark{background:var(--ink)!important;border-color:var(--ink)!important;color:#fff!important}.mxa .mxa-danger{color:#b42318!important}'
            .'.mxa-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:18px}'
            .'.mxa-toggle{display:flex;gap:12px;align-items:flex-start;padding:14px 0;border-top:1px solid var(--line);cursor:pointer}.mxa-toggle:first-of-type{border-top:0}'
            .'.mxa-toggle input{position:absolute;opacity:0;width:1px;height:1px}.mxa-toggle b{display:block;font-weight:600}.mxa-toggle small{display:block;color:var(--muted);font-size:13px;margin-top:2px;max-width:80ch}'
            .'.mxa-switch{flex:none;width:36px;height:20px;border-radius:999px;background:#d9d9d6;position:relative;margin-top:1px;transition:background .15s}'
            .'.mxa-switch:after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:transform .15s}'
            .'.mxa-toggle input:checked+.mxa-switch{background:var(--ink)}.mxa-toggle input:checked+.mxa-switch:after{transform:translateX(16px)}.mxa-toggle input:focus-visible+.mxa-switch{outline:2px solid var(--accent);outline-offset:2px}'
            .'.mxa-notice{margin:16px 0 0;padding:12px 16px;border-radius:12px;font-weight:500}.mxa-notice.is-ok{background:#eaf6ee;color:#14532d}.mxa-notice.is-warn{background:#fff6e0;color:#7a4b00}.mxa-notice.is-error{background:#fdecea;color:#912018}'
            .'.mxa-pill{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 9px;border-radius:7px;font-size:12px;font-weight:600;background:#f1f1ef;color:#5f5e5b;white-space:nowrap}'
            .'.mxa-pill.is-new{background:var(--accent);color:var(--ink)}.mxa-pill.is-ok{background:#eaf6ee;color:#14532d}.mxa-pill.is-off{background:#f1f1ef;color:#787774}'
            .'.mxa-segs{display:flex;flex-wrap:wrap;gap:6px;margin:18px 0 0}.mxa-seg{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 14px;border:1px solid var(--line);border-radius:999px;background:#fff;color:#5f5e5b;text-decoration:none;font-weight:500}'
            .'.mxa-seg:hover{color:var(--ink)}.mxa-seg.is-active{background:var(--ink);border-color:var(--ink);color:#fff}.mxa-seg b{font-weight:600;color:inherit;opacity:.7}.mxa-seg.is-active b{color:var(--accent);opacity:1}'
            .'.mxa-table{width:100%;border-collapse:collapse;font-size:14px}.mxa-table th{text-align:left;font-weight:500;color:var(--muted);font-size:13px;background:var(--soft);padding:10px 14px;border-bottom:1px solid var(--line)}'
            .'.mxa-table td{padding:12px 14px;border-bottom:1px solid var(--line);vertical-align:top}.mxa-table tr:last-child td{border-bottom:0}.mxa-tablewrap{border:1px solid var(--line);border-radius:14px;overflow:auto;margin-top:14px}'
            .'.mxa code{background:#f1f1ef;border-radius:6px;padding:2px 6px;font-size:12.5px}'
            .'</style>';
    }
}
