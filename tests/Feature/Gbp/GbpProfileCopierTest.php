<?php

namespace Tests\Feature\Gbp;

use App\Ai\Agents\GbpBranchServicesAgent;
use App\Enums\CustomerStatus;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\ClaudeNote;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Gbp\GbpProfilePlanner;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * moxdop:gbp:copy-profile (yakup, 2026-10-13): one branch's categories and services onto the brand's other branches,
 * same ids and names, a description written for each branch, sent as an ADR-077 write.
 */
final class GbpProfileCopierTest extends TestCase
{
    use RefreshDatabase;

    /** Live profiles as the fake Google holds them, by location id. @var array<string, array<string, mixed>> */
    private array $locations = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $patches = [];

    private Brand $brand;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value', 'moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        User::factory()->create(['is_active' => true])->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Avrupadent']);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addHour()]);

        $clinic = ['name' => 'categories/gcid:dental_clinic', 'displayName' => 'Diş kliniği'];
        $this->profile('1', 'Avrupadent Çiğli Diş Kliniği | İmplant Tedavisi', [
            'primaryCategory' => $clinic,
            'additionalCategories' => [['name' => 'categories/gcid:orthodontist', 'displayName' => 'Ortodontist'], ['name' => 'categories/gcid:pediatric_dentist', 'displayName' => 'Pedodontist']],
        ], [
            ['structuredServiceItem' => ['serviceTypeId' => 'job_type_id:teeth_whitening', 'description' => 'Çiğli şubemizde diş beyazlatma.']],
            ['freeFormServiceItem' => ['category' => 'categories/gcid:dental_clinic', 'label' => ['displayName' => 'Gülüş Tasarımı', 'description' => 'Çiğli şubemizde gülüş tasarımı.', 'languageCode' => 'tr']]],
            ['freeFormServiceItem' => ['category' => 'categories/gcid:orthodontist', 'label' => ['displayName' => 'Şeffaf Plak', 'description' => 'Şeffaf plak tedavisi.', 'languageCode' => 'tr']]],
            ['freeFormServiceItem' => ['category' => 'categories/gcid:pediatric_dentist', 'label' => ['displayName' => 'Fissür Örtücü', 'languageCode' => 'tr']]],
        ]);
        $this->profile('2', 'Avrupadent Buca Forbes Diş Kliniği | İmplant Tedavisi', [
            'primaryCategory' => $clinic,
            'additionalCategories' => [['name' => 'categories/gcid:orthodontist', 'displayName' => 'Ortodontist']],
        ], [
            ['freeFormServiceItem' => ['category' => 'categories/gcid:dental_clinic', 'label' => ['displayName' => 'gülüş tasarımı', 'languageCode' => 'tr']]],
        ]);
        $this->profile('3', 'Avrupadent Karşıyaka Diş Kliniği', $this->locations['1']['categories'], $this->locations['1']['serviceItems']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (preg_match('~/v1/locations/(\d+)~', $url, $m) === 1) {
                if ($request->method() === 'PATCH') {
                    $this->patches[] = [$m[1], $request->data()];
                    $this->locations[$m[1]] = [...$this->locations[$m[1]], ...$request->data()];
                }

                return Http::response($this->locations[$m[1]]);
            }
            if (str_contains($url, 'categories:batchGet')) {
                return Http::response(['categories' => [
                    ['name' => 'categories/gcid:dental_clinic', 'displayName' => 'Diş kliniği', 'serviceTypes' => [['serviceTypeId' => 'job_type_id:teeth_whitening', 'displayName' => 'Diş beyazlatma']]],
                    ['name' => 'categories/gcid:orthodontist', 'displayName' => 'Ortodontist', 'serviceTypes' => []],
                    ['name' => 'categories/gcid:pediatric_dentist', 'displayName' => 'Pedodontist', 'serviceTypes' => []],
                ]]);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });
    }

    /**
     * @param  array<string, mixed>  $categories
     * @param  list<array<string, mixed>>  $services
     */
    private function profile(string $location, string $name, array $categories, array $services): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => $name]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'accounts/11/locations/'.$location, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->locations[$location] = ['categories' => $categories, 'serviceItems' => $services];

        return $asset;
    }

    public function test_without_send_it_only_lists_what_each_branch_lacks(): void
    {
        $this->artisan('moxdop:gbp:copy-profile', ['source' => 'Avrupadent Çiğli'])
            ->expectsOutputToContain('3 kategori, 4 hizmet → 2 şube')
            ->expectsOutputToContain('Avrupadent Buca Forbes Diş Kliniği (#2): 1 kategori, 3 hizmet eksik.')
            ->expectsOutputToContain('Avrupadent Karşıyaka Diş Kliniği (#3): hepsi zaten var.')
            ->assertSuccessful();

        $this->assertSame([], $this->patches);
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_send_adds_the_same_categories_and_services_with_the_branchs_own_descriptions(): void
    {
        GbpBranchServicesAgent::fake([['services' => [
            ['key' => 's0', 'description' => 'Buca Forbes’ta dişlerin tonunu açmak isteyenler için beyazlatma uygulaması.'],
            ['key' => 's1', 'description' => 'Şeffaf plak tedavisi.'],
            ['key' => 's2', 'description' => 'Çocukların azı dişlerini çürüğe karşı koruyan ince kaplama. Bilgi: 0232 555 55 55'],
        ]]]);

        $this->artisan('moxdop:gbp:copy-profile', ['source' => 'Avrupadent Çiğli', '--send' => true, '--wait' => 0])
            ->expectsOutputToContain('2 hizmet açıklamasız')
            ->expectsOutputToContain('succeeded · 1 kategori, 3 hizmet eklendi')
            ->assertSuccessful();

        GbpBranchServicesAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Avrupadent Buca Forbes Diş Kliniği')
            && str_contains((string) $prompt->prompt, 'Çiğli şubemizde diş beyazlatma.'));
        $this->assertSame(['2', '2'], array_column($this->patches, 0), 'only the branch that lacks something is written');
        $this->assertSame(['categories/gcid:orthodontist', 'categories/gcid:pediatric_dentist'],
            array_column($this->locations['2']['categories']['additionalCategories'], 'name'));
        $items = $this->locations['2']['serviceItems'];
        $this->assertCount(4, $items);
        $this->assertSame(['serviceTypeId' => 'job_type_id:teeth_whitening', 'description' => 'Buca Forbes’ta dişlerin tonunu açmak isteyenler için beyazlatma uygulaması.'], $items[1]['structuredServiceItem']);
        $this->assertSame(['categories/gcid:orthodontist', 'Şeffaf Plak'], [$items[2]['freeFormServiceItem']['category'], $items[2]['freeFormServiceItem']['label']['displayName']]);
        $this->assertArrayNotHasKey('description', $items[2]['freeFormServiceItem']['label'], 'the source sentence is not reused');
        $this->assertArrayNotHasKey('description', $items[3]['freeFormServiceItem']['label'], 'a description with a phone number is dropped');
        $plan = AiProduction::query()->where('kind', GbpProfilePlanner::KIND)->sole();
        $this->assertSame(AiProduction::STATUS_USED, $plan->status);
        $this->assertSame('Avrupadent Çiğli Diş Kliniği', $plan->content['input']['copied_from']['name']);
    }

    public function test_report_lists_each_branchs_send_and_keeps_it_as_a_note(): void
    {
        GbpBranchServicesAgent::fake([['services' => []]]);
        $this->artisan('moxdop:gbp:copy-profile', ['source' => '1', '--only' => ['2'], '--send' => true, '--wait' => 0])->assertSuccessful();

        $this->artisan('moxdop:gbp:copy-profile', ['source' => 'Avrupadent Çiğli', '--report' => true])
            ->expectsOutputToContain('Avrupadent Buca Forbes Diş Kliniği (#2): succeeded · 1 kategori, 3 hizmet eklendi')
            ->expectsOutputToContain('Avrupadent Karşıyaka Diş Kliniği (#3): gönderim yok')
            ->assertSuccessful();

        $note = ClaudeNote::query()->sole();
        $this->assertSame($this->brand->id, (int) $note->brand_id);
        $this->assertStringContainsString('kopyalama sonucu', $note->text);
    }

    public function test_a_queued_send_runs_its_ai_as_the_admin_not_as_automatic_work(): void
    {
        config(['moxdop-ai-pricing.automatic_areas' => ['queries']]);
        GbpBranchServicesAgent::fake([['services' => []]]);

        $this->artisan('moxdop:gbp:copy-profile', ['source' => '1', '--only' => ['2'], '--send' => true, '--wait' => 0])->assertSuccessful();

        $this->assertSame(0, ClaudeNote::query()->count(), 'live 2026-10-13: "Uygun AI sağlayıcısı yok" for every branch');
        $this->assertSame(1, ExternalWriteAction::query()->count());
    }

    public function test_a_branch_that_could_not_be_sent_is_noted_with_the_reason(): void
    {
        GbpBranchServicesAgent::fake(fn () => throw new \RuntimeException('Günlük AI tavanı doldu'));

        $this->artisan('moxdop:gbp:copy-profile', ['source' => '1', '--only' => ['2'], '--send' => true, '--wait' => 0])->assertFailed();

        $this->assertSame(0, ExternalWriteAction::query()->count());
        $note = ClaudeNote::query()->sole();
        $this->assertSame($this->brand->id, (int) $note->brand_id);
        $this->assertStringContainsString('Avrupadent Buca Forbes Diş Kliniği (#2): Açıklamalar yazılamadı: Günlük AI tavanı doldu', $note->text);
    }

    public function test_coverage_lists_each_profile_of_the_sector_with_its_live_counts(): void
    {
        $sector = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->brand->update(['sector_id' => $sector->id]);
        $this->locations['2']['categories'] = [];

        $this->artisan('moxdop:gbp:coverage', ['--like-brand' => (string) $this->brand->id])
            ->expectsOutputToContain('Avrupadent · Avrupadent Buca Forbes Diş Kliniği (#2): birincil YOK, 0 ek kategori, 1 hizmet')
            ->expectsOutputToContain('Avrupadent · Avrupadent Karşıyaka Diş Kliniği (#3): birincil Diş kliniği, 2 ek kategori, 4 hizmet')
            ->assertSuccessful();

        $this->assertNull(ClaudeNote::query()->sole()->brand_id);
        $this->assertSame([], $this->patches);
    }
}
