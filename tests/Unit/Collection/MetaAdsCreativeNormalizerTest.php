<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\Providers\MetaAds\MetaAdsNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Ads made from an existing Instagram post keep their button and lead form on the creative's own call_to_action. */
final class MetaAdsCreativeNormalizerTest extends TestCase
{
    #[Test]
    public function an_existing_post_ad_keeps_its_button_and_lead_form(): void
    {
        $metadata = app(MetaAdsNormalizer::class)->normalizeCreativeSnapshots('777', 'Europe/Istanbul', [[
            'id' => '4002', 'object_type' => 'PHOTO', 'body' => 'Post bariatrik', 'thumbnail_url' => 'https://cdn.test/t.jpg',
            'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['lead_gen_form_id' => '5002']],
        ]], null, null)[0]['metadata'];

        $this->assertSame(['LEARN_MORE', '5002'], [$metadata['call_to_action_type'], $metadata['lead_gen_form_id']]);
    }
}
