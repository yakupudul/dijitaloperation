<?php

namespace Tests\Feature\Queries;

use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryServiceMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Matching keywords: nested keywords → the longer wins; keywords of two services, neither containing the other → conflict. */
final class QueryServiceMatcherTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $aesthetic;

    private ServiceCatalogItem $facelift;

    private ServiceCatalogItem $liquidFacelift;

    private ServiceCatalogItem $botox;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['is_active' => true]);
        $this->aesthetic = ServiceCategory::query()->firstOrCreate(['code' => 'aesthetic'], ['name' => 'Estetik', 'normalized_key' => 'estetik']);
        $catalog = app(ServiceCatalogService::class);
        $this->facelift = $catalog->resolveOrCreate('Yüz Germe', 'aesthetic', actor: $admin)['service'];
        $this->liquidFacelift = $catalog->resolveOrCreate('Sıvı Yüz Germe', 'aesthetic', actor: $admin)['service'];
        $this->botox = $catalog->resolveOrCreate('Botoks', 'aesthetic', actor: $admin)['service'];
        $keywords = app(ServiceKeywordService::class);
        $keywords->replace($this->facelift, "yüz germe\nyüz gerdirme");
        $keywords->replace($this->liquidFacelift, 'sıvı yüz germe');
        $keywords->replace($this->botox, "botoks\nbotox");
    }

    public function test_nested_keyword_gives_way_to_the_longer_one(): void
    {
        $matcher = app(QueryServiceMatcher::class);

        $this->assertSame(['service' => $this->liquidFacelift->id, 'keyword' => 'sivi yuz germe', 'conflicts' => []],
            $matcher->matchWithKeyword('sıvı yüz germe fiyatları', $this->aesthetic->id), '"yüz germe" ⊂ "sıvı yüz germe": the longer wins');
        $this->assertSame($this->facelift->id, $matcher->match('yüz germe ameliyatı', $this->aesthetic->id));
        $this->assertSame($this->facelift->id, $matcher->match('yüz germeden sonra', $this->aesthetic->id), 'suffix tolerant');
    }

    public function test_keywords_of_two_services_not_nested_are_a_conflict(): void
    {
        $matcher = app(QueryServiceMatcher::class);

        $result = $matcher->matchWithKeyword('yüz germe mi botoks mu', $this->aesthetic->id);
        $this->assertNull($result['service']);
        $this->assertNull($result['keyword']);
        $this->assertSame([['keyword' => 'yuz germe', 'service' => $this->facelift->id], ['keyword' => 'botoks', 'service' => $this->botox->id]], $result['conflicts'],
            'longest keyword first; neither contains the other');
        $this->assertNull($matcher->match('yüz germe mi botoks mu', $this->aesthetic->id));

        // The nested keyword drops out first; what remains still belongs to two services.
        $this->assertSame(['sivi yuz germe', 'botoks'], array_column($matcher->matchWithKeyword('sıvı yüz germe ve botoks', $this->aesthetic->id)['conflicts'], 'keyword'));
    }

    public function test_keywords_of_one_service_never_conflict_and_the_longest_decides(): void
    {
        $matcher = app(QueryServiceMatcher::class);

        $this->assertSame(['service' => $this->botox->id, 'keyword' => 'botoks', 'conflicts' => []], $matcher->matchWithKeyword('botox mu botoks mu', $this->aesthetic->id));
        $this->assertSame(['service' => null, 'keyword' => null, 'conflicts' => []], $matcher->matchWithKeyword('dolgu', $this->aesthetic->id));
        $this->assertNull($matcher->match('botoks', null), 'no sector, no match');
    }

    public function test_a_simulated_keyword_does_not_touch_the_matcher_it_came_from(): void
    {
        $matcher = app(QueryServiceMatcher::class);
        $simulated = $matcher->withKeyword($this->aesthetic->id, $this->botox->id, 'dolgu');

        $this->assertSame($this->botox->id, $simulated->match('dudak dolgusu', $this->aesthetic->id));
        $this->assertNull($matcher->match('dudak dolgusu', $this->aesthetic->id));
        $this->assertNotSame([], $simulated->matchWithKeyword('yüz germe dolgu', $this->aesthetic->id)['conflicts'], 'the simulated keyword takes part in conflicts');
    }
}
