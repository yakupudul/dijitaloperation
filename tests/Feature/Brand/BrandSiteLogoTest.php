<?php

namespace Tests\Feature\Brand;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Brand\BrandSiteLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * yakup 2026-10-19: the brand avatar shows the logo taken from the brand's own website; a logo typed in the brand
 * form is never replaced.
 */
final class BrandSiteLogoTest extends TestCase
{
    use RefreshDatabase;

    private function png(): string
    {
        return "\x89PNG\r\n\x1a\n".str_repeat('x', 300);
    }

    public function test_square_site_icon_wins_over_theme_logo_and_small_favicon(): void
    {
        $html = '<html><head><link rel="icon" href="/fav-32.png" sizes="32x32"><link rel=icon href="/fav-192.png" sizes="192x192">'
            .'<link rel="apple-touch-icon" href="https://cdn.example.com/touch.png"></head><body><img class="custom-logo" src="/wp-content/logo.png"></body></html>';

        $this->assertSame([
            'https://cdn.example.com/touch.png',
            'https://site.test/fav-192.png',
            'https://site.test/wp-content/logo.png',
            'https://site.test/fav-32.png',
        ], BrandSiteLogo::fromHtml($html, 'https://site.test'));
    }

    public function test_logo_is_copied_from_the_site_and_typed_logos_are_kept(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://klinik.test/' => Http::response('<link rel="apple-touch-icon" href="/touch.png"><img data-x="1" src="/a.svg" class="site-logo">'),
            'https://klinik.test/touch.png' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('', 404),
        ]);
        $brand = Brand::factory()->create(['logo_url' => null]);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'klinik.test', 'primary_url' => 'https://klinik.test']);
        $typed = Brand::factory()->create(['logo_url' => 'https://elle.example.com/logo.png']);
        DigitalAsset::factory()->create(['brand_id' => $typed->id, 'type' => 'website', 'domain' => 'elle.test', 'primary_url' => 'https://elle.test']);
        $noSite = Brand::factory()->create(['logo_url' => null]);

        $logos = app(BrandSiteLogo::class);

        $this->assertSame('saved', $logos->refresh($brand));
        Storage::disk('public')->assertExists('brand-logos/'.$brand->id.'.png');
        $this->assertTrue(BrandSiteLogo::isOwnCopy((string) $brand->fresh()->logo_url));
        $this->assertSame('kept', $logos->refresh($typed));
        $this->assertSame('https://elle.example.com/logo.png', $typed->fresh()->logo_url);
        $this->assertSame('missing', $logos->refresh($noSite));
        $this->assertNull($noSite->fresh()->logo_url);

        // A copy made here is refreshed on the next run.
        $this->assertSame('saved', $logos->refresh($brand->fresh()));
    }

    public function test_svg_and_non_images_are_never_stored(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://svg.test/' => Http::response('<link rel="icon" href="/i.svg" sizes="192x192">'),
            'https://svg.test/i.svg' => Http::response('<svg><script>alert(1)</script></svg>'.str_repeat(' ', 200), 200, ['Content-Type' => 'image/svg+xml']),
            'https://svg.test/apple-touch-icon.png' => Http::response('<html>not found page'.str_repeat(' ', 200), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $brand = Brand::factory()->create(['logo_url' => null]);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'svg.test', 'primary_url' => 'https://svg.test']);

        $this->assertSame('missing', app(BrandSiteLogo::class)->refresh($brand));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
