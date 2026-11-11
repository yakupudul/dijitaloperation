<?php

namespace Tests\Feature\Site;

use App\Models\ComplianceRule;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\RedLines;

/** Kırmızı çizgiler (Bağlantı Düzeni 3.1): a text with one of the brand's forbidden phrases is not sent, and the reason is shown. */
final class RedLinesTest extends SiteTestCase
{
    private function action(string $channel, string $kind, array $payload): ExternalWriteAction
    {
        return ExternalWriteAction::query()->create(['channel' => $channel, 'action' => $kind, 'digital_asset_id' => $this->site->id,
            'brand_id' => $this->brand->id, 'status' => 'queued', 'request_payload' => $payload, 'requested_by' => $this->admin->id]);
    }

    public function test_a_write_that_carries_a_forbidden_phrase_is_stopped_with_the_reason(): void
    {
        ComplianceRule::query()->create(['pack_id' => 'brand:'.$this->brand->id, 'rule_key' => 'brand_karin_germe', 'kind' => 'forbidden_phrase', 'label' => 'Karın germe yok',
            'patterns' => ['karın germe'], 'message' => 'Bu markada karın germe yazılmaz.', 'severity' => 'high', 'active' => true, 'origin' => 'operator']);

        $reply = $this->action(ExternalWriteAction::CHANNEL_GBP, ExternalWriteAction::ACTION_REVIEW_REPLY, ['review_id' => 1, 'comment' => 'Teşekkürler, karın germe için de bekleriz.', 'label' => 'Yorum yanıtı']);
        $this->assertStringContainsString('karın germe', (string) RedLines::crossed($reply));
        app(ExternalWriteService::class)->execute($reply);
        $reply = $reply->fresh();
        $this->assertSame('failed', $reply->status);
        $this->assertStringContainsString('kırmızı çizgi', (string) $reply->error);
        $this->assertStringContainsString('karın germe', (string) $reply->error);

        // A site title is read too; an address or a clean text passes.
        $fix = $this->action(ExternalWriteAction::CHANNEL_WORDPRESS, ExternalWriteAction::ACTION_SITE_FIX, ['changes' => [
            ['type' => 'redirect', 'from' => '/karin-germe/', 'value' => '/estetik/', 'reference' => '/karin-germe/'],
            ['type' => 'meta_title', 'object_id' => 5, 'reference' => '/estetik/', 'value' => 'Karın Germe Ameliyatı Ankara'],
        ]]);
        $this->assertStringContainsString('/estetik/', (string) RedLines::crossed($fix));
        $clean = $this->action(ExternalWriteAction::CHANNEL_GBP, ExternalWriteAction::ACTION_LOCAL_POST, ['summary' => 'İmplant tedavisi hakkında bilgi alın.']);
        $this->assertNull(RedLines::crossed($clean));

        // A low ("uyar") rule does not stop a write.
        ComplianceRule::query()->where('rule_key', 'brand_karin_germe')->update(['severity' => 'low']);
        $this->assertNull(RedLines::crossed($reply));
    }
}
