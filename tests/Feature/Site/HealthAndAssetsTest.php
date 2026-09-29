<?php

namespace Tests\Feature\Site;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Website\V2\HealthTab;
use App\Livewire\Operator\Website\V2\LinkedAssetsTab;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Site\Health\SiteExpiryChecker;
use App\Services\Site\SiteDomains;
use App\Support\Roles;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Faz 4b Site Sağlığı (WordPress, SSL, domain, hosting, uptime) and Bağlı Varlıklar. */
final class HealthAndAssetsTest extends TestCase
{
    use RefreshDatabase;
    use SiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSite();
    }

    public function test_ssl_expiry_is_read_from_the_certificate_and_domain_expiry_from_rdap(): void
    {
        $parsed = $this->selfSignedCertificate(days: 20);
        $this->app->instance(SslCertificateProbe::class, new class($parsed) extends SslCertificateProbe
        {
            /** @param  array<string, mixed>  $parsed */
            public function __construct(private readonly array $parsed)
            {
                parent::__construct();
            }

            public function probe(string $host, DateTimeInterface $observedAt, int $port = 443): array
            {
                return (new SslCertParser)->fromOpenSslParsed($this->parsed, $host, $observedAt);
            }
        });
        Http::fake(['rdap.org/domain/panorama.example' => Http::response(['objectClassName' => 'domain', 'events' => [
            ['eventAction' => 'registration', 'eventDate' => '2015-03-01T00:00:00Z'],
            ['eventAction' => 'expiration', 'eventDate' => '2027-03-01T00:00:00Z'],
        ]])]);

        $result = app(SiteExpiryChecker::class)->check($this->site);

        $this->assertTrue($result['checked']);
        $row = DB::table('website_expiry_checks')->where('website_asset_id', $this->site->id)->sole();
        $this->assertSame(now()->addDays(20)->toDateString(), substr((string) $row->ssl_expires_at, 0, 10));
        $this->assertSame('Test CA', $row->ssl_issuer);
        $this->assertSame('2027-03-01', substr((string) $row->domain_expires_at, 0, 10));
        $this->assertSame('panorama.example', $row->registrable_domain);

        // Checked at most once a day.
        $this->assertFalse(app(SiteExpiryChecker::class)->check($this->site)['checked']);
        Http::assertSentCount(1);

        Livewire::test(HealthTab::class, ['assetId' => $this->site->id])
            ->assertSee('SSL sertifikası')->assertSee('20 gün kaldı · Test CA')
            ->assertSee('Alan adı')->assertSee('01.03.2027');
    }

    public function test_rdap_parsing_and_registrable_domains(): void
    {
        $this->assertSame('2026-12-31', SiteExpiryChecker::expiration(['events' => [['eventAction' => 'Expiration', 'eventDate' => '2026-12-31T23:59:59Z']]])?->toDateString());
        $this->assertNull(SiteExpiryChecker::expiration(['events' => [['eventAction' => 'last changed', 'eventDate' => '2026-01-01']]]));
        $this->assertSame('panorama.com.tr', SiteDomains::registrable('www.panorama.com.tr'));
        $this->assertSame('example.com', SiteDomains::registrable('blog.example.com'));

        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'klinik.com.tr']);
        $this->app->instance(SslCertificateProbe::class, new class extends SslCertificateProbe
        {
            public function probe(string $host, DateTimeInterface $observedAt, int $port = 443): array
            {
                return (new SslCertParser)->missing($host, $observedAt, SslCertParser::FETCH_METHOD_PHP_STREAM, 'certificate_missing');
            }
        });
        Http::fake(['rdap.org/*' => Http::response([], 404)]);
        app(SiteExpiryChecker::class)->check($site);
        $row = DB::table('website_expiry_checks')->where('website_asset_id', $site->id)->sole();
        $this->assertSame(['klinik.com.tr', 'Sertifika alınamadı'], [$row->registrable_domain, $row->ssl_error]);
        $this->assertStringStartsWith('RDAP kaydı yok', (string) $row->domain_error);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://rdap.org/domain/klinik.com.tr');
    }

    public function test_health_rows_show_wordpress_updates_hosting_uptime_and_the_login_button(): void
    {
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'plugin_version' => '1.5.0']]);
        DB::table('wordpress_site_health')->insert(['digital_asset_id' => $this->site->id, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'payload' => json_encode([
            'wordpress_version' => '6.5.2', 'core_update' => '6.6.1',
            'plugins' => [['file' => 'seo/seo.php', 'name' => 'SEO Eklentisi', 'version' => '2.0', 'active' => true, 'update' => '2.1'], ['file' => 'x/x.php', 'name' => 'Güncel', 'version' => '1.0', 'update' => null]],
            'themes' => [], 'site_health' => ['good' => 15, 'recommended' => 4, 'critical' => 2],
        ])]);
        DB::table('uptime_states')->insert(['digital_asset_id' => $this->site->id, 'state' => 'down', 'consecutive_failures' => 3, 'down_since' => now()->subHour(),
            'last_checked_at' => now(), 'last_error' => 'HTTP 503', 'created_at' => now(), 'updated_at' => now()]);

        Livewire::test(HealthTab::class, ['assetId' => $this->site->id])
            ->assertSee('6.5.2 → 6.6.1')->assertSee('Eklenti: SEO Eklentisi')->assertSee('2.0 → 2.1')->assertDontSee('Eklenti: Güncel')
            ->assertSee('2 kritik sorun')->assertSee('Erişilemiyor')->assertSee('HTTP 503')
            ->assertSee('Bitiş tarihi girilmedi')->assertSee("WordPress'e giriş", false)
            ->assertSeeHtml(route('operator.integrations.wordpress-login', ['site' => $this->site->id]))
            ->set('hostingDate', now()->addDays(11)->toDateString())->call('saveHosting')->assertHasNoErrors()
            ->assertSee(now()->addDays(11)->format('d.m.Y'))->assertSee('11 gün kaldı · elle girildi');
        $this->assertSame(now()->addDays(11)->toDateString(), substr((string) $this->site->fresh()->getAttribute('hosting_expires_on'), 0, 10));

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(HealthTab::class, ['assetId' => $this->site->id])->assertDontSee("WordPress'e giriş", false);
    }

    public function test_linked_assets_show_bindings_last_data_and_links(): void
    {
        $gsc = CoreExternalResource::factory()->searchConsole()->create(['display_name' => 'sc-domain:panorama.example']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console']);
        DB::table('gsc_property_daily')->insert(['digital_asset_id' => null, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:panorama.example', 'search_type' => 'web',
            'reporting_date' => '2026-09-26', 'clicks' => 5, 'impressions' => 50, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('d', 64)]);
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => 'active', 'name' => 'Panorama Ads']);

        Livewire::test(LinkedAssetsTab::class, ['assetId' => $this->site->id])
            ->assertSee('26.09.2026')->assertSee('Panorama Ads')
            ->assertSeeHtml(route('operator.search-console', ['assetId' => $this->site->id]))
            ->assertSeeHtml(route('operator.google-ads.overview', ['assetId' => $ads->id]));
    }

    public function test_tabs_render_for_a_site_of_a_passive_customer(): void
    {
        $this->customer->forceFill(['status' => CustomerStatus::Inactive])->save();

        Livewire::test(HealthTab::class, ['assetId' => $this->site->id])->assertOk()->assertSee('Henüz kontrol edilmedi');
        Livewire::test(LinkedAssetsTab::class, ['assetId' => $this->site->id])->assertOk();
    }

    /** @return array<string, mixed> openssl_x509_parse() of a self-signed certificate valid for $days days */
    private function selfSignedCertificate(int $days): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Test CA'], $key);
        $cert = openssl_csr_sign($csr, null, $key, $days);

        return (array) openssl_x509_parse($cert);
    }
}
