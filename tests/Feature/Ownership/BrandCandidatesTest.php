<?php

namespace Tests\Feature\Ownership;

use App\Ai\Agents\BrandCandidateAgent;
use App\Jobs\RefreshBrandCandidatesJob;
use App\Livewire\Operator\Integrations\DiscoveredAssetsPage;
use App\Models\AiTask;
use App\Models\Brand;
use App\Models\BrandCandidate;
use App\Models\BrandCandidateResource;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Portfolio\BrandCandidateBuilder;
use App\Services\Portfolio\BrandCandidateManager;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 2: discovered websites / accounts → brand candidates (deterministic, then one AI call per batch with sector),
 * operator approve / edit / dismiss, sector stored on the brand only and inherited by assets.
 */
final class BrandCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $google;

    private CoreIntegration $meta;

    private ServiceCategory $dental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'dev']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'dev']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['access_token' => 'atok', 'refresh_token' => 'rtok'], 'expires_at' => now()->addHour()]);
        $this->meta = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        ServiceCategory::query()->firstOrCreate(['code' => 'legal'], ['name' => 'Hukuk', 'normalized_key' => 'hukuk']);
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_deterministic_grouping_joins_website_gsc_ga4_gbp_ads_final_urls_and_names(): void
    {
        $r = $this->resources();

        $summary = app(BrandCandidateBuilder::class)->refresh();

        $this->assertSame('no_provider', $summary['ai_status'], 'no AI configured: deterministic only');
        $panorama = $this->candidateOf($r['gsc']);
        $this->assertEqualsCanonicalizing(
            [$r['ga4']->id, $r['gbp']->id, $r['ads']->id, $r['metaAds']->id, $r['gsc']->id],
            $panorama->members()->whereNotNull('external_resource_id')->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->all(),
            'GSC, GA4 stream, GBP website, Ads final URL join by host; Meta by name',
        );
        $this->assertSame($r['site']->id, (int) $panorama->members()->whereNotNull('website_asset_id')->value('website_asset_id'), 'the brandless (connector) website joins by host');
        $this->assertSame('Panorama Ankara Diş Kliniği', $panorama->name, 'Business Profile title names the candidate');
        $this->assertSame(['panorama.com.tr'], $panorama->hosts());
        $this->assertSame('Diş kliniği', data_get($panorama->signals, 'gbp_category'));
        $this->assertSame(0.9, $panorama->confidence);
        $this->assertStringContainsString('Hesap adı', (string) $panorama->members()->where('external_resource_id', $r['metaAds']->id)->value('reason'), 'Meta account joins by name');

        $this->assertNotSame($panorama->id, $this->candidateOf($r['other'])->id, 'another host is another candidate');
        $this->assertSame(BrandCandidate::PROPOSED, $this->candidateOf($r['leftover'])->status, 'without AI a leftover is its own candidate');
        $this->assertFalse(BrandCandidateResource::query()->where('external_resource_id', $r['bound']->id)->exists(), 'bound accounts are never proposed');
        $this->assertFalse(BrandCandidateResource::query()->where('external_resource_id', $r['manager']->id)->exists(), 'manager accounts are never proposed');

        // Re-run: nothing moves, a new resource joins the proposed candidate by host.
        $new = $this->make('search_console', 'sc-domain:www.panorama.com.tr', 'www.panorama.com.tr', ['site_url' => 'https://www.panorama.com.tr/']);
        app(BrandCandidateBuilder::class)->refresh();
        $this->assertSame($panorama->id, $this->candidateOf($new)->id);
        $this->assertSame(3, BrandCandidate::query()->count());
    }

    public function test_ai_groups_leftovers_and_proposes_sector_from_the_most_reliable_signal(): void
    {
        $this->enableAi();
        $r = $this->resources();
        $extra = $this->make('google_ads', '4242', 'PNR Kampanya Hesabı', ['descriptive_name' => 'PNR Kampanya Hesabı']);
        $prompts = [];
        BrandCandidateAgent::fake(function (string $prompt) use (&$prompts, $r, $extra): array {
            $prompts[] = $prompt;
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $panoramaKey = collect($data['candidates'])->firstWhere('name', 'Panorama Ankara Diş Kliniği')['key'];
            $otherKey = collect($data['candidates'])->first(fn (array $c): bool => in_array('baska-hukuk.com', $c['hosts'], true))['key'];

            return [
                'groups' => [
                    ['candidate_key' => $panoramaKey, 'name' => 'Panorama', 'account_keys' => ['r:'.$extra->id, 'r:999999']],
                    ['candidate_key' => null, 'name' => 'Xyz Holding', 'account_keys' => ['r:'.$r['leftover']->id]],
                ],
                'sectors' => [
                    ['key' => $panoramaKey, 'sector_code' => 'dental', 'signal' => 'gbp_category', 'reason' => 'İşletme kategorisi: Diş kliniği', 'confidence' => 0.95],
                    ['key' => $otherKey, 'sector_code' => 'uydurma', 'signal' => 'site', 'reason' => 'x', 'confidence' => 0.9],
                    ['key' => 'new:1', 'sector_code' => 'legal', 'signal' => 'ads', 'reason' => 'Hesap adı', 'confidence' => 0.4],
                ],
                'prompt_version' => BrandCandidateAgent::PROMPT_VERSION,
            ];
        });

        $summary = app(BrandCandidateBuilder::class)->refresh();

        $this->assertSame(1, $summary['ai_calls'], 'one AI call for the batch');
        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('Diş kliniği', $prompts[0], 'GBP primary category is sent as the sector signal');
        $panorama = $this->candidateOf($r['gsc']);
        $this->assertSame($panorama->id, $this->candidateOf($extra)->id, 'AI placed the leftover into the candidate');
        $this->assertSame($this->dental->id, $panorama->sector_id);
        $this->assertSame('gbp_category', $panorama->sector_signal, 'which signal decided the sector is stored');
        $this->assertSame('İşletme kategorisi: Diş kliniği', $panorama->sector_reason);
        $this->assertNull($this->candidateOf($r['other'])->sector_id, 'an unknown sector code is dropped');
        $xyz = $this->candidateOf($r['leftover']);
        $this->assertSame('Xyz Holding', $xyz->name);
        $this->assertSame('ai', $xyz->method);
        $this->assertSame('ads', $xyz->sector_signal);

        // Checked candidates are not sent again.
        app(BrandCandidateBuilder::class)->refresh();
        $this->assertCount(1, $prompts, 'nothing new: no second AI call');
    }

    public function test_approve_creates_customer_brand_with_sector_and_assets_that_inherit_it(): void
    {
        $r = $this->resources();
        app(BrandCandidateBuilder::class)->refresh();
        $candidate = $this->candidateOf($r['gsc']);
        app(BrandCandidateManager::class)->setSector($candidate, $this->dental->id);

        Livewire::test(DiscoveredAssetsPage::class)
            ->assertSee('Panorama Ankara Diş Kliniği')
            ->set('customerName.'.$candidate->id, 'Panorama Sağlık A.Ş.')
            ->call('approve', $candidate->id)
            ->assertSee('Panorama Ankara Diş Kliniği hazır');

        $customer = Customer::query()->where('name', 'Panorama Sağlık A.Ş.')->firstOrFail();
        $brand = Brand::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($this->dental->id, $brand->sector_id, 'sector stored on the brand');
        $this->assertSame('dental', $brand->sector, 'legacy code mirrors sector_id');
        $site = DigitalAsset::query()->findOrFail($r['site']->id);
        $this->assertSame($brand->id, $site->brand_id, 'the brandless website joined the brand');
        $this->assertEqualsCanonicalizing(['ga4', 'search_console'], CoreAssetBinding::query()->where('digital_asset_id', $site->id)->where('status', 'active')->pluck('capability')->all());
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->get();
        $this->assertEqualsCanonicalizing(['website', 'google_business_profile', 'google_ads', 'meta_ads'], $assets->pluck('type')->unique()->values()->all());
        foreach ($assets as $asset) {
            $this->assertSame($this->dental->id, $asset->sector()?->id, 'asset '.$asset->type.' inherits the brand sector');
        }
        $this->assertSame(BrandCandidate::APPROVED, $candidate->fresh()->status);
        $this->assertSame($brand->id, $candidate->fresh()->brand_id);

        // Approved groupings never change automatically and cannot be decided twice.
        app(BrandCandidateBuilder::class)->refresh();
        $this->assertSame(BrandCandidate::APPROVED, $candidate->fresh()->status);
        $this->expectException(ValidationException::class);
        app(BrandCandidateManager::class)->dismiss($candidate->fresh(), $this->admin);
    }

    public function test_approve_into_existing_customer_and_existing_brand_by_website(): void
    {
        $customer = Customer::factory()->create(['name' => 'Mevcut Müşteri']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama', 'sector_id' => $this->dental->id]);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'primary_url' => 'https://panorama.com.tr/', 'domain' => 'panorama.com.tr']);
        $gsc = $this->make('search_console', 'sc-domain:panorama.com.tr', 'panorama.com.tr', ['site_url' => 'sc-domain:panorama.com.tr']);
        $ads = $this->make('google_ads', '1234567890', 'Hukuk Bürosu Ads', ['descriptive_name' => 'Hukuk Bürosu Ads']);
        app(BrandCandidateBuilder::class)->refresh();

        $candidate = $this->candidateOf($gsc);
        $this->assertSame($brand->id, data_get($candidate->signals, 'existing_brand_id'));
        $brands = Brand::query()->count();
        app(BrandCandidateManager::class)->approve($candidate, [], $this->admin);
        $this->assertSame($brands, Brand::query()->count(), 'no new brand');
        $this->assertTrue(CoreAssetBinding::query()->where('external_resource_id', $gsc->id)->where('status', 'active')->exists());

        $adsCandidate = $this->candidateOf($ads);
        $outcome = app(BrandCandidateManager::class)->approve($adsCandidate, ['customer_id' => $customer->id, 'brand_name' => 'Hukuk'], $this->admin);
        $this->assertSame($customer->id, $outcome['brand']->customer_id, 'existing customer chosen');
        $this->assertNull($outcome['brand']->sector_id, 'no sector proposed → none stored');
    }

    public function test_edit_move_rename_sector_and_dismiss(): void
    {
        $r = $this->resources();
        app(BrandCandidateBuilder::class)->refresh();
        $panorama = $this->candidateOf($r['gsc']);
        $metaMember = BrandCandidateResource::query()->where('external_resource_id', $r['metaAds']->id)->firstOrFail();
        $other = $this->candidateOf($r['other']);

        Livewire::test(DiscoveredAssetsPage::class)
            ->call('edit', $panorama->id)
            ->set('moveTo.'.$metaMember->id, (string) $other->id)
            ->call('move', $metaMember->id)
            ->set('names.'.$panorama->id, 'Panorama Ankara')
            ->set('sectorFor.'.$panorama->id, (string) $this->dental->id)
            ->call('saveEdit', $panorama->id)
            ->call('dismiss', $this->candidateOf($r['leftover'])->id);

        $this->assertSame($other->id, $this->candidateOf($r['metaAds'])->id);
        $panorama->refresh();
        $this->assertSame('Panorama Ankara', $panorama->name);
        $this->assertSame($this->dental->id, $panorama->sector_id);
        $this->assertSame('manual', $panorama->sector_signal);
        $this->assertSame(BrandCandidate::DISMISSED, $this->candidateOf($r['leftover'])->status);

        // Moving every member out of a candidate removes the empty one.
        $manager = app(BrandCandidateManager::class);
        $alone = $manager->move(BrandCandidateResource::query()->where('external_resource_id', $r['other']->id)->firstOrFail(), null);
        $this->assertSame('manual', $alone->method);
        $manager->move(BrandCandidateResource::query()->where('external_resource_id', $r['metaAds']->id)->firstOrFail(), $alone);
        $this->assertNull(BrandCandidate::query()->find($other->id));
        app(BrandCandidateBuilder::class)->refresh();
        $this->assertSame(BrandCandidate::DISMISSED, $this->candidateOf($r['leftover'])->status, 'dismissed stays dismissed on re-run');
    }

    public function test_a_resource_is_in_at_most_one_candidate(): void
    {
        $r = $this->resources();
        app(BrandCandidateBuilder::class)->refresh();
        $this->assertSame(1, BrandCandidateResource::query()->where('external_resource_id', $r['gsc']->id)->count());
        $this->expectException(UniqueConstraintViolationException::class);
        BrandCandidateResource::query()->create(['brand_candidate_id' => $this->candidateOf($r['other'])->id, 'external_resource_id' => $r['gsc']->id]);
    }

    public function test_resource_binds_to_one_asset_and_candidate_member_leaves_once_bound(): void
    {
        $r = $this->resources();
        app(BrandCandidateBuilder::class)->refresh();
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'name' => 'Ads']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $r['leftover']->id, 'capability' => 'google_ads', 'status' => 'active']);

        app(BrandCandidateBuilder::class)->refresh();

        $this->assertFalse(BrandCandidateResource::query()->where('external_resource_id', $r['leftover']->id)->exists(), 'a bound resource leaves its proposed candidate');
        $second = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'name' => 'Ads 2']);
        $conflict = app(OwnershipGuard::class)->forResource($r['leftover']->fresh(), $second);
        $this->assertNotNull($conflict, 'resource ↔ one asset: binding it elsewhere is a conflict');
    }

    private function candidateOf(CoreExternalResource $resource): BrandCandidate
    {
        return BrandCandidateResource::query()->where('external_resource_id', $resource->id)->firstOrFail()->candidate;
    }

    public function test_failed_ai_call_leaves_leftovers_unplaced_for_the_next_run(): void
    {
        $this->enableAi();
        $r = $this->resources();
        BrandCandidateAgent::fake(fn (): array => throw new \RuntimeException('Invalid schema for response_format'));

        $summary = app(BrandCandidateBuilder::class)->refresh();

        $this->assertSame('error', $summary['ai_status']);
        $this->assertSame('Invalid schema for response_format', $summary['ai_error']);
        $this->assertFalse(BrandCandidateResource::query()->where('external_resource_id', $r['leftover']->id)->exists(), 'no per-account candidate on an AI error');
        $this->assertNotNull($this->candidateOf($r['gsc']), 'deterministic grouping still runs');

        BrandCandidateAgent::fake([['groups' => [['candidate_key' => null, 'name' => 'Xyz Holding', 'account_keys' => ['r:'.$r['leftover']->id]]], 'sectors' => [], 'prompt_version' => BrandCandidateAgent::PROMPT_VERSION]]);
        $retry = app(BrandCandidateBuilder::class)->refresh();
        $this->assertSame('called', $retry['ai_status']);
        $this->assertSame('Xyz Holding', $this->candidateOf($r['leftover'])->name, 'the next run retries the leftovers');
    }

    public function test_delegated_grouping_waits_for_claude_and_places_the_leftovers_from_the_answer(): void
    {
        $this->enableAi();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $registry = app(PromptRegistry::class);
        $registry->publish(AiRouteKeys::BRAND_CANDIDATES, ['template' => (string) $registry->current(AiRouteKeys::BRAND_CANDIDATES)->template, 'model' => AiTaskQueue::MODEL], $this->admin);
        BrandCandidateAgent::fake()->preventStrayPrompts();
        $r = $this->resources();

        app()->call([new RefreshBrandCandidatesJob, 'handle']);

        $task = AiTask::query()->sole();
        $this->assertSame(AiRouteKeys::BRAND_CANDIDATES, $task->operation);
        $this->assertFalse(BrandCandidateResource::query()->where('external_resource_id', $r['leftover']->id)->exists(), 'leftovers wait for Claude');
        $this->assertNotNull($this->candidateOf($r['gsc']), 'deterministic grouping does not wait');
        BrandCandidateAgent::assertNeverPrompted();

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['groups' => [['candidate_key' => null, 'name' => 'Xyz Holding', 'account_keys' => ['r:'.$r['leftover']->id]]],
            'sectors' => [], 'prompt_version' => BrandCandidateAgent::PROMPT_VERSION]));
        Bus::assertDispatched(RefreshBrandCandidatesJob::class);
        app()->call([new RefreshBrandCandidatesJob, 'handle']);

        $this->assertSame('Xyz Holding', $this->candidateOf($r['leftover'])->name);
        $this->assertSame(AiTask::CONSUMED, $task->fresh()->status);
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @return array<string, mixed> */
    private function resources(): array
    {
        $site = DigitalAsset::factory()->create(['brand_id' => null, 'type' => 'website', 'name' => 'panorama.com.tr', 'domain' => 'panorama.com.tr', 'primary_url' => 'https://panorama.com.tr']);
        $ads = $this->make('google_ads', '1112223333', 'PNR-2024', ['descriptive_name' => 'PNR-2024']);
        DB::table('google_ads_ad_snapshot')->insert([
            'digital_asset_id' => null, 'external_resource_id' => $ads->id, 'customer_id' => '1112223333', 'ad_id' => '1', 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('a', 64),
            'metadata' => json_encode(['final_urls' => ['https://www.panorama.com.tr/implant']]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'site' => $site,
            'gsc' => $this->make('search_console', 'sc-domain:panorama.com.tr', 'panorama.com.tr', ['site_url' => 'sc-domain:panorama.com.tr']),
            'ga4' => $this->make('ga4', 'properties/111', 'Web', ['web_stream_uris' => ['https://www.panorama.com.tr']]),
            'gbp' => $this->make('google_business_profile', 'locations/1', 'Panorama Ankara Diş Kliniği', ['website_uri' => 'https://panorama.com.tr/?utm_source=gbp', 'primary_category' => 'Diş kliniği']),
            'ads' => $ads,
            'metaAds' => CoreExternalResource::factory()->create([
                'integration_id' => $this->meta->id, 'provider' => 'meta', 'resource_type' => 'meta_ads', 'external_id' => 'act_55',
                'display_name' => 'Panorama Diş Meta', 'metadata' => ['selectable' => true], 'status' => CoreExternalResource::STATUS_AVAILABLE,
            ]),
            'other' => $this->make('search_console', 'sc-domain:baska-hukuk.com', 'baska-hukuk.com', ['site_url' => 'sc-domain:baska-hukuk.com']),
            'leftover' => $this->make('google_ads', '5556667777', 'XYZ Holding 2', ['descriptive_name' => 'XYZ Holding 2']),
            'manager' => $this->make('google_ads', '999', 'Ajans MCC', ['descriptive_name' => 'Ajans MCC', 'is_manager' => true]),
            'bound' => tap($this->make('search_console', 'sc-domain:bagli.com', 'bagli.com', ['site_url' => 'sc-domain:bagli.com']), function (CoreExternalResource $bound): void {
                CoreAssetBinding::factory()->create(['external_resource_id' => $bound->id, 'capability' => 'search_console']);
            }),
        ];
    }

    /** @param  array<string, mixed>  $meta */
    private function make(string $type, string $externalId, string $name, array $meta = []): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $name, 'metadata' => $meta + ['selectable' => true], 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
    }
}
