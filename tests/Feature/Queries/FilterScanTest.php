<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\QueryFilterScanAgent;
use App\Livewire\Operator\Library\QueriesPage;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\FilterScanner;
use App\Services\Queries\QueryPlanner;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Filtre sepeti › "Sorgularda tara": place / brand / person / off-topic words of the library queries → the basket on approval. */
final class FilterScanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $dental;

    private ServiceCatalogItem $implant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->implant = app(ServiceCatalogService::class)->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        app(ServiceKeywordService::class)->add($this->implant, 'implant');
        Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Panorama Klinik', 'sector_id' => $this->dental->id]);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        Http::preventStrayRequests();

        foreach ([
            'ankarada implant fiyatları' => 500, 'implant ankara' => 300, 'dentgroup implant' => 200,
            'ayşe yılmaz implant' => 40, 'panorama implant yorumları' => 90, 'implant iş ilanı' => 10, 'implant 2024' => 5,
        ] as $text => $impressions) {
            Query::query()->create(['text' => $text, 'text_hash' => hash('sha256', $text), 'sector_id' => $this->dental->id, 'impressions' => $impressions]);
        }
    }

    public function test_the_scan_lists_places_without_ai_and_the_words_ai_flags_and_saves_only_the_ticked_ones(): void
    {
        $sent = [];
        QueryFilterScanAgent::fake(function (string $prompt) use (&$sent): array {
            $sent = array_column(json_decode(substr($prompt, strlen("DATA_JSON\n")), true)['words'], 'word');

            return ['words' => [
                ['word' => 'dentgroup', 'category' => 'brand', 'reason' => 'rakip klinik'],
                ['word' => 'ayşe', 'category' => 'person', 'reason' => 'kişi adı'],
                ['word' => 'uydurma', 'category' => 'brand', 'reason' => 'listede yok'],
            ], 'prompt_version' => QueryFilterScanAgent::PROMPT_VERSION];
        });

        $page = Livewire::test(QueriesPage::class)->call('setTab', 'filters')->call('scanFilters');
        $scan = QueryPlanner::current($this->admin->id, 'scan');

        $this->assertSame('ready', $scan['status']);
        $this->assertNotContains('implant', $sent, 'a matching keyword is never sent');
        $this->assertNotContains('panorama', $sent, 'the own brand name is never sent');
        $this->assertNotContains('ankarada', $sent, 'place names are found without AI');
        $this->assertNotContains('2024', $sent);
        $this->assertNotContains('fiyatları', $sent, 'intent words are left out');
        $byTerm = collect($scan['items'])->keyBy('term');
        $this->assertSame(['ankara', 'dentgroup', 'ayşe'], $byTerm->keys()->all(), 'places first, then brands, then persons; unknown words dropped');
        $this->assertSame(['place', 2, 800], [$byTerm['ankara']['category'], $byTerm['ankara']['count'], $byTerm['ankara']['impressions']], '"ankarada" and "ankara" are one line');

        $page->assertSee('Yer adı')->assertSee('dentgroup')
            ->call('toggleScanLine', 2)
            ->call('approveScan');

        $this->assertEqualsCanonicalizing(['ankara', 'dentgroup'], FilterTerm::query()->pluck('term')->all());
        $this->assertNull(QueryPlanner::current($this->admin->id, 'scan'));
    }

    public function test_a_category_can_be_unticked_at_once_and_words_already_in_the_basket_are_not_proposed_again(): void
    {
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'dentgroup', 'source' => 'manual', 'created_by' => $this->admin->id]);
        QueryFilterScanAgent::fake(fn (): array => ['words' => [['word' => 'ayşe', 'category' => 'person', 'reason' => 'kişi adı'], ['word' => 'yılmaz', 'category' => 'person', 'reason' => 'soyadı']],
            'prompt_version' => QueryFilterScanAgent::PROMPT_VERSION]);

        Livewire::test(QueriesPage::class)->call('setTab', 'filters')->call('scanFilters')
            ->call('setScanCategory', 'person', false)
            ->call('approveScan');

        $this->assertEqualsCanonicalizing(['dentgroup', 'ankara'], FilterTerm::query()->pluck('term')->all(), 'persons unticked; dentgroup not proposed twice');
    }

    public function test_without_ai_the_place_names_are_still_offered(): void
    {
        CoreIntegration::query()->delete();
        config(['moxdop.anthropic.api_key' => null]);

        $items = app(FilterScanner::class)->scanSector($this->dental);

        $this->assertIsArray($items);
        $this->assertSame(['ankara'], array_column($items, 'term'));
    }
}
