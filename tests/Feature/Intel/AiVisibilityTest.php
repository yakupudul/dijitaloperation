<?php

namespace Tests\Feature\Intel;

use App\Ai\Agents\AiVisibilityProbeAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Operator\Market\AiVisibilityPage;
use App\Models\Brand;
use App\Models\BrandServiceArea;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchDemandCompetitor;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Intel\AiVisibilityService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 10c: AI visibility — questions from services × areas, AI on click, brand / competitor detection, history.
 */
final class AiVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompts_check_detection_and_history(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['name' => 'Atlas Diş Kliniği', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'atlasdis.com']);
        app(BrandOfferingService::class)->create($brand, 'İmplant');
        BrandServiceArea::query()->create(['brand_id' => $brand->id, 'country_code' => 'TR', 'city_name' => 'İstanbul', 'district_name' => 'Kadıköy', 'normalized_key' => 'k', 'status' => 'active', 'priority_rank' => 1]);
        SearchDemandCompetitor::query()->create(['uuid' => (string) Str::uuid(), 'brand_id' => $brand->id, 'display_name' => 'Gülüş Diş', 'normalized_domain' => 'gulusdis.com', 'normalized_domain_hash' => hash('sha256', 'gulusdis.com'), 'status' => 'approved']);

        $service = app(AiVisibilityService::class);
        $this->assertSame(['Kadıköy bölgesinde implant için hangi yeri önerirsin?'], $service->prompts($brand));
        $this->assertSame([true, 2], $service->detect($brand, '', ['Gülüş Diş', "Atlas Diş Kliniği'nin Kadıköy şubesi"]), 'suffix tolerant, position in list');
        $this->assertSame([true, null], $service->detect($brand, 'atlasdis.com adresinden randevu alınabilir.', []), 'site host in the answer');
        $this->assertSame([false, null], $service->detect($brand, 'Kadıköy\'de birçok klinik var.', ['Gülüş Diş']));

        AiVisibilityProbeAgent::fake([
            ['answer' => 'Kadıköy\'de Gülüş Diş ve Atlas Diş Kliniği öne çıkıyor.', 'businesses' => ['Gülüş Diş', 'Atlas Diş Kliniği']],
            ['answer' => 'Belirli bir yer bilmiyorum.', 'businesses' => []],
        ]);
        Livewire::test(AiVisibilityPage::class, ['brand' => $brand->id])
            ->assertSet('prompts', 'Kadıköy bölgesinde implant için hangi yeri önerirsin?')
            ->set('prompts', "Kadıköy bölgesinde implant için hangi yeri önerirsin?\nİstanbul'da diş kliniği önerir misin?")
            ->call('check')
            ->assertSee('2. sırada')->assertSee('geçmiyor')->assertSee('Rakipler: Gülüş Diş');

        $rows = DB::table('ai_visibility_checks')->orderBy('id')->get();
        $this->assertSame(['done', 'done'], $rows->pluck('status')->all());
        $this->assertSame([true, false], $rows->pluck('mentioned')->map(fn ($m): bool => (bool) $m)->all());
        $this->assertSame([['rate' => 50, 'mentioned' => 1, 'done' => 2]], array_map(fn (array $h): array => ['rate' => $h['rate'], 'mentioned' => $h['mentioned'], 'done' => $h['done']], $service->history($brand)));
        $this->assertSame(2, DB::table('ai_usage_records')->count(), 'each question is one recorded AI call');

        Livewire::test(AiVisibilityPage::class, ['brand' => $brand->id])->set('prompts', "Soru 1\nSoru 2")->call('savePrompts');
        $this->assertSame(['Soru 1', 'Soru 2'], $service->prompts($brand->fresh()));
        $this->get(route('operator.market.ai-visibility'))->assertOk();
    }
}
