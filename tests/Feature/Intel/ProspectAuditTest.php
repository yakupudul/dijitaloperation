<?php

namespace Tests\Feature\Intel;

use App\Livewire\Demo\Sales\ProspectShow;
use App\Models\CoreIntegration;
use App\Models\Prospect;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Intel\DataForSeoTaskQueue;
use App\Services\Intel\ProspectAuditService;
use App\Services\Intel\PublicPageReader;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 8f: external audit of a prospect (public website checks + one Maps search) and the printable report.
 */
final class ProspectAuditTest extends TestCase
{
    use RefreshDatabase;

    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.dataforseo.base_url' => 'https://api.dataforseo.com', 'moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $integration = CoreIntegration::factory()->dataforseo()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(DataForSeoProviderCredentialService::class)->save($integration, ['login' => 'agency@example.com', 'password' => 'secret'], $admin);
        $this->prospect = Prospect::factory()->create(['company_name' => 'Gülüş Diş', 'website_url' => 'https://gulusdis.com', 'contact_phone' => '0216 555 12 34']);

        $pages = [
            'https://gulusdis.com' => '<html><head><title>Gülüş Diş Kliniği | Kadıköy İmplant ve Estetik</title><meta name="viewport" content="width=device-width"></head><body><h1>Gülüş</h1><a href="tel:+902165551234">Ara</a><form></form><script src="https://www.googletagmanager.com/gtm.js"></script></body></html>',
            'https://gulusdis.com/sitemap.xml' => '<urlset><url><loc>https://gulusdis.com/</loc></url></urlset>',
        ];
        $this->mock(PublicPageReader::class, function ($mock) use ($pages): void {
            $mock->shouldReceive('fetch')->andReturnUsing(function (string $url) use ($pages): array {
                $body = $pages[$url] ?? null;

                return ['ok' => $body !== null, 'status_code' => $body !== null ? 200 : 404, 'body' => $body, 'content_type' => 'text/html', 'final_url' => $url, 'error' => $body !== null ? null : 'http_404'];
            });
        });
    }

    public function test_website_checks_maps_presence_and_print(): void
    {
        $checks = app(ProspectAuditService::class)->websiteChecks('https://gulusdis.com');
        $this->assertTrue($checks['reachable']);
        foreach (['https', 'title', 'h1', 'viewport', 'phone', 'form', 'analytics', 'indexable', 'sitemap', 'weight'] as $key) {
            $this->assertTrue($checks['checks'][$key], $key);
        }
        foreach (['description', 'whatsapp', 'pixel', 'schema'] as $key) {
            $this->assertFalse($checks['checks'][$key], $key);
        }
        $this->assertSame(71, $checks['score']);
        $this->assertSame(['2165551234'], $checks['phones']);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/serp/google/maps/task_post')) {
                return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'tasks' => [['id' => '00000000-0000-0000-0000-000000000001', 'status_code' => 20100, 'cost' => 0.0012]]]);
            }

            return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'tasks' => [['id' => 'x', 'status_code' => 20000, 'result' => [['items' => [
                ['type' => 'maps_search', 'rank_group' => 1, 'title' => 'Rakip Klinik', 'rating' => ['value' => 4.9, 'votes_count' => 500]],
                ['type' => 'maps_search', 'rank_group' => 2, 'title' => 'Başka', 'rating' => ['value' => 4.5, 'votes_count' => 90]],
                ['type' => 'maps_search', 'rank_group' => 3, 'title' => 'Üçüncü'],
                ['type' => 'maps_search', 'rank_group' => 6, 'title' => 'Gülüş Ağız ve Diş', 'phone' => '+90 216 555 12 34', 'rating' => ['value' => 4.2, 'votes_count' => 31]],
            ]]]]]]);
        });

        Livewire::test(ProspectShow::class, ['prospectId' => (string) $this->prospect->id])
            ->set('tab', 'audit')->set('auditKeyword', 'diş kliniği kadıköy')->call('runAudit')
            ->assertSee('site puanı %71')->assertSee('sonuç bekleniyor');
        $audit = DB::table('prospect_audits')->first();
        $this->assertSame('running', $audit->status);
        $this->travel(2)->minutes();
        app(DataForSeoTaskQueue::class)->collect();
        $maps = json_decode((string) DB::table('prospect_audits')->value('maps'), true);
        $this->assertSame([true, 6, 4.2], [$maps['found'], $maps['ours']['rank'], $maps['ours']['rating']], 'matched by phone');
        $this->assertSame('Rakip Klinik', $maps['top3'][0]['title']);

        $this->get(route('operator.prospect.audit.print', ['prospectId' => $this->prospect->id, 'auditId' => $audit->id]))->assertOk()
            ->assertSee('Dijital ön denetim: Gülüş Diş')->assertSee('6. sırada', false)->assertSee('Meta pikseli')->assertSee('Rakip Klinik');
        $this->get(route('operator.prospect.audit.print', ['prospectId' => $this->prospect->id, 'auditId' => 999]))->assertNotFound();
    }

    public function test_cap_and_missing_inputs(): void
    {
        config(['moxdop-intel.prospect_audit.monthly_usd' => 0.0001]);
        try {
            app(ProspectAuditService::class)->run($this->prospect, 'diş kliniği');
            $this->fail('cap');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tavan', collect($exception->errors())->flatten()->first());
        }
        $id = app(ProspectAuditService::class)->run($this->prospect);
        $this->assertSame('completed', DB::table('prospect_audits')->where('id', $id)->value('status'), 'website only: no paid call');

        $empty = Prospect::factory()->create(['website_url' => null]);
        Livewire::test(ProspectShow::class, ['prospectId' => (string) $empty->id])->call('runAudit')->assertSee('Web sitesi ya da harita arama kelimesi gerekli');
    }
}
