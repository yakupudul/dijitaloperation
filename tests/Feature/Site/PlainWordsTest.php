<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Repair\RepairDeskPage;
use App\Livewire\Operator\Website\V2\SuggestionsTab;
use App\Livewire\Operator\Work\WorkPage;
use App\Models\BrandClusterPage;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\SiteAudit;
use App\Services\Site\BrokenTranslations;
use App\Services\Site\Clarity\ClarityRules;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ImageAlts;
use App\Services\Site\SiteSuggestionTypes;
use Livewire\Livewire;

/**
 * Ortak dil (yakup, 2026-10-19: "küme çakışması, 301, ayrıştır… ne olduğunu anlamadım"): every work type has a plain
 * name, and Öneriler, Onarım masası and İşler never show an internal code or an English state as text.
 */
final class PlainWordsTest extends SiteTestCase
{
    /** Codes that must never reach the operator as text. */
    private const array CODES = ['cluster_overlap', 'image_alt', 'translation_fix', 'seo_fields', 'title_description', 'missing_topic', 'internal_links',
        'technical_seo', 'wrong_intent', 'duplicate_content', 'service_location', 'differentiate', 'merge_redirect', 'site_fix', 'succeeded', 'pending_approval',
        'recheck', 'dismissed', 'Çakışma:', 'Ayrıştır', '301 öneriliyor'];

    public function test_every_work_type_has_a_plain_name(): void
    {
        $types = [...array_keys(SiteSuggestionTypes::ANALYSIS), SiteSuggestionTypes::CONTENT, SiteSuggestionTypes::COMPETITOR, ClusterOverlaps::TYPE,
            ImageAlts::TYPE, BrokenTranslations::TYPE, SiteAudit::TYPE, ClarityRules::TYPE];
        foreach ($types as $type) {
            $this->assertArrayHasKey($type, SiteSuggestionTypes::PLAIN, $type.' has no plain name');
            $this->assertNotSame($type, SiteSuggestionTypes::label($type));
        }
    }

    public function test_screens_show_no_internal_codes(): void
    {
        $main = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet']);
        $copy = $this->page('/implant-tedavisi-nedir/', 'İmplant tedavisi nedir', ['category' => 'blog']);
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'language' => 'tr', 'state' => 'possible_conflict', 'page_id' => $main->id, 'overlap_page_ids' => [$copy->id]]);
        app(ClusterOverlaps::class)->sync($this->site, $this->brand);
        foreach ([...array_keys(SiteSuggestionTypes::ANALYSIS), ImageAlts::TYPE, SiteAudit::TYPE, ClarityRules::TYPE] as $i => $type) {
            $this->row($type, $main->id, Suggestion::OPEN, ['images' => [['image_id' => 9, 'file' => 'a.jpg', 'alt' => 'Implant modeli']]]);
        }
        $applied = $this->row('title_description', $main->id, Suggestion::APPLIED, ['writes' => [1]]);
        ExternalWriteAction::query()->create(['channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_SITE_FIX,
            'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'status' => 'succeeded', 'requested_by' => $this->admin->id, 'request_payload' => ['changes' => []], 'suggestion_id' => $applied->id]);
        $applied->forceFill(['channel' => 'search', 'outcome' => ['d30' => ['before' => ['clicks' => 14], 'clicks' => 31, 'verdict' => OutcomeTracker::WORKED, 'reason' => 'arttı']]])->save();

        $tab = Livewire::test(SuggestionsTab::class, ['assetId' => $this->site->id]);
        $this->assertNoCodes($tab->html());
        $this->assertNoCodes(Livewire::test(SuggestionsTab::class, ['assetId' => $this->site->id])->set('status', '')->call('open', $applied->id)->html());
        $this->assertSame('30 gün sonra: tıklama 14\'ten 31\'e çıktı.', OutcomeTracker::sentence($applied->fresh()));
        Livewire::test(SuggestionsTab::class, ['assetId' => $this->site->id])->set('status', 'applied')->assertSee('30 gün sonra: tıklama 14\'ten 31\'e çıktı.');

        $this->assertNoCodes(Livewire::test(RepairDeskPage::class)->set('lane', RepairDesk::LANE_REVIEW)->html());
        $this->assertNoCodes(Livewire::withQueryParams(['sekme' => 'cakisma'])->test(WorkPage::class)->html());
    }

    /** @param  array<string, mixed>  $action */
    private function row(string $type, int $pageId, string $status, array $action = []): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.test', 'fingerprint' => hash('sha256', $type.$status.random_int(1, PHP_INT_MAX)),
            'material_hash' => hash('sha256', 'x'), 'title' => 'Sayfanın başlığı kısa', 'reason' => 'Sayfa Google\'da görünüyor ama başlığı kısa.', 'priority' => 2,
            'action_type' => $type, 'page_id' => $pageId, 'target_type' => 'page', 'target_id' => $pageId, 'status' => $status,
            'action' => ['site_id' => $this->site->id] + $action, 'evidence' => [],
        ]);
    }

    private function assertNoCodes(string $html): void
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $html)));
        foreach (self::CODES as $code) {
            $this->assertStringNotContainsString($code, $text, '«'.$code.'» is shown to the operator');
        }
    }
}
