<?php
defined('ABSPATH') || exit;

/**
 * Everything a clean WordPress needs to run the whole Moximu site with this theme alone: the client (vaka) post type,
 * SEO tags when no SEO plugin is active, tracking codes from Moximu ayarları, text pages, hub grids, calculators and
 * the newsletter form.
 */

// Clients: one record per clinic; a record with sections is its case page at /sonuclar/<slug>/.
add_action('init', static function (): void {
    register_post_type('musteri', [
        'labels' => ['name' => 'Müşteriler', 'singular_name' => 'Müşteri', 'add_new_item' => 'Yeni müşteri', 'edit_item' => 'Müşteriyi düzenle', 'menu_name' => 'Müşteriler'],
        'public' => true, 'show_in_rest' => true, 'has_archive' => false, 'menu_icon' => 'dashicons-groups', 'menu_position' => 21,
        'supports' => ['title', 'excerpt', 'thumbnail', 'page-attributes'], 'rewrite' => ['slug' => 'sonuclar', 'with_front' => false],
    ]);
    add_post_type_support('page', 'excerpt');
});

/** True when an SEO plugin prints title, description and canonical itself. */
function mx_seo_plugin(): bool
{
    return defined('SEOPRESS_VERSION') || defined('RANK_MATH_VERSION') || defined('WPSEO_VERSION') || defined('AIOSEO_VERSION');
}

function mx_seo_value(int $id, string $kind): string
{
    $keys = $kind === 'title' ? ['_mx_seo_title', '_seopress_titles_title'] : ['_mx_seo_desc', '_seopress_titles_desc'];
    foreach ($keys as $key) {
        $value = trim((string) get_post_meta($id, $key, true));
        if ($value !== '') {
            return $value;
        }
    }
    if ($kind === 'desc') {
        $post = get_post($id);
        $text = $post ? ($post->post_excerpt !== '' ? $post->post_excerpt : wp_strip_all_tags(strip_shortcodes($post->post_content))) : '';

        return trim(wp_html_excerpt(preg_replace('/\s+/', ' ', $text), 158, ''));
    }

    return '';
}

add_filter('pre_get_document_title', static function (string $title): string {
    if (mx_seo_plugin() || ! is_singular()) {
        return $title;
    }
    $own = mx_seo_value(get_queried_object_id(), 'title');

    return $own !== '' ? $own : $title;
}, 20);

add_filter('document_title_separator', static fn (): string => '·');

add_action('wp_head', static function (): void {
    if (mx_seo_plugin()) {
        return;
    }
    $desc = '';
    $url = '';
    $image = '';
    $noindex = false;
    if (is_singular()) {
        $id = get_queried_object_id();
        $desc = mx_seo_value($id, 'desc');
        $url = (string) get_permalink($id);
        $noindex = get_post_meta($id, '_mx_noindex', true) === '1' || get_post_meta($id, '_seopress_robots_index', true) === 'yes';
        $image = (string) get_the_post_thumbnail_url($id, 'large');
    } elseif (is_home()) {
        $blog = (int) get_option('page_for_posts');
        $desc = $blog ? mx_seo_value($blog, 'desc') : '';
        $url = $blog ? (string) get_permalink($blog) : home_url('/');
        if (is_paged()) {
            $url = (string) get_pagenum_link(max(1, (int) get_query_var('paged')));
        }
    } elseif (is_category()) {
        $desc = trim(wp_strip_all_tags(category_description()));
        $url = (string) get_category_link(get_queried_object_id());
    } elseif (is_search() || is_404()) {
        $noindex = true;
    }
    if ($image === '') {
        $image = MX_URI.'/assets/i/og.png';
    }
    if ($noindex) {
        echo '<meta name="robots" content="noindex,follow">'."\n";
    }
    if ($desc !== '') {
        echo '<meta name="description" content="'.esc_attr($desc).'">'."\n";
    }
    if ($url !== '') {
        echo '<link rel="canonical" href="'.esc_url($url).'">'."\n";
    }
    $title = wp_get_document_title();
    echo '<meta property="og:type" content="'.(is_singular('post') ? 'article' : 'website').'">'."\n";
    echo '<meta property="og:site_name" content="Moximu">'."\n";
    echo '<meta property="og:locale" content="tr_TR">'."\n";
    echo '<meta property="og:title" content="'.esc_attr($title).'">'."\n";
    if ($desc !== '') {
        echo '<meta property="og:description" content="'.esc_attr($desc).'">'."\n";
    }
    if ($url !== '') {
        echo '<meta property="og:url" content="'.esc_url($url).'">'."\n";
    }
    echo '<meta property="og:image" content="'.esc_url($image).'">'."\n";
    echo '<meta name="twitter:card" content="summary_large_image">'."\n";
}, 2);

// WordPress's own sitemap stays; noindexed pages and private records leave it.
add_filter('wp_sitemaps_posts_query_args', static function (array $args): array {
    $args['meta_query'] = [['relation' => 'OR', ['key' => '_mx_noindex', 'compare' => 'NOT EXISTS'], ['key' => '_mx_noindex', 'value' => '1', 'compare' => '!=']]];

    return $args;
});
add_filter('wp_sitemaps_add_provider', static fn ($provider, string $name) => $name === 'users' ? false : $provider, 10, 2);
add_filter('wp_sitemaps_taxonomies', static function (array $taxonomies): array {
    unset($taxonomies['post_tag'], $taxonomies['post_format']);

    return $taxonomies;
});

// SEO box on pages, posts and clients (only when no SEO plugin is active).
add_action('add_meta_boxes', static function (): void {
    if (mx_seo_plugin()) {
        return;
    }
    add_meta_box('mx_seo', 'Google görünümü', static function (WP_Post $post): void {
        wp_nonce_field('mx_seo', 'mx_seo_nonce');
        $title = (string) get_post_meta($post->ID, '_mx_seo_title', true);
        $desc = (string) get_post_meta($post->ID, '_mx_seo_desc', true);
        $noindex = get_post_meta($post->ID, '_mx_noindex', true) === '1';
        echo '<p><label for="mx_seo_title"><b>Başlık</b> (60 karakter)</label><input class="widefat" id="mx_seo_title" maxlength="70" name="mx_seo_title" value="'.esc_attr($title).'"></p>';
        echo '<p><label for="mx_seo_desc"><b>Açıklama</b> (155 karakter)</label><textarea class="widefat" id="mx_seo_desc" maxlength="180" name="mx_seo_desc" rows="3">'.esc_textarea($desc).'</textarea></p>';
        echo '<p><label><input name="mx_noindex" type="checkbox" value="1"'.checked($noindex, true, false).'> Google\'da gösterme (noindex)</label></p>';
    }, ['page', 'post', 'musteri'], 'normal', 'high');
});

add_action('save_post', static function (int $post_id): void {
    if (! isset($_POST['mx_seo_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mx_seo_nonce'])), 'mx_seo') || ! current_user_can('edit_post', $post_id)) {
        return;
    }
    update_post_meta($post_id, '_mx_seo_title', sanitize_text_field(wp_unslash((string) ($_POST['mx_seo_title'] ?? ''))));
    update_post_meta($post_id, '_mx_seo_desc', sanitize_textarea_field(wp_unslash((string) ($_POST['mx_seo_desc'] ?? ''))));
    update_post_meta($post_id, '_mx_noindex', empty($_POST['mx_noindex']) ? '0' : '1');
});

/*
 * Tracking codes from Moximu ayarları › Takip kodları. They are printed as normal tags; speed.php turns them into
 * "start after the first tap or 7 s" scripts, so they never slow the first paint.
 */
add_action('wp_head', static function (): void {
    if (! function_exists('get_field') || is_user_logged_in()) {
        return;
    }
    $gtm = preg_replace('/[^A-Z0-9-]/', '', strtoupper((string) get_field('gtm_id', 'option')));
    $clarity = preg_replace('/[^a-z0-9]/', '', strtolower((string) get_field('clarity_id', 'option')));
    $pixel = preg_replace('/\D/', '', (string) get_field('meta_pixel_id', 'option'));
    if ($gtm === '' && $clarity === '' && $pixel === '') {
        return;
    }
    // Google Consent Mode v2: everything denied until the visitor accepts in the cookie banner (mx_consent cookie).
    echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}(function(){var a=/(?:^|; )mx_consent=all/.test(document.cookie)?'granted':'denied';gtag('consent','default',{ad_storage:a,ad_user_data:a,ad_personalization:a,analytics_storage:a,functionality_storage:'granted',security_storage:'granted',wait_for_update:500})})();</script>\n";
    if ($gtm !== '') {
        echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','".esc_js($gtm)."');</script>\n";
    }
    if ($clarity !== '') {
        echo '<script data-consent>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script","'.esc_js($clarity).'");</script>'."\n";
    }
    if ($pixel !== '') {
        echo "<script data-consent>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','".esc_js($pixel)."');fbq('track','PageView');</script>\n";
    }
}, 30);

/** A row of a layout as it stands on the home page (or any seeded page), for sections shared by text pages. */
function mx_shared_row(string $layout): array
{
    static $cache = [];
    if (isset($cache[$layout])) {
        return $cache[$layout];
    }
    $about = get_page_by_path('hakkimizda');
    $ids = array_filter([(int) get_option('page_on_front'), (int) get_option('mx_shared_source'), $about ? (int) $about->ID : 0]);
    foreach ($ids as $id) {
        foreach ((array) (function_exists('get_field') ? get_field('bolumler', $id) : []) as $row) {
            if (($row['acf_fc_layout'] ?? '') === $layout) {
                return $cache[$layout] = $row;
            }
        }
    }

    return $cache[$layout] = [];
}

/** Prints one shared section (analiz, sonuclar, yorum_ozet) under a text page. */
function mx_shared_section(string $layout): void
{
    $d = mx_shared_row($layout);
    $file = MX_DIR.'/sections/'.sanitize_key($layout).'.php';
    if ($d !== [] && is_file($file)) {
        $GLOBALS['mx_section_index'] = 50;
        include $file;
    }
}

// [mx_alt_sayfalar] — cards of the page's children (hub pages), [mx_alt_sayfalar ust="/klinik/"] for another parent.
add_shortcode('mx_alt_sayfalar', static function ($atts): string {
    $atts = shortcode_atts(['ust' => '', 'tur' => 'page'], (array) $atts);
    $parent = get_the_ID();
    if ($atts['ust'] !== '') {
        $page = get_page_by_path(trim((string) $atts['ust'], '/'));
        $parent = $page ? $page->ID : 0;
    }
    $items = get_posts(['post_type' => $atts['tur'] === 'musteri' ? 'musteri' : 'page', 'post_parent' => $atts['tur'] === 'musteri' ? 0 : $parent,
        'posts_per_page' => 60, 'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'], 'post_status' => 'publish', 'no_found_rows' => true]);
    if (! $items) {
        return '';
    }
    $out = '<div class="mx-cards">';
    foreach ($items as $item) {
        if (get_post_meta($item->ID, '_mx_noindex', true) === '1') {
            continue;
        }
        $label = trim((string) get_post_meta($item->ID, '_mx_kart_etiket', true));
        $out .= '<a class="mx-card" href="'.esc_url(get_permalink($item)).'">'.mx_svc_icon((string) get_permalink($item), get_the_title($item)).($label !== '' ? '<span class="mx-card-k">'.esc_html($label).'</span>' : '')
            .'<b>'.esc_html(get_the_title($item)).'</b><span>'.esc_html(wp_trim_words($item->post_excerpt !== '' ? $item->post_excerpt : wp_strip_all_tags(strip_shortcodes($item->post_content)), 22)).'</span><i>İncele <em>→</em></i></a>';
    }

    return $out.'</div>';
});

// [mx_site_haritasi] — every published page as a tree, then the guides. Pages marked noindex and the thank-you page are left out.
add_shortcode('mx_site_haritasi', static function (): string {
    $skip = static fn (WP_Post $p): bool => get_post_meta($p->ID, '_mx_noindex', true) === '1' || in_array($p->post_name, ['tesekkurler', 'rehber-yazisi-sablonu', 'site-haritasi'], true);
    $pages = get_pages(['sort_column' => 'menu_order,post_title', 'post_status' => 'publish']);
    $kids = [];
    foreach ($pages as $p) {
        if (! $skip($p)) {
            $kids[(int) $p->post_parent][] = $p;
        }
    }
    $tree = static function (int $parent) use (&$tree, $kids): string {
        if (empty($kids[$parent])) {
            return '';
        }
        $out = '<ul>';
        foreach ($kids[$parent] as $p) {
            $out .= '<li><a href="'.esc_url(get_permalink($p)).'">'.esc_html(get_the_title($p)).'</a>'.$tree((int) $p->ID).'</li>';
        }

        return $out.'</ul>';
    };
    $out = '<div class="mx-sitemap"><div><h2>Sayfalar</h2>'.$tree(0).'</div>';
    $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 200, 'no_found_rows' => true]);
    if ($posts) {
        $out .= '<div><h2>Rehber</h2><ul>';
        foreach ($posts as $p) {
            $out .= '<li><a href="'.esc_url(get_permalink($p)).'">'.esc_html(get_the_title($p)).'</a></li>';
        }
        $out .= '</ul></div>';
    }

    return $out.'</div>';
});

// [mx_logolar] — the client logo wall from Moximu ayarları › Müşteri logoları.
add_shortcode('mx_logolar', static function (): string {
    $rows = mx_rows(function_exists('get_field') ? get_field('musteri_logolari', 'option') : []);
    if (! $rows) {
        return '';
    }
    $out = '<div class="mx-logos">';
    foreach ($rows as $row) {
        $src = mx_img_src($row['logo'] ?? 0);
        $name = (string) ($row['ad'] ?? '');
        $out .= $src !== '' ? '<span><img alt="'.esc_attr($name).'" loading="lazy" src="'.esc_url($src).'"/></span>' : '<span class="t">'.esc_html($name).'</span>';
    }

    return $out.'</div>';
});

// [mx_telefon], [mx_eposta] — contact details from Moximu ayarları.
add_shortcode('mx_telefon', static function (): string {
    $phone = function_exists('get_field') ? trim((string) get_field('telefon', 'option')) : '';
    $phone = $phone !== '' ? $phone : '+90 538 861 02 88';

    return '<a href="tel:'.esc_attr(preg_replace('/[^0-9+]/', '', $phone)).'">'.esc_html($phone).'</a>';
});
add_shortcode('mx_whatsapp', static function (): string {
    $phone = function_exists('get_field') ? trim((string) get_field('telefon', 'option')) : '';
    $digits = preg_replace('/\D/', '', $phone !== '' ? $phone : '+90 538 861 02 88');

    return '<a href="https://wa.me/'.esc_attr($digits).'" rel="noopener" target="_blank">WhatsApp</a>';
});
add_shortcode('mx_eposta', static function (): string {
    $mail = function_exists('get_field') ? trim((string) get_field('talep_eposta', 'option')) : '';
    $mail = $mail !== '' ? $mail : (string) get_option('admin_email');

    return '<a href="mailto:'.esc_attr(antispambot($mail)).'">'.esc_html(antispambot($mail)).'</a>';
});

// [mx_arac tur="talep|butce|kart"] — the three free tools, calculated in the browser.
add_shortcode('mx_arac', static function ($atts): string {
    $type = (string) (shortcode_atts(['tur' => 'talep'], (array) $atts)['tur']);
    ob_start();
    if ($type === 'talep') : ?>
<form class="mx-tool" data-tool="talep" onsubmit="return false">
<label>Aylık reklam harcaması (₺)<input inputmode="numeric" name="harcama" value="20000"></label>
<label>Ayda gelen form + WhatsApp + arama<input inputmode="numeric" name="talep" value="40"></label>
<label>Talepten randevuya dönüşen (%)<input inputmode="numeric" name="oran" value="30"></label>
<label>Ortalama tedavi tutarı (₺)<input inputmode="numeric" name="tutar" value="25000"></label>
<output><span>Bir talep <b data-out="cpl">–</b></span><span>Bir randevu <b data-out="cpa">–</b></span><span>Aylık randevu <b data-out="randevu">–</b></span><span>Reklamın getirdiği ciro <b data-out="ciro">–</b></span></output>
<p class="mx-tool-n">Hesap tarayıcınızda yapılır; girdiğiniz rakamlar hiçbir yere gönderilmez.</p>
</form>
<?php elseif ($type === 'butce') : ?>
<form class="mx-tool" data-tool="butce" onsubmit="return false">
<label>Ayda kaç yeni hasta istiyorsunuz?<input inputmode="numeric" name="hasta" value="20"></label>
<label>Talepten hastaya dönüşen (%)<input inputmode="numeric" name="oran" value="30"></label>
<label>Bir talebin tahmini maliyeti (₺)<input inputmode="numeric" name="cpl" value="400"></label>
<label>Kanal<select name="kanal"><option value="1">Google arama reklamı</option><option value="0.7">Instagram ve Facebook formu</option><option value="0.85">İkisi birlikte</option></select></label>
<output><span>Gereken talep <b data-out="talep">–</b></span><span>Aylık bütçe <b data-out="butce">–</b></span><span>Günlük bütçe <b data-out="gunluk">–</b></span></output>
<p class="mx-tool-n">Talep maliyeti şehir, tedavi ve sezona göre değişir; analizde kendi hesabınızın rakamıyla hesaplarız.</p>
</form>
<?php elseif ($type === 'skor') : ?>
<form class="mx-tool mx-check" data-tool="kart" onsubmit="return false">
<?php foreach (['Her tedavinin sitede ayrı sayfası var', 'Site telefonda 3 saniyenin altında açılıyor', 'Her sayfada arama ve WhatsApp düğmesi görünüyor', 'Form iki üç alandan oluşuyor ve kliniğe anında düşüyor', 'Google İşletme Profilinde hizmetler, saatler ve fotoğraflar güncel', 'Son ayın yorumlarının hepsi cevaplanmış', 'Reklam hesapları kliniğin adına', 'Form, arama ve WhatsApp ayrı ölçülüyor', 'Reklam arama terimleri her hafta gözden geçiriliyor', 'Bir talebin kaça geldiğini biliyorsunuz', 'Gelen talepler aynı gün aranıyor', 'Her ay tek sayfalık bir rapor alıyorsunuz'] as $i => $item) : ?>
<label class="mx-chk"><input name="k<?= (int) $i ?>" type="checkbox"> <?= esc_html($item) ?></label>
<?php endforeach; ?>
<output><span>Puanınız <b data-out="puan">0 / 12</b></span><span data-out="yorum">Kutuları işaretleyin.</span></output>
</form>
<?php else : ?>
<form class="mx-tool mx-check" data-tool="kart" onsubmit="return false">
<?php foreach (['Profil adı tabeladaki adla aynı', 'Birincil kategori doğru (ör. Diş kliniği)', 'Hizmet listesi tek tek girilmiş', 'Çalışma saatleri ve tatil günleri güncel', 'Telefon ve web sitesi bağlantısı doğru', 'Son 30 günde en az 2 yeni fotoğraf', 'Son 7 günde bir gönderi', 'Tüm yorumlar cevaplanmış', 'Son 30 günde yeni yorum gelmiş', 'Aynı adreste ikinci profil yok', 'Soru-cevap bölümü dolu', 'Site, tedavi sayfasına bağlı (ana sayfaya değil)'] as $i => $item) : ?>
<label class="mx-chk"><input name="k<?= (int) $i ?>" type="checkbox"> <?= esc_html($item) ?></label>
<?php endforeach; ?>
<output><span>Kart puanı <b data-out="puan">0 / 12</b></span><span data-out="yorum">Kutuları işaretleyin.</span></output>
</form>
<?php endif;
    $html = (string) ob_get_clean();
    static $script = false;
    if (! $script) {
        $script = true;
        $html .= '<script>'.mx_tool_js().'</script>';
    }

    return $html;
});

function mx_tool_js(): string
{
    return <<<'JS'
(function(){var f=function(n){return new Intl.NumberFormat('tr-TR',{maximumFractionDigits:0}).format(n)};var num=function(el){return parseFloat(String(el.value).replace(/\./g,'').replace(',','.'))||0};
function run(form){var t=form.getAttribute('data-tool'),q=function(n){return form.querySelector('[name="'+n+'"]')},o=function(n,v){var e=form.querySelector('[data-out="'+n+'"]');if(e)e.textContent=v};
if(t==='talep'){var h=num(q('harcama')),l=num(q('talep')),r=num(q('oran'))/100,a=num(q('tutar'));var rv=l*r;o('cpl',l?f(h/l)+' ₺':'–');o('cpa',rv?f(h/rv)+' ₺':'–');o('randevu',f(rv));o('ciro',f(rv*a)+' ₺')}
if(t==='butce'){var p=num(q('hasta')),r2=num(q('oran'))/100,c=num(q('cpl'))*parseFloat(q('kanal').value);var need=r2?Math.ceil(p/r2):0;o('talep',f(need));o('butce',f(need*c)+' ₺');o('gunluk',f(need*c/30.4)+' ₺')}
if(t==='kart'){var b=form.querySelectorAll('input[type=checkbox]'),n=0;b.forEach(function(x){if(x.checked)n++});o('puan',n+' / '+b.length);o('yorum',n>=11?'İyi durumda; haftalık düzeni sürdürün.':n>=7?'Eksikler var; işaretsiz maddeler bu hafta kapanabilir.':'Temel eksikler var; ücretsiz analizde birlikte ele alalım.')}}
document.querySelectorAll('.mx-tool').forEach(function(form){form.addEventListener('input',function(){run(form)});form.addEventListener('change',function(){run(form)});run(form)})})();
JS;
}

// [mx_bulten] — newsletter sign-up, stored as a private "Bülten kaydı" and mailed like an analysis request.
add_shortcode('mx_bulten', static function (): string {
    return '<form class="mx-tool mx-news" data-news onsubmit="return false"><label>E-posta<input autocomplete="email" name="email" required type="email"></label>'
        .'<label>Klinik adı (isteğe bağlı)<input name="klinik"></label><input aria-hidden="true" class="mx-hp" name="website" tabindex="-1">'
        .'<label class="mx-chk"><input name="kvkk" type="checkbox" value="1"> <span><a href="'.esc_url(home_url('/kvkk-aydinlatma-metni/')).'">KVKK aydınlatma metni</a>\'ni okudum; ayda bir e-posta almayı kabul ediyorum.</span></label>'
        .'<button class="btn" type="submit">Abone ol</button><p class="mx-tool-n" data-msg></p></form>'
        .'<script>document.querySelectorAll("[data-news]").forEach(function(f){f.addEventListener("submit",function(){var d=new FormData(f);d.append("action","mx_bulten");var m=f.querySelector("[data-msg]");fetch('.wp_json_encode(admin_url('admin-ajax.php')).',{method:"POST",body:d}).then(function(r){return r.json()}).then(function(j){m.textContent=(j.data&&j.data.message)||"Teşekkürler.";if(j.success)f.reset()}).catch(function(){m.textContent="Gönderilemedi, lütfen tekrar deneyin."})})})</script>';
});

function mx_bulten_handle(): void
{
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        wp_send_json_success(['message' => 'Teşekkürler.']);
    }
    $mail = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
    if ($mail === '' || empty($_POST['kvkk'])) {
        wp_send_json_error(['message' => 'E-posta ve onay gerekli.'], 422);
    }
    $key = 'mx_bulten_'.md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ((int) get_transient($key) >= 5) {
        wp_send_json_error(['message' => 'Çok fazla deneme. Lütfen biraz sonra tekrar deneyin.'], 429);
    }
    set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
    $clinic = mb_substr(sanitize_text_field(wp_unslash((string) ($_POST['klinik'] ?? ''))), 0, 120);
    wp_insert_post(['post_type' => 'mx_talep', 'post_status' => 'private', 'post_title' => 'Bülten: '.$mail, 'post_content' => "E-posta: $mail\nKlinik: $clinic\nTür: Bülten"]);
    mx_send_mail('Yeni bülten kaydı: '.$mail, "E-posta: $mail\nKlinik: $clinic");
    wp_send_json_success(['message' => 'Kaydınız alındı. İlk e-posta ayın ilk haftası gelir.']);
}
add_action('wp_ajax_mx_bulten', 'mx_bulten_handle');
add_action('wp_ajax_nopriv_mx_bulten', 'mx_bulten_handle');

// Seeded text uses site-relative links ("/fiyatlar/"); on a site installed in a folder they point inside that folder.
add_filter('the_content', static function (string $html): string {
    $home = untrailingslashit(home_url());
    if ($home === untrailingslashit((string) preg_replace('~^(https?://[^/]+).*$~', '$1', $home)) || ! str_contains($html, '="/')) {
        return $html;
    }

    return (string) preg_replace('~\b(href|src)="/(?!/)~', '$1="'.esc_url($home).'/', $html);
}, 20);

/** Name and icon file of a social profile link in the footer. */
function mx_social(string $url): array
{
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn'] as $key => $name) {
        if (str_contains($host, $key)) {
            return [$name, 's-'.$key.'.svg'];
        }
    }

    return ['YouTube', 'ddd804db4f.svg'];
}

/** Cookie banner: shown until the visitor chooses; Clarity and the Meta pixel run only after "Kabul et". */
add_action('wp_footer', static function (): void {
    if (! function_exists('get_field') || is_user_logged_in()) {
        return;
    }
    if (trim((string) get_field('gtm_id', 'option').get_field('clarity_id', 'option').get_field('meta_pixel_id', 'option')) === '') {
        return;
    }
    $policy = mx_seed_post_id('/cerez-politikasi/');
    ?>
<div class="mx-consent" id="mx-consent" role="dialog" aria-label="Çerez tercihi" hidden><p>Siteyi ölçmek ve reklamlarımızı değerlendirmek için çerez kullanıyoruz; onay vermezseniz yalnız zorunlu çerezler çalışır.<?php if ($policy) : ?> <a href="<?= esc_url(get_permalink($policy)) ?>">Çerez politikası</a><?php endif; ?></p><div><button type="button" class="mx-consent-min" data-c="min">Yalnız zorunlu</button><button type="button" class="btn" data-c="all">Kabul et</button></div></div>
<script>(function(){var b=document.getElementById("mx-consent");if(!b)return;function show(){b.hidden=!1}if(!/(?:^|; )mx_consent=/.test(document.cookie))show();b.addEventListener("click",function(e){var c=e.target.getAttribute("data-c");if(!c)return;document.cookie="mx_consent="+c+";path=/;max-age=31536000;SameSite=Lax";var g=c==="all"?"granted":"denied";if(window.gtag)gtag("consent","update",{ad_storage:g,ad_user_data:g,ad_personalization:g,analytics_storage:g});if(c==="all"&&window.mxConsentRun)mxConsentRun();b.hidden=!0});document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest('a[href$="#cerez-tercihleri"]');if(a){e.preventDefault();show()}})})();</script>
<?php
}, 50);

/*
 * A client's featured image is its logo (a wide strip); shared on Facebook, LinkedIn or X it would be cropped badly.
 * Unless a share image is chosen in the post's SEOPress box, case pages share the site's 1200×630 image.
 */
add_filter('get_post_metadata', static function ($value, $object_id, $meta_key, $single) {
    if (! in_array($meta_key, ['_seopress_social_fb_img', '_seopress_social_twitter_img'], true) || is_admin() || get_post_type($object_id) !== 'musteri') {
        return $value;
    }
    global $wpdb;
    $own = (string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $object_id, $meta_key));
    if ($own !== '') {
        return $value;
    }
    $url = MX_URI.'/assets/i/og.png';

    return $single ? $url : [$url];
}, 10, 4);

/** Portfolio cards: the live site in a browser frame (scrolls on hover) with its phone view on top. */
function mx_portfolio_grid(array $items): string
{
    if (! $items) {
        return '';
    }
    $out = '<div class="mx-pf-g">';
    foreach ($items as $row) {
        $name = trim(wp_strip_all_tags((string) ($row['ad'] ?? '')));
        $url = trim((string) ($row['adres'] ?? ''));
        if ($name === '') {
            continue;
        }
        $host = $url !== '' ? preg_replace('~^www\.~', '', (string) wp_parse_url($url, PHP_URL_HOST)) : '';
        $desk = mx_img_src($row['gorsel'] ?? null);
        $phone = mx_img_src($row['gorsel_2'] ?? null);
        $tag = $url !== '' ? 'a' : 'div';
        $link = $url !== '' ? ' href="'.esc_url($url).'" rel="noopener" target="_blank" aria-label="'.esc_attr($name.' sitesini yeni sekmede açın').'"' : '';
        $out .= '<article class="mx-pf-i"><'.$tag.' class="mx-pf-v"'.$link.'>'
            .'<span class="mx-pf-br"><span class="mx-pf-bar"><i></i><i></i><i></i><em>'.esc_html($host).'</em></span>'
            .'<span class="mx-pf-scr">'.($desk !== '' ? '<img alt="'.esc_attr($name.' web sitesi, masaüstü görünüm').'" decoding="async" loading="lazy" src="'.$desk.'"/>' : '').'</span></span>'
            .($phone !== '' ? '<span class="mx-pf-ph"><img alt="'.esc_attr($name.' web sitesi, telefon görünümü').'" decoding="async" loading="lazy" src="'.$phone.'"/></span>' : '')
            .'</'.$tag.'><div class="mx-pf-t">'
            .(! empty($row['tur']) ? '<span class="mx-pf-k">'.esc_html((string) $row['tur']).'</span>' : '')
            .'<h3>'.esc_html($name).'</h3>'
            .(! empty($row['metin']) ? '<p>'.esc_html((string) $row['metin']).'</p>' : '')
            .($url !== '' ? '<a class="mx-pf-go" href="'.esc_url($url).'" rel="noopener" target="_blank">'.esc_html($host).' ↗</a>' : '')
            .'</div></article>';
    }

    return $out.'</div>';
}

// [mx_portfoy] — every site from the portfolio section of the web site service page (one list, two places).
add_shortcode('mx_portfoy', static function (): string {
    $page = get_page_by_path('hizmetlerimiz/klinik-web-sitesi');
    if (! $page || ! function_exists('get_field')) {
        return '';
    }
    foreach ((array) get_field('bolumler', $page->ID) as $row) {
        if (($row['acf_fc_layout'] ?? '') === 'portfoy') {
            return mx_portfolio_grid(mx_rows($row['liste'] ?? null));
        }
    }

    return '';
});

// [mx_video id="YouTube id" baslik="…"] — a light video card: thumbnail first, the player (youtube-nocookie) only on click.
add_shortcode('mx_video', static function ($atts): string {
    $atts = shortcode_atts(['id' => '', 'baslik' => '', 'sure' => ''], (array) $atts);
    $id = preg_replace('/[^\w-]/', '', (string) $atts['id']);
    if ($id === '') {
        return '';
    }
    $title = (string) $atts['baslik'];

    return '<figure class="mx-yt"><button type="button" data-yt="'.esc_attr($id).'" aria-label="'.esc_attr(($title !== '' ? $title.' · ' : '').'videoyu oynatın').'">'
        .'<img alt="" decoding="async" loading="lazy" src="https://i.ytimg.com/vi/'.esc_attr($id).'/hqdefault.jpg"/>'
        .'<span class="mx-yt-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></span></button>'
        .($title !== '' ? '<figcaption>'.esc_html($title).($atts['sure'] !== '' ? ' <span>'.esc_html((string) $atts['sure']).'</span>' : '').'</figcaption>' : '')
        .'</figure>';
});

// [mx_harita] — the office on a map; Google Maps loads only when the visitor asks for it (no third-party request before).
add_shortcode('mx_harita', static function (): string {
    $q = 'Moximu, Gazi Osman Paşa Cd. No:44, Şehzadeler, Manisa';
    $src = 'https://www.google.com/maps?q='.rawurlencode($q).'&z=16&output=embed';

    return '<div class="mx-map"><div class="mx-map-card"><b>Moximu</b><span>1. Anafartalar, Gazi Osman Paşa Cd. No:44<br/>45010 Şehzadeler / Manisa</span>'
        .'<small>Hafta içi 09:00–18:00</small><button type="button" class="btn" data-map="'.esc_attr($src).'">Haritayı gösterin</button>'
        .'<a href="https://www.google.com/maps/search/?api=1&amp;query='.rawurlencode($q).'" rel="noopener" target="_blank">Google Haritalar\'da açın ↗</a></div></div>';
});

// Favicon from the Moximu mark (theme files), unless a Site Icon is set in WordPress.
add_action('wp_head', static function (): void {
    if (has_site_icon()) {
        return;
    }
    $f = MX_URI.'/assets/i/fav/';
    echo '<link rel="icon" href="'.esc_url($f.'favicon.ico').'" sizes="any"/>'
        .'<link rel="icon" type="image/png" sizes="32x32" href="'.esc_url($f.'favicon-32.png').'"/>'
        .'<link rel="icon" type="image/png" sizes="192x192" href="'.esc_url($f.'favicon-192.png').'"/>'
        .'<link rel="apple-touch-icon" href="'.esc_url($f.'favicon-180.png').'"/>'
        .'<meta name="theme-color" content="#fdcf3c"/>'."\n";
}, 2);
