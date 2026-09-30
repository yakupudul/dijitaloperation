<?php

namespace Tests\Feature\Queries;

use App\Livewire\Operator\Library\QueriesPage;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Queries\QueryRuleEngine;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Sorgu kural motoru: variant / topic keys, merged list, export, bulk filter terms. */
final class QueryRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $dental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
    }

    public function test_variants_merge_spelling_suffixes_word_order_and_implied_words_and_facets_leave_the_topic(): void
    {
        $engine = app(QueryRuleEngine::class);
        $engine->loadVocabulary(['implant', 'diş implantı', 'implant fiyatları', 'estetik', 'burun estetiği', 'zirkonyum kaplama', 'kaplama']);
        $key = fn (string $text): array => $engine->keys($text, 'dis sagligi');

        foreach (['implant', 'diş implantı', 'dis implant', 'implant diş', 'implantlar', 'implamt', 'implant tedavisi'] as $text) {
            $this->assertSame('implant', $key($text)['variant'], $text);
        }
        $this->assertSame(['variant' => 'fiyat implant', 'topic' => 'implant', 'facets' => 'fiyat'], $key('diş implant ücretleri 2025'));
        $this->assertSame($key('implant fiyatları')['variant'], $key('implant ücreti')['variant'], 'ücret = fiyat');
        $this->assertSame(['variant' => 'implant nasil nedir yapilir', 'topic' => 'implant', 'facets' => 'nedir,nasil'], $key('implant nedir nasıl yapılır'));
        $this->assertSame('agri implant sonrasi', $key('implant sonrası ağrı')['topic'], 'topic words stay: a separate content');
        $this->assertSame('burun estetik', $key('burun estetiği')['variant'], 'soft consonant: estetiği → estetik');
        $this->assertSame('dis', $key('diş')['variant'], 'a query of only implied words keeps them');
        $this->assertNotSame($key('kaplama')['variant'], $key('kaplam')['variant']);
    }

    public function test_apply_writes_keys_and_marks_the_head_and_the_list_shows_one_row_per_variant_group(): void
    {
        foreach (['implant' => 500, 'diş implantı' => 200, 'dis implant' => 50, 'implant fiyatları' => 300, 'implant ücreti' => 100, 'zirkonyum' => 80] as $text => $impressions) {
            Query::query()->create(['text' => $text, 'text_hash' => hash('sha256', $text), 'sector_id' => $this->dental->id, 'impressions' => $impressions]);
        }

        $result = app(QueryRuleEngine::class)->apply();

        $this->assertSame(['queries' => 6, 'changed' => 6], $result);
        $this->assertSame(0, app(QueryRuleEngine::class)->apply()['changed'], 'a second run writes nothing');
        $this->assertSame(['implant', 'implant fiyatları', 'zirkonyum'], Query::query()->where('variant_head', true)->orderBy('text')->pluck('text')->all());
        $this->assertSame('implant', Query::query()->where('text', 'implant ücreti')->value('topic_key'));

        $page = Livewire::test(QueriesPage::class)
            ->assertSeeText('6 sorgu → 3 varyant → 2 konu')
            ->assertSee('+2 varyant')->assertDontSee('dis implant');

        // Hiding the head hides its variants.
        $head = Query::query()->where('text', 'implant')->value('id');
        $page->set('selected', [$head])->call('hideSelected');
        $this->assertSame(3, Query::query()->where('hidden', true)->count());

        Livewire::test(QueriesPage::class)->set('variants', false)->assertSee('implant ücreti');
    }

    public function test_the_csv_export_streams_every_query_with_its_keys(): void
    {
        Query::query()->create(['text' => 'diş implantı', 'text_hash' => hash('sha256', 'diş implantı'), 'sector_id' => $this->dental->id, 'impressions' => 10]);
        Query::query()->create(['text' => 'implant', 'text_hash' => hash('sha256', 'implant'), 'sector_id' => $this->dental->id, 'impressions' => 20]);
        app(QueryRuleEngine::class)->apply();

        $csv = $this->get(route('operator.library.queries.export'))->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFid,sorgu,sektor", $csv);
        $this->assertStringContainsString('"diş implantı","Diş sağlığı"', $csv);
        $this->assertStringContainsString(',implant,0,implant,,1', $csv, 'the variant of the head, with its keys');
    }

    public function test_bulk_filter_terms_skip_duplicates_and_question_words(): void
    {
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'forum', 'source' => 'manual', 'created_by' => $this->admin->id]);

        Livewire::test(QueriesPage::class)->call('setTab', 'filters')
            ->set('bulkTerms', "forum\nDentgroup\n  iş ilanı  \nnedir\n\ndentgroup\nx")
            ->call('addBulkTerms')->assertSee('2 terim eklendi');

        $this->assertEqualsCanonicalizing(['forum', 'dentgroup', 'iş ilanı'], FilterTerm::query()->pluck('term')->all());
    }
}
