<?php

namespace Tests\Feature\Site;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\RepairDesk;
use App\Services\Site\BrokenTranslations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Bozuk çeviri onarımı (2026-10-19: OPC Klinik's Italian treatment pages were written in game slang): a language
 * version full of game words while its source version has none gets a faithful translation on the Onarım masası; the
 * one desk approval sends it as a draft copy that replaces the page once WordPress confirmed it.
 */
final class BrokenTranslationsTest extends SiteTestCase
{
    private const string BROKEN = 'Gameplay: come si fa. Anche se ti senti overpowered, un ping al server ogni 6 mesi. Niente lag, niente respawn della placca, '
        .'evita i noob errori, il cooldown è di 6 mesi, la daily grind e il win rate del 99%.';

    private function translated(Page $page, array $translations): void
    {
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => (string) $page->wp_post_id,
            'permalink' => $page->url, 'title' => $page->title, 'metadata' => json_encode(['language' => $page->language, 'translations' => $translations]),
            'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', (string) $page->wp_post_id)]);
    }

    public function test_a_language_version_in_game_slang_whose_source_is_sound_comes_to_the_desk(): void
    {
        $this->page('/', 'Ana sayfa');
        $source = $this->page('/rutin-kontroller/', 'Rutin kontroller', ['wp_post_id' => 10, 'content_text' => 'Rutin kontroller ve temizlik her 6 ayda bir önerilir.']);
        $broken = $this->page('/it/ispezioni/', 'Ispezioni di routine', ['wp_post_id' => 11, 'language' => 'it', 'content_text' => self::BROKEN]);
        $sound = $this->page('/it/impianti/', 'Impianti', ['wp_post_id' => 12, 'language' => 'it', 'content_text' => 'Gli impianti dentali sostituiscono i denti mancanti.']);
        $sports = $this->page('/en/sports/', 'Sports dentistry', ['wp_post_id' => 13, 'language' => 'en', 'content_text' => 'Every player needs a mouthguard; a player in contact sports, players.']);
        $own = $this->page('/it/gaming/', 'Gaming', ['wp_post_id' => 14, 'language' => 'it', 'content_text' => self::BROKEN]);
        $ownSource = $this->page('/gaming/', 'Gaming', ['wp_post_id' => 15, 'content_text' => self::BROKEN]);
        $this->translated($broken, ['tr' => 10, 'it' => 11]);
        $this->translated($sound, ['tr' => 10, 'it' => 12]);
        $this->translated($sports, ['tr' => 10, 'en' => 13]);
        $this->translated($own, ['tr' => 15, 'it' => 14]);

        $ids = app(BrokenTranslations::class)->scan($this->site);

        $this->assertCount(1, $ids);
        $row = Suggestion::query()->findOrFail($ids[0]);
        $this->assertSame(BrokenTranslations::TYPE, $row->action_type);
        $this->assertSame($broken->id, $row->page_id);
        $this->assertSame($source->id, $row->action['source_page_id']);
        $this->assertSame($ids, app(BrokenTranslations::class)->scan($this->site), 'a second scan keeps the same row');
        $this->assertSame(1, Suggestion::query()->where('action_type', BrokenTranslations::TYPE)->count());
    }

    public function test_the_translation_is_kept_only_when_it_is_a_faithful_one(): void
    {
        $fixes = app(BrokenTranslations::class);
        $source = '<h2>Rutin kontroller</h2><p>Her 6 ayda bir kontrol önerilir. <a href="https://panorama.com.tr/it/impianti/">İmplant</a></p>';
        $current = '<h2>Gameplay</h2><p>Un ping al server ogni 6 mesi.</p>';
        $urls = ['https://panorama.com.tr/it/impianti/'];

        $good = $fixes->validated(['html' => '<h2>Controlli di routine</h2><p>Si consiglia un controllo ogni 6 mesi. <a href="https://panorama.com.tr/it/impianti/">Impianti</a></p>',
            'seo_title' => 'Controlli di routine a Kadıköy', 'meta_description' => null], $source, $current, $urls, 'https://panorama.com.tr/it/ispezioni/');
        $this->assertNotNull($good);
        $this->assertStringContainsString('href="https://panorama.com.tr/it/impianti/"', $good['html']);
        $this->assertSame('Controlli di routine a Kadıköy', $good['seo_title']);

        $this->assertNull($fixes->validated(['html' => '<h2>Controlli</h2><p>Si consiglia un controllo ogni 3 mesi, nel 99% dei casi va bene.</p>'], $source, $current, $urls, 'https://panorama.com.tr/it/ispezioni/'),
            'a number the source does not have');
        $this->assertNull($fixes->validated(['html' => '<h2>Gameplay</h2><p>Un controllo ogni 6 mesi senza lag e senza respawn della placca.</p>'], $source, $current, $urls, 'https://panorama.com.tr/it/ispezioni/'),
            'slang left in it');
        $unlinked = $fixes->validated(['html' => '<h2>Controlli di routine</h2><p>Si consiglia un controllo ogni 6 mesi. <a href="https://spam.test/">Impianti</a></p>'],
            $source, $current, $urls, 'https://panorama.com.tr/it/ispezioni/');
        $this->assertStringNotContainsString('spam.test', (string) $unlinked['html']);
    }

    public function test_one_desk_approval_sends_the_draft_copy_and_it_goes_live_once_wordpress_has_it(): void
    {
        Queue::fake();
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.19.0']]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'c', 'shared_secret' => str_repeat('s', 43)]]);
        $page = $this->page('/it/ispezioni/', 'Ispezioni di routine', ['wp_post_id' => 11, 'language' => 'it', 'content_text' => self::BROKEN]);
        $row = Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => BrokenTranslations::DECISION, 'fingerprint' => 'f', 'material_hash' => 'm',
            'title' => 'Bozuk çeviri: IT sayfası', 'reason' => 'Oyun argosu.', 'priority' => 2, 'action_type' => BrokenTranslations::TYPE, 'page_id' => $page->id,
            'target_type' => 'page', 'target_id' => $page->id, 'status' => Suggestion::OPEN, 'evidence' => [], 'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => ['site_id' => $this->site->id, 'language' => 'it', 'proposal' => ['kind' => 'content', 'current' => ['html' => '<p>Gameplay</p>'],
                'new' => ['html' => '<h2>Controlli di routine</h2><p>Si consiglia un controllo ogni 6 mesi.</p>'], 'note' => 'Çeviri düzeltildi.']]]);

        $desk = app(RepairDesk::class);
        $deskRow = $desk->rows()->firstWhere('id', $row->id);
        $this->assertSame(RepairDesk::SITE_CONTENT, $deskRow['kind']);
        $this->assertSame(RepairDesk::MEDIUM, $deskRow['risk'], 'a faithful translation can be approved with the others');

        $desk->approve([$row->id], $this->admin);
        $draft = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_CONTENT_DRAFT)->sole();
        $this->assertSame(11, $draft->request_payload['object_id']);
        $this->assertSame(Suggestion::APPROVED, $row->fresh()->status);
        $this->assertNull($desk->rows()->firstWhere('id', $row->id), 'it leaves the desk while it is sent');

        $draft->forceFill(['status' => 'succeeded', 'result' => ['post_id' => 900, 'status' => 'succeeded']])->save();
        app(BrokenTranslations::class)->draftFinished($draft);

        $apply = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_CONTENT_APPLY)->sole();
        $this->assertSame(900, $apply->request_payload['draft_id']);
        $this->assertSame(Suggestion::APPLIED, $row->fresh()->status);
    }

    public function test_a_draft_copy_that_failed_returns_the_row_to_the_desk_with_the_reason(): void
    {
        $page = $this->page('/it/ispezioni/', 'Ispezioni di routine', ['wp_post_id' => 11, 'language' => 'it']);
        $row = Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => BrokenTranslations::DECISION, 'fingerprint' => 'f', 'material_hash' => 'm',
            'title' => 'Bozuk çeviri: IT sayfası', 'reason' => 'Oyun argosu.', 'priority' => 2, 'action_type' => BrokenTranslations::TYPE, 'page_id' => $page->id,
            'target_type' => 'page', 'target_id' => $page->id, 'status' => Suggestion::APPROVED, 'evidence' => [], 'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => ['site_id' => $this->site->id, 'writes' => [1], 'proposal' => ['kind' => 'content', 'new' => ['html' => '<p>x</p>']]]]);
        $draft = ExternalWriteAction::query()->create(['channel' => 'wordpress', 'action' => ExternalWriteAction::ACTION_CONTENT_DRAFT, 'status' => 'failed', 'error' => 'site yanıt vermedi',
            'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'suggestion_id' => $row->id, 'requested_by' => $this->admin->id, 'request_payload' => []]);

        app(BrokenTranslations::class)->draftFinished($draft);

        $row->refresh();
        $this->assertSame(Suggestion::OPEN, $row->status);
        $this->assertArrayNotHasKey('writes', $row->action);
        $this->assertStringContainsString('site yanıt vermedi', $row->action[RepairDesk::DESK_ERROR]);
    }
}
