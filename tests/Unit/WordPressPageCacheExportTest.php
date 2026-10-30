<?php

namespace Tests\Unit;

use MoxDOP_Connector_Page_Cache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Connector 1.6.0 page-cache export: the cache-file layout of each supported plugin, read from a temporary
 * wp-content/cache directory (no WordPress needed for the path resolution and the file read).
 */
final class WordPressPageCacheExportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir().'/moxdop-wp-stub/');
        }
        require_once dirname(__DIR__, 2).'/connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-page-cache.php';
        $this->dir = sys_get_temp_dir().'/moxdop-page-cache-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    #[Test]
    public function every_supported_plugin_layout_resolves_to_its_cache_files(): void
    {
        $base = '/srv/wp-content/cache/x';
        $this->assertSame([$base.'/example.com/hizmet/implant/index-https.html', $base.'/example.com/hizmet/implant/index-https.html_gzip'],
            MoxDOP_Connector_Page_Cache::candidate_paths('wp_rocket', $base, 'https://Example.com/hizmet/implant/'));
        $this->assertSame([$base.'/example.com/index.html', $base.'/example.com/index.html_gzip'],
            MoxDOP_Connector_Page_Cache::candidate_paths('wp_rocket', $base, 'http://example.com/'));
        $this->assertSame($base.'/example.com/blog/index-https.html',
            MoxDOP_Connector_Page_Cache::candidate_paths('wp_super_cache', $base, 'https://example.com/blog')[0]);
        $this->assertSame([$base.'/example.com/iletisim/_index_ssl.html', $base.'/example.com/iletisim/_index_ssl.html_gzip'],
            MoxDOP_Connector_Page_Cache::candidate_paths('w3_total_cache', $base, 'https://example.com/iletisim/'));
        $this->assertSame([$base.'/iletisim/index.html'],
            MoxDOP_Connector_Page_Cache::candidate_paths('wp_fastest_cache', $base, 'https://example.com/iletisim/'));
        $this->assertSame($base.'/example.com/iletisim/https-index.html',
            MoxDOP_Connector_Page_Cache::candidate_paths('cache_enabler', $base, 'https://example.com/iletisim/')[0]);
        // Percent-encoded (Turkish) slugs are stored decoded.
        $this->assertSame($base.'/example.com/diş-beyazlatma/index-https.html',
            MoxDOP_Connector_Page_Cache::candidate_paths('wp_rocket', $base, 'https://example.com/di%C5%9F-beyazlatma/')[0]);

        foreach (['https://example.com/?p=12', 'https://example.com/a/../../etc/', 'https://user:pw@example.com/', 'not a url'] as $url) {
            $this->assertSame([], MoxDOP_Connector_Page_Cache::candidate_paths('wp_rocket', $base, $url), $url);
        }
        $this->assertSame([], MoxDOP_Connector_Page_Cache::candidate_paths('litespeed', $base, 'https://example.com/'));
    }

    #[Test]
    public function cached_html_is_read_from_plain_and_gzip_files_but_never_outside_the_cache_directory(): void
    {
        $html = '<!DOCTYPE html><html><body><h1>İmplant</h1></body></html><!-- This website is like a Rocket -->';
        mkdir($this->dir.'/example.com/implant', 0777, true);
        file_put_contents($this->dir.'/example.com/implant/index-https.html', $html);
        mkdir($this->dir.'/example.com/ortodonti', 0777, true);
        file_put_contents($this->dir.'/example.com/ortodonti/index-https.html_gzip', gzencode($html));
        mkdir($this->dir.'/example.com/bos', 0777, true);
        file_put_contents($this->dir.'/example.com/bos/index-https.html', 'not html');

        $plain = MoxDOP_Connector_Page_Cache::read_cached('wp_rocket', $this->dir, 'https://example.com/implant/');
        $this->assertSame($html, $plain['html']);
        $this->assertGreaterThan(0, $plain['file_mtime']);
        $this->assertSame($html, MoxDOP_Connector_Page_Cache::read_cached('wp_rocket', $this->dir, 'https://example.com/ortodonti/')['html']);
        $this->assertNull(MoxDOP_Connector_Page_Cache::read_cached('wp_rocket', $this->dir, 'https://example.com/yok/'), 'not cached');
        $this->assertNull(MoxDOP_Connector_Page_Cache::read_cached('wp_rocket', $this->dir, 'https://example.com/bos/'), 'not an HTML page');

        // A symlink that leaves the cache directory is not followed.
        $outside = sys_get_temp_dir().'/moxdop-outside-'.bin2hex(random_bytes(4)).'.html';
        file_put_contents($outside, $html);
        mkdir($this->dir.'/example.com/link', 0777, true);
        symlink($outside, $this->dir.'/example.com/link/index-https.html');
        $this->assertNull(MoxDOP_Connector_Page_Cache::read_cached('wp_rocket', $this->dir, 'https://example.com/link/'));
        unlink($outside);
    }

    #[Test]
    public function the_smart_date_box_is_registered_and_only_fills_the_editors_own_publish(): void
    {
        $root = dirname(__DIR__, 2).'/connectors/wordpress/moxdop-connector';
        $plugin = (string) file_get_contents($root.'/moxdop-connector.php');
        $this->assertStringContainsString('class-moxdop-connector-smart-date.php', $plugin);
        $this->assertStringContainsString('(new MoxDOP_Connector_Smart_Date)->register();', $plugin);
        $box = (string) file_get_contents($root.'/includes/class-moxdop-connector-smart-date.php');
        $this->assertStringContainsString("check_ajax_referer('moxdop_smart_date'", $box);
        $this->assertStringContainsString("dispatch('core/editor').editPost", $box);
        $this->assertStringContainsString("getElementById('publish')", $box);
        $this->assertStringNotContainsString('hidden_', $box);
        $this->assertStringContainsString("document.body.classList.contains('block-editor-page')", $box, 'classic pages that load the block editor scripts stay on the classic path');
        $this->assertStringContainsString('$slot[\'date\'] <= $current', $box, 'today only once its hour has come');
        $this->assertStringContainsString("add_filter('bulk_actions-edit-post'", $box);
        $this->assertStringContainsString("check_admin_referer('moxdop_smart_date_bulk')", $box);
        $this->assertStringContainsString('const BULK_MAX = 20;', $box);
        $this->assertStringContainsString("'İleriye zamanla'", $box, 'the bulk screen can line drafts up ahead');
        $this->assertStringContainsString('İleri tarih (zamanlanır)', $box, 'the box always offers days ahead');
        $this->assertStringNotContainsString('wp_remote_', $box);
    }

    #[Test]
    public function the_claude_and_chatgpt_connection_is_off_by_default_and_signs_in_with_pkce(): void
    {
        $root = dirname(__DIR__, 2).'/connectors/wordpress/moxdop-connector';
        $plugin = (string) file_get_contents($root.'/moxdop-connector.php');
        $this->assertStringContainsString('(new MoxDOP_Connector_AI)->register();', $plugin);
        $ai = (string) file_get_contents($root.'/includes/class-moxdop-connector-ai.php');
        $this->assertStringContainsString("get_option(self::ACCESS_OPTION, 'off')", $ai, 'off until a site admin picks a level');
        $this->assertStringContainsString("hash_equals((string) \$code['challenge'], \$challenge)", $ai, 'PKCE S256 is checked');
        $this->assertStringContainsString('delete_transient($key);', $ai, 'a code works once');
        $this->assertStringContainsString("'claude.ai', 'claude.com', 'anthropic.com', 'chatgpt.com', 'openai.com'", $ai, 'only Claude and ChatGPT may register');
        $this->assertStringContainsString("hash('sha256', \$access)", $ai, 'keys are stored hashed');
        $this->assertStringContainsString("user_can(\$user, 'manage_options')", $ai);
        $this->assertStringNotContainsString("'post_status' => 'publish'", $ai, 'nothing is published over the connection');
        $this->assertStringNotContainsString('wp_delete_post', $ai);
        $fixes = (string) file_get_contents($root.'/includes/class-moxdop-connector-fixes.php');
        $this->assertStringContainsString('public function undo_change($id)', $fixes);
        $full = (string) file_get_contents($root.'/includes/class-moxdop-connector-ai-full.php');
        $this->assertStringContainsString("!== 'downloads.wordpress.org'", $full, 'plugins only from WordPress.org');
        $this->assertStringContainsString("'siteurl', 'home', 'admin_email'", $full, 'options that could lock the site are refused');
        $this->assertStringContainsString('$undo();', $full, 'a change that broke the site is put back');
        $this->assertStringContainsString("'Etkin eklenti silinmez; önce set_plugin_state ile kapat.'", $full, 'only inactive plugins are deleted');
        $this->assertStringContainsString("'DELETE_PLUGINS'", $full, 'deleting plugins needs a confirmation after a dry run');
        $this->assertStringContainsString("'DELETE_USERS'", $full, 'deleting users needs a confirmation after a dry run');
        $this->assertStringContainsString("min(500, (int) (\$args['limit'] ?? 200))", $full, 'users are deleted in batches that fit the PHP time limit');
        $this->assertStringNotContainsString('wp_delete_post(', $full, 'nothing is deleted for good');
        $this->assertStringContainsString("'permission_callback' => [\$auth, 'authorize']", $ai, 'MoxDOP\'s call is signed');
    }

    #[Test]
    public function the_plugin_files_are_valid_php_and_the_route_keeps_the_one_at_a_time_lock(): void
    {
        $root = dirname(__DIR__, 2).'/connectors/wordpress/moxdop-connector';
        foreach (array_merge([$root.'/moxdop-connector.php'], glob($root.'/includes/*.php') ?: []) as $file) {
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $code);
            $this->assertSame(0, $code, $file.': '.implode("\n", $output));
        }
        $plugin = (string) file_get_contents($root.'/moxdop-connector.php');
        $this->assertStringContainsString("define('MOXDOP_CONNECTOR_VERSION', '1.32.0')", $plugin);
        $this->assertStringContainsString('class-moxdop-connector-page-cache.php', $plugin);
        $this->assertStringContainsString('Stable tag: 1.32.0', (string) file_get_contents($root.'/readme.txt'));

        $controller = (string) file_get_contents($root.'/includes/class-moxdop-connector-rest-controller.php');
        $this->assertStringContainsString("'/page-cache'", $controller);
        $route = substr($controller, (int) strpos($controller, 'public function page_cache'));
        $route = substr($route, 0, (int) strpos($route, 'private function build_snapshot'));
        $this->assertStringContainsString('MoxDOP_Connector_Lock::acquire($lock, 120)', $route);
        $this->assertStringContainsString("'moxdop_connector_snapshot_lock'", $route);
        $this->assertStringContainsString("'permission_callback' => [\$this->auth, 'authorize'],\n            'callback' => [\$this, 'page_cache']", $controller);

        $export = (string) file_get_contents($root.'/includes/class-moxdop-connector-page-cache.php');
        // Files are read, never rendered or written.
        $this->assertStringNotContainsString('apply_filters(\'the_content\'', $export);
        $this->assertStringNotContainsString('file_put_contents', $export);
        $this->assertStringNotContainsString('wp_remote_', $export);
    }
}
