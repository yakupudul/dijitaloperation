<?php

namespace Tests\Feature\Gbp;

use App\Support\PrintLogo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrintLogoTest extends TestCase
{
    public function test_print_logo_reaches_a_host_that_refuses_the_server_through_the_stored_page_and_the_proxy(): void
    {
        $image = imagecreatetruecolor(480, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 20, 20, 460, 100, imagecolorallocate($image, 15, 95, 91));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        Http::fake([
            'wsrv.nl/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'r.jina.ai/*' => Http::response('<html><header><img class="logo" src="https://klinik.test/wp-content/uploads/logo.png"></header></html>'),
            'klinik.test/*' => Http::response('Forbidden', 403),
        ]);
        $stored = '<html><header><a href="/"><img class="site-logo" src="https://klinik.test/wp-content/uploads/logo.png"></a></header></html>';

        $found = PrintLogo::find('https://klinik.test', $stored);

        $this->assertNotNull($found);
        $this->assertStringStartsWith('data:image/png;base64,', $found['uri']);
        $this->assertSame('#0f5f5b', $found['accent']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://wsrv.nl/?url='.urlencode('https://klinik.test/wp-content/uploads/logo.png')));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://klinik.test/wp-content/uploads/logo.png');
        $this->assertNotNull(PrintLogo::find('https://klinik.test'), 'no stored copy: the page comes through the reader');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://r.jina.ai/https://klinik.test/');
    }
}
