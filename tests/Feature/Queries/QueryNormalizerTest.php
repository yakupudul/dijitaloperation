<?php

namespace Tests\Feature\Queries;

use App\Services\Queries\QueryContext;
use App\Services\Queries\QueryNormalization;
use App\Services\Queries\QueryNormalizer;
use Tests\TestCase;

/** Raw provider query → core query: places, own / competitor / product brands, near-me words, Turkish folding. */
final class QueryNormalizerTest extends TestCase
{
    private QueryNormalizer $normalizer;

    private QueryContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = app(QueryNormalizer::class);
        $this->context = new QueryContext(
            ownMarks: ['atlasdis', 'atlasdental'],
            competitorMarks: ['panoramadis', 'rakipklinik'],
            productMarks: ['straumann', 'nobel biocare', 'invisalign'],
            exclusionRules: [['id' => 1, 'label' => 'bedava', 'normalized' => 'bedava']],
            sector: 'dental',
        );
    }

    public function test_places_and_near_me_words_are_stripped_modifiers_stay(): void
    {
        $this->assertCore('implant fiyatı', 'çankaya implant fiyatı', location: true);
        $this->assertCore('implant fiyatı', "Çankaya'da implant fiyatı", location: true);
        $this->assertCore('implant fiyatı', 'çankayada implant fiyatı', location: true);
        $this->assertCore('diş kliniği', 'en yakın diş kliniği', location: true);
        $this->assertCore('diş kliniği', 'diş kliniği yakınımda', location: true);
        $this->assertCore('diş kliniği', 'Bahçelievler Mahallesi diş kliniği', location: true);
        $this->assertCore('implant', 'ankara ve istanbul implant', location: true);
        $this->assertCore('implant nedir', 'implant nedir');
        $this->assertCore('implant nasıl yapılır', 'İzmir implant nasıl yapılır', location: true);

        $only = $this->normalizer->normalize('kadıköy', $this->context);
        $this->assertSame(QueryNormalization::EMPTY, $only->kind, 'place only: no core query');
    }

    public function test_own_competitor_and_product_brands(): void
    {
        $own = $this->normalizer->normalize('Atlas Diş implant fiyatı', new QueryContext(ownMarks: ['atlasdis']));
        $this->assertSame([QueryNormalization::CORE, 'implant fiyatı', true], [$own->kind, $own->core, $own->hadOwnBrand]);
        $this->assertCore('yorumlar', 'atlasdental yorumlar', ownBrand: true);
        $this->assertCore('implant', 'atlasdis.com implant', ownBrand: true);
        $this->assertSame(QueryNormalization::BRAND, $this->normalizer->normalize('atlas dental', $this->context)->kind, 'own brand only: navigational');

        $competitor = $this->normalizer->normalize('panorama diş implant fiyatları', $this->context);
        $this->assertSame(QueryNormalization::COMPETITOR, $competitor->kind);
        $this->assertTrue($competitor->hadCompetitorBrand);
        $this->assertSame('implant fiyatları', $competitor->core);
        $this->assertSame(QueryNormalization::COMPETITOR, $this->normalizer->normalize('rakipklinikte implant', $this->context)->kind, 'suffixed competitor name');

        $this->assertCore('implant fiyatı', 'Straumann implant fiyatı', product: true);
        $this->assertCore('implant', 'nobel biocare implant', product: true);
        $this->assertCore('tedavi fiyatı', 'invisalign tedavi fiyatı', product: true);
    }

    public function test_turkish_folding_suffixes_and_banned_list(): void
    {
        $upper = $this->normalizer->normalize('İMPLANT FİYATI', $this->context);
        $ascii = $this->normalizer->normalize('implant fiyati', $this->context);
        $this->assertSame('implant fiyatı', $upper->core, 'Turkish İ / I lowered correctly');
        $this->assertSame(QueryNormalizer::coreKey($upper->core), QueryNormalizer::coreKey($ascii->core), 'ı / i and diacritics: one core identity');

        $banned = $this->normalizer->normalize('bedava diş muayenesi', $this->context);
        $this->assertSame(QueryNormalization::BANNED, $banned->kind);

        $hash = hash('sha256', $this->normalizer->canonical('bedava diş muayenesi'));
        $protected = new QueryContext(exclusionRules: $this->context->exclusionRules, protectedHashes: [$hash => true]);
        $this->assertSame(QueryNormalization::CORE, $this->normalizer->normalize('bedava diş muayenesi', $protected)->kind, 'protected expression is not banned');
    }

    private function assertCore(string $core, string $raw, bool $location = false, bool $ownBrand = false, bool $product = false): void
    {
        $result = $this->normalizer->normalize($raw, $this->context);
        $this->assertSame(QueryNormalization::CORE, $result->kind, $raw);
        $this->assertSame($core, $result->core, $raw);
        $this->assertSame($location, $result->hadLocation, $raw.' location flag');
        $this->assertSame($ownBrand, $result->hadOwnBrand, $raw.' own brand flag');
        $this->assertSame($product, $result->hadProductBrand, $raw.' product flag');
    }
}
