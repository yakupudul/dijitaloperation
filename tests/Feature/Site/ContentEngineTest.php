<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ContentBriefAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Komuta\KomutaPage;
use App\Livewire\Operator\Work\ContentWeekPlanCard;
use App\Models\ContentSiteState;
use App\Models\Suggestion;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Work\ArticleCoverage;
use App\Services\Work\ContentAutopilot;
use App\Services\Work\ContentWeekPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Aşama 8 (2026-10-22): the content score (what Google's first results cover), an article that fails the check written
 * again (twice at most), and the week's plan: proposed on Monday, approved in one click, a dropped row replaced.
 */
final class ContentEngineTest extends SiteTestCase
{
    private const array BRIEF = ['searched' => true, 'must_cover' => ['İlk 24 saat soğuk ve yumuşak gıdalar', 'Sigara ve alkol ne zaman serbest', 'Ağrı kesici kullanımı'],
        'people_also_ask' => ['İmplanttan sonra kahve içilir mi?']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 05:00', 'Europe/Istanbul'));
    }

    /** @param  array<string, mixed>  $action */
    private function idea(string $title, array $action = [], string $status = Suggestion::OPEN, array $extra = []): Suggestion
    {
        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', $title), 'material_hash' => 'm',
            'title' => $title, 'reason' => 'Sorgu 120 gösterim aldı.', 'priority' => 3, 'action_type' => SiteSuggestionTypes::CONTENT,
            'status' => $status, 'action' => array_merge(['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'outline' => ['Giriş'], 'questions' => []], $action),
            'evidence' => [], 'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ], $extra));
    }

    public function test_the_content_score_measures_what_the_first_results_cover(): void
    {
        $full = '<p>İlk 24 saat soğuk ve yumuşak gıdalar yenir.</p><p>Sigara ve alkol bir hafta sonra serbest.</p><p>Ağrı kesici kullanımı hekime sorulur.</p><p>İmplanttan sonra kahve ilk gün içilmez.</p>';
        $this->assertSame(['score' => 100, 'covered' => 4, 'total' => 4, 'missing' => []], ArticleCoverage::score($full, self::BRIEF));

        $thin = ArticleCoverage::score('<p>İlk 24 saat soğuk gıdalar yenir, yumuşak beslenme.</p>', self::BRIEF);
        $this->assertSame(23, $thin['score'], '1 of 3 must-cover lines × 0.7, no question');
        $this->assertSame(['Sigara ve alkol ne zaman serbest', 'Ağrı kesici kullanımı', 'İmplanttan sonra kahve içilir mi?'], $thin['missing']);
        $this->assertStringContainsString('İçerik puanı 23/100', ArticleCoverage::reason($thin));
        $this->assertNull(ArticleCoverage::score('<p>x</p>', ['must_cover' => []]), 'no brief, no score');
    }

    public function test_an_article_under_seventy_is_written_again_with_what_it_missed_and_held_after_the_second_time(): void
    {
        Queue::fake();
        $article = ['title' => 'İmplant sonrası beslenme', 'html' => '<p>İlk 24 saat soğuk gıdalar yenir, yumuşak beslenme.</p>', 'language' => 'tr'];
        $idea = $this->idea('İmplant sonrası beslenme', ['article' => $article, 'brief' => self::BRIEF, 'autopilot' => ['date' => '2026-10-19', 'week' => '2026-10-19']], Suggestion::APPROVED);

        app(ContentAutopilot::class)->review($this->site);

        $action = $idea->fresh()->action;
        $this->assertArrayNotHasKey('article', $action, 'the article is written again');
        $this->assertSame(1, $action['check_rewrites']);
        $this->assertStringContainsString('İçerik puanı 23/100', $action['check_feedback']);
        $this->assertSame('2026-10-19', $action['autopilot']['date'], 'it keeps its day');
        Queue::assertPushed(RunSiteOperationJob::class, 1);

        // The writer reads the check's reason.
        $this->enableAi();
        ContentBriefAgent::fake([self::BRIEF + ['top_results' => [], 'outline' => ['Giriş']]]);
        $sent = [];
        WriteArticleAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['title' => 'İmplant sonrası beslenme', 'slug' => 'implant-beslenme', 'meta_title' => 'İmplant sonrası beslenme', 'meta_description' => 'İlk hafta.',
                'excerpt' => 'İlk hafta.', 'html' => '<p>İlk 24 saat soğuk gıdalar yenir, yumuşak beslenme.</p><p>'.str_repeat('hekim kontrolünde beslenme sürer ', 200).'</p>'];
        });
        app(ContentPlanner::class)->writeArticle($idea->fresh());
        $this->assertStringContainsString('İçerik puanı 23/100', (string) data_get($sent, '0.plan.previous_check'));

        // Still under 70 twice more: written once more, then held for the operator with the reason.
        app(ContentAutopilot::class)->review($this->site);
        $this->assertSame(2, $idea->fresh()->action['check_rewrites']);
        $action = $idea->fresh()->action;
        $action['article'] = $article;
        $idea->forceFill(['action' => $action])->save();
        app(ContentAutopilot::class)->review($this->site);
        $action = $idea->fresh()->action;
        $this->assertSame(2, $action['check_rewrites']);
        $this->assertSame('wrong', $action['autopilot']['review']['verdict']);
        $this->assertSame(23, $action['article_score']);
        $this->assertSame([], app(ContentAutopilot::class)->ready()->pluck('id')->all(), 'never sent by the one click');
    }

    public function test_monday_proposes_the_week_and_one_click_approves_it_a_dropped_row_is_replaced(): void
    {
        Queue::fake();
        ContentSiteState::for($this->site)->forceFill(['backfill' => ContentSiteState::BACKFILL_OFF, 'new_per_week' => 1, 'updates_per_week' => 2])->save();
        $new = $this->idea('Diş taşı temizliği sıklığı', ['cycle' => '2026-10-14']);
        $spare = $this->idea('Diş beyazlatma kaç seans', ['cycle' => '2026-10-07']);
        $update = $this->idea('Kanal tedavisi sayfasını yenile', ['kind' => 'update'], extra: ['priority' => 2]);
        $later = $this->idea('Dolgu sayfasını yenile', ['kind' => 'update'], extra: ['priority' => 4]);
        $thin = $this->idea('Zirkonyum sayfasını güçlendir', ['kind' => 'update'], extra: ['priority' => 4, 'reason' => '',
            'evidence' => [['kind' => 'page', 'value' => '/zirkonyum/ · İnce · yalnız 90 kelime, ana sayfa güçlendirilecek', 'source' => 'site']]]);

        $this->assertSame(['planned' => 0, 'quota' => 1, 'proposed' => 3], app(ContentAutopilot::class)->planWeek($this->site, $this->admin));
        Queue::assertNothingPushed();
        $this->assertNull(data_get($later->fresh()->action, 'plan'), 'two updates a week');

        $rows = app(ContentWeekPlan::class)->waiting();
        $this->assertSame([$thin->id, $new->id, $update->id], $rows->pluck('id')->all(), 'strengthening first, then new, then updates');
        $this->assertSame(['strengthen', 'new', 'update'], $rows->pluck('kind')->all());
        $this->assertSame('/zirkonyum/ · İnce · yalnız 90 kelime, ana sayfa güçlendirilecek', $rows[0]['why'], 'no reason: the first evidence');
        $this->assertSame('2026-10-19', $rows[1]['date']);

        $this->actingAs($this->admin);
        Livewire::test(ContentWeekPlanCard::class)->assertSee('Bu haftanın içerik planı')->assertSee('Tümünü onayla (3)')->assertSee('Neden: Sorgu 120 gösterim aldı.')
            ->call('drop', $new->id)->assertSee('yerine «Diş beyazlatma kaç seans» geldi');
        $this->assertSame(Suggestion::DISMISSED, $new->fresh()->status);
        $this->assertSame(ContentWeekPlan::DROP_REASON, $new->fresh()->operator_note);
        $this->assertSame('2026-10-19', data_get($spare->fresh()->action, 'plan.date'), 'the replacement takes the dropped day');

        Livewire::test(KomutaPage::class)->assertSee('Bu haftanın içerik planı')->call('approveGroup', 'content-plan')->assertSee('3 plan satırı onaylandı');
        $this->assertSame([Suggestion::APPROVED, Suggestion::APPROVED, Suggestion::APPROVED], [$spare->fresh()->status, $thin->fresh()->status, $update->fresh()->status]);
        $this->assertSame('2026-10-19', data_get($spare->fresh()->action, 'autopilot.date'));
        $this->assertNull(data_get($thin->fresh()->action, 'autopilot.date'), 'an update keeps its own approval on the page');
        $this->assertSame(0, app(ContentWeekPlan::class)->waiting()->count());
        $this->assertSame(['planned' => 0, 'quota' => 1, 'proposed' => 0], app(ContentAutopilot::class)->planWeek($this->site, $this->admin), 'the week is full');

        // Next Monday last week's unanswered proposals go back to the pool.
        $later->forceFill(['action' => array_merge($later->action, ['plan' => ['week' => '2026-10-19', 'proposed_at' => now()->toIso8601String()]])])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 05:00', 'Europe/Istanbul'));
        app(ContentAutopilot::class)->planWeek($this->site, $this->admin);
        $this->assertSame('2026-10-26', data_get($later->fresh()->action, 'plan.week'), 'proposed again for the new week');
    }
}
