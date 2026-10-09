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
        // A stem must be a real library word (in ≥ 3 queries): the library is repeated.
        $engine->loadVocabulary(array_merge(...array_fill(0, 3, ['implant', 'diş implantı', 'implant fiyatları', 'estetik', 'burun estetiği', 'zirkonyum kaplama', 'kaplama', 'cost'])));
        $key = fn (string $text): array => $engine->keys($text, 'dis sagligi');

        foreach (['implant', 'diş implantı', 'dis implant', 'implant diş', 'implantlar', 'implamt', 'implant tedavisi'] as $text) {
            $this->assertSame('implant', $key($text)['variant'], $text);
        }
        $this->assertSame(['variant' => 'fiyat implant', 'topic' => 'implant', 'facets' => 'fiyat'], $key('diş implant ücretleri 2025'));
        $this->assertSame($key('implant fiyatları')['variant'], $key('implant ücreti')['variant'], 'ücret = fiyat');
        $this->assertSame(['variant' => 'implant nasil nedir yapilir', 'topic' => 'implant #info', 'facets' => 'nedir,nasil'], $key('implant nedir nasıl yapılır'));
        $this->assertSame('agri implant sonrasi', $key('implant sonrası ağrı')['topic'], 'topic words stay: a separate content');
        $this->assertSame('burun estetik', $key('burun estetiği')['variant'], 'soft consonant: estetiği → estetik');
        $this->assertSame('dis', $key('diş')['variant'], 'a query of only implied words keeps them');
        $this->assertNotSame($key('kaplama')['variant'], $key('kaplam')['variant']);
        $this->assertSame('[en] implant', $key('dental implants')['variant'], 'English: own key, plural → singular, "dental" implied');
        $this->assertSame(['variant' => '[en] cost how implant much', 'topic' => '[en] implant', 'facets' => 'fiyat'], $key('how much does a dental implant cost'));
        $this->assertSame('フロスとは', $key('フロスとは')['variant'], 'an alphabet the rules cannot read: the query is its own group, never an empty key');
    }

    public function test_informational_searches_keep_their_own_topic_and_turkish_words_stay_turkish(): void
    {
        $engine = app(QueryRuleEngine::class);
        $engine->loadVocabulary(array_merge(...array_fill(0, 3, ['implant', 'implant fiyatları', 'kanal tedavisi', 'kanallı', 'hurda', 'makinesi', 'makine'])));
        $key = fn (string $text, string $sector = 'dis sagligi'): array => $engine->keys($text, $sector);

        // The article that explains is not the page that sells: "nedir / nasıl / süre / avantaj" never join "fiyat / randevu".
        $this->assertSame('implant', $key('implant fiyatları')['topic']);
        $this->assertSame('implant', $key('implant randevu')['topic']);
        $this->assertSame('implant #info', $key('implant nedir')['topic']);
        $this->assertSame('implant #info', $key('implant ne kadar sürer')['topic']);
        $this->assertSame('implant #info', $key('implant avantajları')['topic']);
        $this->assertNotSame($key('implant fiyatları')['topic'], $key('implant nasıl yapılır')['topic']);

        // "çok" / "daha" are part of the topic: multi-canal root canal treatment is not root canal treatment.
        $this->assertNotSame($key('kanal tedavisi')['topic'], $key('çok kanallı kanal tedavisi')['topic']);
        $this->assertStringContainsString('cok', $key('çok kanallı kanal tedavisi')['topic']);

        // "dental" and "iş" are Turkish words, not English markers.
        $this->assertStringStartsNotWith('[en]', $key('dental implant fiyatları')['variant']);
        $this->assertStringStartsNotWith('[en]', $key('hurda iş makinesi', 'geri donusum')['variant']);
        $this->assertContains('is', explode(' ', $key('hurda iş makinesi', 'geri donusum')['variant']), '"iş" is never dropped as the English "is"');
        $this->assertGreaterThanOrEqual(3, QueryRuleEngine::version(), 'a rule change raises the version so every key is recomputed');
    }

    public function test_rare_misspellings_and_rare_typo_stems_are_handled_from_the_library(): void
    {
        $engine = app(QueryRuleEngine::class);
        $engine->loadVocabulary([...array_fill(0, 40, 'hurda fiyatları'), ...array_fill(0, 40, 'diastema'), 'hurd', 'diastama', 'diaestema']);

        $this->assertSame('fiyat hurda', $engine->keys('hurda fiyatları')['variant'], '"hurd" (a typo in one query) never stems "hurda"');
        $this->assertSame('diastema', $engine->keys('diastama')['variant'], 'one wrong letter');
        $this->assertSame('diastema', $engine->keys('diaestema')['variant'], 'one extra letter');
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
        $this->assertStringContainsString(',implant,0,implant,,'.QueryRuleEngine::version(), $csv, 'the variant of the head, with its keys');
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
