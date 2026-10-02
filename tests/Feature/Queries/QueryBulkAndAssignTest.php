<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\QueryAssignServicesAgent;
use App\Ai\Agents\QueryPlanFiltersAgent;
use App\Enums\NotificationKind;
use App\Livewire\Operator\Library\QueriesPage;
use App\Livewire\Operator\Library\QueryPlanWizard;
use App\Models\Brand;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\QueryReview;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QueryServiceAssigner;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Sorgular: hizmet ataması kuyruğu ("AI ile hizmet öner"), toplu seçim / işlemler, Atanmış / Atanmamış, filtre talimatı. */
final class QueryBulkAndAssignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $dental;

    private ServiceCategory $hair;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $zirkonyum;

    private ServiceCatalogItem $fue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->hair = ServiceCategory::query()->firstOrCreate(['code' => 'hair'], ['name' => 'Saç ekimi', 'normalized_key' => 'sac ekimi']);
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        $this->zirkonyum = $catalog->resolveOrCreate('Zirkonyum Kaplama', 'dental', actor: $this->admin)['service'];
        $this->fue = $catalog->resolveOrCreate('FUE Saç Ekimi', 'hair', actor: $this->admin)['service'];
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'sector_id' => $this->dental->id]);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        Http::preventStrayRequests();
    }

    public function test_ai_assignment_queue_proposes_validated_services_in_batches_and_approve_applies_the_ticked_lines(): void
    {
        $assigned = $this->libraryQuery('implant fiyatları', $this->dental, 500, $this->implant);
        $hidden = $this->libraryQuery('gizli sorgu', $this->dental, 400, hidden: true);
        $noSector = $this->libraryQuery('sektörsüz sorgu', null, 300);
        $dental = [];
        for ($i = 1; $i <= 201; $i++) {
            $dental[$i] = $this->libraryQuery('diş sorgusu '.$i, $this->dental, 1000 - $i);
        }
        $hairQuery = $this->libraryQuery('saç ekimi türkiye', $this->hair, 50);
        $prompts = [];
        QueryAssignServicesAgent::fake(function (string $prompt) use (&$prompts, $dental, $hairQuery, $assigned): array {
            $prompts[] = $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            if ($data['sector']['id'] === $this->hair->id) {
                return ['assignments' => [
                    ['query_id' => $hairQuery->id, 'service_id' => $this->fue->id, 'reason' => 'Saç ekimi'],
                ], 'keywords' => [], 'prompt_version' => QueryAssignServicesAgent::PROMPT_VERSION];
            }
            if (count($data['queries']) === 1) {
                return ['assignments' => [['query_id' => $dental[201]->id, 'service_id' => $this->implant->id, 'reason' => 'son parti']], 'keywords' => [], 'prompt_version' => 'x'];
            }

            return ['assignments' => [
                ['query_id' => $dental[1]->id, 'service_id' => $this->zirkonyum->id, 'reason' => 'Zirkonyum'],
                ['query_id' => $dental[2]->id, 'service_id' => $this->implant->id, 'reason' => 'İmplant'],
                ['query_id' => $dental[2]->id, 'service_id' => $this->zirkonyum->id, 'reason' => 'ikinci kez'],
                ['query_id' => $dental[3]->id, 'service_id' => $this->fue->id, 'reason' => 'başka sektörün hizmeti'],
                ['query_id' => $dental[4]->id, 'service_id' => null, 'reason' => 'hiçbiri'],
                ['query_id' => 999999, 'service_id' => $this->implant->id, 'reason' => 'bilinmeyen sorgu'],
                ['query_id' => $assigned->id, 'service_id' => $this->zirkonyum->id, 'reason' => 'partide yok'],
                ['query_id' => $dental[5]->id, 'service_id' => 424242, 'reason' => 'bilinmeyen hizmet'],
            ], 'keywords' => [
                ['service_id' => $this->zirkonyum->id, 'keyword' => 'diş sorgusu 1', 'reason' => 'veride var'],
                ['service_id' => $this->zirkonyum->id, 'keyword' => 'fiyat', 'reason' => 'genel'],
                ['service_id' => $this->zirkonyum->id, 'keyword' => 'lamina', 'reason' => 'veride yok'],
                ['service_id' => $this->zirkonyum->id, 'keyword' => 'implant', 'reason' => 'başka hizmetin'],
            ], 'prompt_version' => QueryAssignServicesAgent::PROMPT_VERSION];
        });

        $page = Livewire::test(QueriesPage::class)->set('service', '__none')
            ->assertSee('Hizmet ataması kuyruğu · 202 atanmamış sorgu')
            ->call('suggestServices')
            ->assertSee('AI hizmet önerisi')->assertSee('diş sorgusu 1')->assertSee('Zirkonyum Kaplama')->assertSee('Eklenecek eşleme kelimeleri · 1');

        $this->assertCount(3, $prompts, 'dental 200 + 1, hair 1: every batch, one sector per call');
        $this->assertSame([200, 1, 1], array_map(fn (array $d): int => count($d['queries']), $prompts));
        $this->assertSame([$this->dental->id, $this->dental->id, $this->hair->id], array_map(fn (array $d): int => $d['sector']['id'], $prompts));
        $sent = collect($prompts)->flatMap(fn (array $d): array => array_column($d['queries'], 'id'))->all();
        $this->assertNotContains($hidden->id, $sent, 'hidden queries are not queued');
        $this->assertNotContains($noSector->id, $sent, 'queries without a sector are not queued');
        $this->assertNotContains($assigned->id, $sent);
        $this->assertSame(['Diş İmplantı', 'Zirkonyum Kaplama'], collect($prompts[0]['services'])->pluck('name')->sort()->values()->all(), 'the sector\'s services');
        $this->assertSame(['implant'], collect($prompts[0]['services'])->firstWhere('id', $this->implant->id)['keywords']);

        $proposal = QueryServiceAssigner::current($this->admin->id);
        $this->assertSame('ready', $proposal['status']);
        $this->assertSame(
            [[$dental[1]->id, $this->zirkonyum->id], [$dental[2]->id, $this->implant->id], [$dental[201]->id, $this->implant->id], [$hairQuery->id, $this->fue->id]],
            array_map(fn (array $item): array => [$item['query_id'], $item['service_id']], $proposal['items']),
            'unknown / foreign ids, other-sector services and duplicates dropped',
        );
        $this->assertSame(['diş sorgusu 1'], array_column($proposal['keywords'], 'keyword'), 'generic, absent and taken keywords dropped');
        $this->assertNull($dental[1]->fresh()->service_id, 'nothing changes before approval');
        $notice = UserNotification::query()->where('recipient_user_id', $this->admin->id)->where('notification_kind', NotificationKind::QueriesNotice->value)->sole();
        $this->assertSame('Hizmet önerisi hazır: 4 sorgu', $notice->presentation['title']);

        // The operator unticks one line and the keyword, then approves in one click; a query assigned meanwhile keeps its service.
        $dental[201]->forceFill(['service_id' => $this->zirkonyum->id])->save();
        $page->call('toggleAssign', 1)->call('toggleAssignKeyword', 0)->call('approveAssignments')->assertSee('2 sorguya hizmet atandı');

        $this->assertSame([$this->zirkonyum->id, 'ai', true], [$dental[1]->fresh()->service_id, $dental[1]->fresh()->assignment, $dental[1]->fresh()->locked]);
        $this->assertNull($dental[2]->fresh()->service_id, 'unticked line stays unassigned');
        $this->assertSame($this->zirkonyum->id, $dental[201]->fresh()->service_id, 'only still unassigned queries change');
        $this->assertSame($this->fue->id, $hairQuery->fresh()->service_id);
        $this->assertSame(0, $this->zirkonyum->matchingKeywords()->count(), 'unticked keyword not added');
        $this->assertSame(0, QueryReview::query()->count(), 'no keyword → no rescan');
        $this->assertNull(QueryServiceAssigner::current($this->admin->id));
    }

    public function test_ticked_keyword_is_added_and_starts_a_rescan_and_an_empty_queue_is_reported(): void
    {
        $query = $this->libraryQuery('zirkonyum diş fiyatı', $this->dental, 10);
        QueryAssignServicesAgent::fake(fn (): array => ['assignments' => [['query_id' => $query->id, 'service_id' => $this->zirkonyum->id, 'reason' => 'Zirkonyum']],
            'keywords' => [['service_id' => $this->zirkonyum->id, 'keyword' => 'Zirkonyum', 'reason' => 'veride var']], 'prompt_version' => 'x']);

        Livewire::test(QueriesPage::class)->call('suggestServices')->assertSee('Eklenecek eşleme kelimeleri')
            ->call('approveAssignments')->assertSee('1 sorguya hizmet atandı · 1 eşleme kelimesi eklendi');

        $this->assertSame(['zirkonyum'], $this->zirkonyum->matchingKeywords()->pluck('normalized_key')->all());
        $this->assertSame(1, QueryReview::query()->count(), 'keyword change goes through the rescan review');
        $this->assertSame($this->zirkonyum->id, $query->fresh()->service_id);

        Livewire::test(QueriesPage::class)->call('suggestServices')->assertSee('Hizmeti atanmamış (sektörü olan) sorgu yok.');
    }

    public function test_assigned_and_unassigned_filters(): void
    {
        $this->libraryQuery('implant fiyatları', $this->dental, 100, $this->implant);
        $this->libraryQuery('diş taşı temizliği', $this->dental, 50);

        Livewire::test(QueriesPage::class)
            ->set('service', '__any')->assertSee('implant fiyatları')->assertDontSee('diş taşı temizliği')
            ->set('service', '__none')->assertSee('diş taşı temizliği')->assertDontSee('implant fiyatları')
            ->set('service', '')->assertSee('diş taşı temizliği')->assertSee('implant fiyatları');
    }

    public function test_select_all_matching_applies_bulk_actions_by_query_across_pages_minus_unticked_rows(): void
    {
        $other = $this->libraryQuery('saç ekimi fiyatı', $this->hair, 5000);
        $ids = [];
        for ($i = 1; $i <= 60; $i++) {
            $ids[$i] = $this->libraryQuery('diş sorgusu '.$i, $this->dental, 1000 - $i)->id;
        }
        $cluster = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'Eski küme']);
        ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $ids[55]]);

        $page = Livewire::test(QueriesPage::class)->set('sector', (string) $this->dental->id)
            ->call('selectPage')->assertSee('seçili 50');
        $this->assertCount(50, $page->get('selected'), 'the page\'s rows');

        $page->call('selectAllMatching')->assertSet('selectAll', true)->assertSee('Filtreye uyan 60 sorgunun tümü seçili')
            ->call('toggleExcluded', $ids[1])->assertSee('seçili 59')
            ->set('bulkService', (string) $this->zirkonyum->id)->call('assignSelected')->assertSee('59 sorgu hizmete atandı')
            ->assertSet('selectAll', false);

        $this->assertNull(Query::query()->find($ids[1])->service_id, 'unticked row untouched');
        $this->assertSame(59, Query::query()->where('service_id', $this->zirkonyum->id)->where('locked', true)->where('assignment', 'manual')->count());
        $this->assertNull($other->fresh()->service_id, 'outside the filter untouched');
        $this->assertSame(0, ClusterQuery::query()->count(), 'left the other service\'s cluster');

        // Filter → all matching → hizmeti kaldır, then sil (hide).
        $page->set('service', (string) $this->zirkonyum->id)->call('selectAllMatching')->call('unassignSelected')->assertSee('59 sorgunun hizmeti kaldırıldı');
        $this->assertSame(0, Query::query()->where('service_id', $this->zirkonyum->id)->count());
        $this->assertSame(60, Query::query()->where('sector_id', $this->dental->id)->whereNull('service_id')->count());
        $this->assertTrue(Query::query()->find($ids[2])->locked, 'kept unassigned on rescans');

        $page->set('service', '__none')->set('search', 'sorgusu 1')->call('selectAllMatching')->call('hideSelected')->assertSee('11 sorgu gizlendi');
        $this->assertSame(11, Query::query()->where('hidden', true)->count(), 'diş sorgusu 1, 10–19');
        $this->assertFalse($other->fresh()->hidden);

        // Filtreye ekle takes the matching queries (most impressions first).
        $page->set('search', 'sorgusu 2')->call('selectAllMatching')->call('openNegatives')->assertSet('negOpen', true);
        $this->assertSame('diş sorgusu 2', explode("\n", $page->get('negText'))[0]);
    }

    public function test_operator_instruction_reaches_the_filter_ai_payload_in_the_wizard_and_the_filter_basket(): void
    {
        FilterTerm::query()->create(['sector_id' => $this->dental->id, 'term' => 'forum']);
        $prompts = [];
        QueryPlanFiltersAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['terms' => [
                ['sector_id' => $this->dental->id, 'term' => 'iş ilanı', 'reason' => 'İş arayan'],
                ['sector_id' => $this->dental->id, 'term' => 'kursu', 'reason' => 'Eğitim'],
            ], 'prompt_version' => QueryPlanFiltersAgent::PROMPT_VERSION];
        });

        Livewire::test(QueryPlanWizard::class)->call('goTo', 3)
            ->set('filterInstruction', "  iş ilanı ve   eğitim içerikli\nkelimeler üret ")->call('runAi')->assertSee('iş ilanı');
        $this->assertCount(1, $prompts);
        $this->assertSame('iş ilanı ve eğitim içerikli kelimeler üret', $prompts[0]['operator_instruction']);
        $this->assertSame(['forum'], $prompts[0]['sectors'][0]['terms'], 'the stored context still goes');

        Livewire::test(QueryPlanWizard::class)->call('goTo', 3)->call('runAi');
        $this->assertArrayNotHasKey('operator_instruction', $prompts[1], 'no instruction → no field');

        // Filtre sepeti tab: same call path, checklist pre-ticked, untick one, save → rescan after the first import.
        $this->libraryQuery('implant fiyatları', $this->dental, 10);
        QueryPipeline::markImported();
        Livewire::test(QueriesPage::class)->call('setTab', 'filters')
            ->set('filterInstruction', 'eğitim içerikli kelimeler')->call('generateFilters')
            ->assertSee('AI önerisi · 2')->assertSee('kursu')
            ->call('toggleFilterLine', 1)->call('approveFilterProposal')->assertSee('1 filtre terimi kaydedildi · tarama başladı');
        $this->assertSame('eğitim içerikli kelimeler', $prompts[2]['operator_instruction']);
        $this->assertSame([$this->dental->id], array_column($prompts[2]['sectors'], 'id'), 'the used sectors');
        $this->assertSame(['forum', 'iş ilanı'], FilterTerm::query()->orderBy('term')->pluck('term')->all());
        $this->assertSame('ai', FilterTerm::query()->where('term', 'iş ilanı')->value('source'));
        $this->assertSame(1, QueryReview::query()->count());
    }

    private function libraryQuery(string $text, ?ServiceCategory $sector, int $impressions, ?ServiceCatalogItem $service = null, bool $hidden = false): Query
    {
        return Query::query()->create([
            'text' => $text, 'text_hash' => QueryNormalizer::hash($text), 'sector_id' => $sector?->id, 'service_id' => $service?->id,
            'assignment' => $service !== null ? 'rule' : 'none', 'impressions' => $impressions, 'hidden' => $hidden,
        ]);
    }
}
