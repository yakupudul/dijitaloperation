<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\DoorwayCheckAgent;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\SeoFieldsBatch;
use App\Services\Repair\SiteAudit;
use App\Services\Repair\WebHealthAudit;
use App\Services\Site\DoorwayCheck;

/**
 * Kapı sayfaları (yakup, 2026-10-22: "sadece riskli olanları kaldır"): template pages naming another business or an
 * unserved place become noindex rows on Onarım masası; the business's own topics stay; nothing is written to the site.
 */
final class DoorwayCheckTest extends SiteTestCase
{
    public function test_risky_template_pages_become_noindex_rows_and_close_once_they_leave_google(): void
    {
        $this->enableAi();
        $pages = [];
        foreach (range(1, DoorwayCheck::MIN_TEMPLATE_PAGES) as $i) {
            $pages[] = $this->template('/kw/konu-'.$i.'/', 'Ankara Implant Konu '.$i, 100 + $i);
        }
        $other = $this->template('/kw/hurdaci-ismail-bolu/', 'Hurdacı İsmail Bolu', 900);
        $away = $this->template('/kw/niksar-implant/', 'Niksar İmplant', 901);
        $this->page('/iletisim/', 'İletişim', ['wp_post_id' => 5, 'content_text' => 'Bize ulaşın.']);
        DoorwayCheckAgent::fake(function (string $prompt) use ($other, $away): array {
            $data = json_decode(substr($prompt, strpos($prompt, '{')), true);

            return ['items' => array_map(fn (array $p): array => ['id' => $p['id'], 'reason' => 'Başlık başkasını hedefliyor.',
                'verdict' => match ($p['id']) {
                    $other->id => 'other_business', $away->id => 'out_of_area', default => 'keep'
                }], $data['pages'])];
        });

        $this->assertSame(['checked' => 22, 'flagged' => 2, 'closed' => 0, 'waiting' => 0], app(DoorwayCheck::class)->run());

        $rows = Suggestion::query()->where('action_type', WebHealthAudit::TYPE)->where('action->check', DoorwayCheck::CHECK)->get()
            ->keyBy(fn (Suggestion $s): string => (string) data_get($s->action, 'verdict'));
        $this->assertSame(['other_business', 'out_of_area'], $rows->keys()->sort()->values()->all());
        $row = $rows['other_business'];
        $this->assertSame([Suggestion::OPEN, DoorwayCheck::TITLES['other_business'].' (1 sayfa)'], [$row->status, $row->title]);
        $this->assertSame([['type' => 'noindex', 'object_id' => 900, 'reference' => 'doorway-noindex-'.$other->id, 'value' => true]], data_get($row->action, 'changes'));
        $this->assertSame(['checked' => 0, 'flagged' => 0, 'closed' => 0, 'waiting' => 0], app(DoorwayCheck::class)->run(), 'a page is read once');
        $this->assertSame(2, Suggestion::query()->where('action->check', DoorwayCheck::CHECK)->count(), 'a new run keeps the same rows');

        $row->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $this->admin->id])->save();
        $other->forceFill(['is_indexable' => false])->save();
        $this->assertSame(1, app(DoorwayCheck::class)->run()['closed']);
        $this->assertSame(Suggestion::APPLIED, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->applied_at);
        $this->assertSame(Suggestion::OPEN, $rows['out_of_area']->fresh()->status);
    }

    public function test_a_page_the_operator_refused_never_returns_on_a_new_row(): void
    {
        $this->enableAi();
        foreach (range(1, DoorwayCheck::MIN_TEMPLATE_PAGES) as $i) {
            $this->template('/kw/konu-'.$i.'/', 'Hurdacı Ali '.$i, 100 + $i);
        }
        DoorwayCheckAgent::fake(function (string $prompt): array {
            $data = json_decode(substr($prompt, strpos($prompt, '{')), true);

            return ['items' => array_map(fn (array $p): array => ['id' => $p['id'], 'verdict' => 'other_business', 'reason' => 'Kişi adı.'], $data['pages'])];
        });
        app(DoorwayCheck::class)->run();
        $row = Suggestion::query()->where('action->check', DoorwayCheck::CHECK)->sole();
        $this->assertCount(DoorwayCheck::MIN_TEMPLATE_PAGES, data_get($row->action, 'changes'));
        $row->forceFill(['status' => Suggestion::DISMISSED])->save();

        app(DoorwayCheck::class)->run();

        $this->assertSame(1, Suggestion::query()->where('action->check', DoorwayCheck::CHECK)->count());
        $this->assertSame(Suggestion::DISMISSED, $row->fresh()->status);
    }

    public function test_titles_of_doorway_pages_are_held_back_until_claude_clears_the_page(): void
    {
        $this->enableAi();
        $pages = [];
        foreach (range(1, DoorwayCheck::MIN_TEMPLATE_PAGES) as $i) {
            $pages[] = $this->template('/kws/konu-'.$i.'/', 'Müge Anlı Hurda '.$i, 100 + $i);
        }
        $proposal = ['kind' => 'fields', 'new' => ['seo_title' => 'Müge Anlı Hurda 1 | Panorama Ankara']];
        $row = Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'action_type' => SiteAudit::TYPE, 'status' => Suggestion::OPEN,
            'decision_key' => 'repair.'.SiteAudit::TYPE, 'title' => 'Başlık', 'reason' => 'x', 'priority' => 3, 'target_type' => 'page', 'target_id' => $pages[0]->id,
            'page_id' => $pages[0]->id, 'fingerprint' => 'f1', 'material_hash' => 'm1', 'first_seen_at' => now(), 'last_seen_at' => now(), 'action' => ['site_id' => $this->site->id, 'proposal' => $proposal]]);

        $this->assertSame(1, app(DoorwayCheck::class)->hold($this->site));
        $action = $row->fresh()->action;
        $this->assertArrayNotHasKey('proposal', $action, 'off the send');
        $this->assertSame(DoorwayCheck::HOLD_REASONS['unchecked'], $action['proposal_blocked']);
        $this->assertTrue(DoorwayCheck::held($pages[0]));
        $this->assertSame(['status' => 'ready', 'prepared' => 0, 'skipped' => 0], app(SeoFieldsBatch::class)->prepare($this->site, [$row->id]), 'no title is written for it');

        DoorwayCheckAgent::fake(function (string $prompt) use ($pages): array {
            $data = json_decode(substr($prompt, strpos($prompt, '{')), true);

            return ['items' => array_map(fn (array $p): array => ['id' => $p['id'], 'verdict' => $p['id'] === $pages[0]->id ? 'keep' : 'other_business', 'reason' => 'x'], $data['pages'])];
        });
        app(DoorwayCheck::class)->run();

        $this->assertSame($proposal, $row->fresh()->action['proposal'], 'a cleared page gets its title back');
        $this->assertArrayNotHasKey('proposal_blocked', $row->fresh()->action);
        $this->assertFalse(DoorwayCheck::held($pages[0]));
        $this->assertTrue(DoorwayCheck::held($pages[1]));
    }

    public function test_a_few_contact_pages_are_not_a_doorway_set(): void
    {
        $this->enableAi();
        foreach (range(1, DoorwayCheck::MIN_TEMPLATE_PAGES - 1) as $i) {
            $this->template('/kw/konu-'.$i.'/', 'Konu '.$i, 100 + $i);
        }
        DoorwayCheckAgent::fake(fn (): array => $this->fail('a few contact pages are never sent to Claude'));

        $this->assertSame(['checked' => 0, 'flagged' => 0, 'closed' => 0, 'waiting' => 0], app(DoorwayCheck::class)->run());
        $this->assertTrue(DoorwayCheck::isTemplate('Hurdacı İsmail Bolu', "## Hurdacı İsmail Bolu\n\n### Hurdacı İsmail Bolu için iletişim bilgileri\n| Telefon |"));
        $this->assertFalse(DoorwayCheck::isTemplate('Bakır hurda', 'Bakır hurdanın değeri saflığına göre değişir.'));
    }

    private function template(string $path, string $title, int $postId): Page
    {
        return $this->page($path, $title, ['wp_post_id' => $postId, 'category' => 'diger',
            'content_text' => '## '.$title."\n\n### ".$title." için iletişim bilgileri\n\n| ".$title.' için Kurumsal Telefon : | 0312 000 00 00 |']);
    }
}
