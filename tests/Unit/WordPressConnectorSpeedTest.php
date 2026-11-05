<?php

namespace Tests\Unit;

use MoxDOP_Connector_Speed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Connector 1.37.0 "Hız turu" (yakup 2026-11-05): the HTML parts of the speed recipe, pure string work (no WordPress).
 */
final class WordPressConnectorSpeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir().'/moxdop-wp-stub/');
        }
        require_once dirname(__DIR__, 2).'/connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-speed.php';
    }

    #[Test]
    public function the_first_images_of_the_page_are_not_lazy_loaded(): void
    {
        $imgs = '';
        for ($i = 1; $i <= 6; $i++) {
            $imgs .= '<img src="/'.$i.'.jpg" loading="lazy" alt="">';
        }
        $html = '<html><head><meta name="x" content=\'<img loading="lazy">\'></head><body class="home">'.$imgs.'</body></html>';
        $out = MoxDOP_Connector_Speed::eager($html);

        $this->assertStringContainsString('<img src="/1.jpg" alt="">', $out);
        $this->assertStringContainsString('<img src="/4.jpg" alt="">', $out);
        $this->assertStringContainsString('<img src="/5.jpg" loading="lazy" alt="">', $out, 'below the first images lazy loading stays');
        $this->assertStringContainsString('content=\'<img loading="lazy">\'', $out, 'nothing before <body> is touched');
    }

    #[Test]
    public function tag_manager_and_clarity_start_late_but_consent_blocked_scripts_and_meta_pixel_stay(): void
    {
        $html = '<html><body>'
            .'<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
            .'<script>(function(c,l,a,r,i,t,y){})(window,document,"clarity","script","x");var u="https://www.clarity.ms/tag/";</script>'
            .'<script type="text/plain" data-category="analytics" src="https://www.googletagmanager.com/gtm.js?id=GTM-1"></script>'
            .'<script type="application/ld+json">{"url":"https://www.googletagmanager.com"}</script>'
            .'<script>!function(f,b,e,v){}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");</script>'
            .'</body></html>';
        $out = MoxDOP_Connector_Speed::late_tags($html);

        $this->assertStringContainsString('<script type="text/mx-later" src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>', $out);
        $this->assertSame(2, substr_count($out, '<script type="text/mx-later"'));
        $this->assertStringContainsString('<script type="text/plain" data-category="analytics"', $out, 'a consent tool keeps its blocked script');
        $this->assertStringContainsString('<script type="application/ld+json">', $out);
        $this->assertStringContainsString('<script>!function(f,b,e,v)', $out, 'the Meta pixel is not delayed');
        $this->assertStringEndsWith('</script></body></html>', $out);
        $this->assertSame($out, MoxDOP_Connector_Speed::late_tags($out), 'running twice changes nothing');
        $this->assertSame('<html><body><p>x</p></body></html>', MoxDOP_Connector_Speed::late_tags('<html><body><p>x</p></body></html>'));
    }
}
