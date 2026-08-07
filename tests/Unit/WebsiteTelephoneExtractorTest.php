<?php

namespace Tests\Unit;

use App\Support\WebsiteTelephoneExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebsiteTelephoneExtractorTest extends TestCase
{
    public function test_extracts_tel_href_itemprop_and_json_ld_telephones(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body>
<a href="tel:+1-555-0100">Call</a>
<span itemprop="telephone">+1 (555) 0100</span>
<script type="application/ld+json">{"telephone":"+15550100"}</script>
<a href="tel:123">too short</a>
</body></html>
HTML;

        $extractor = new WebsiteTelephoneExtractor;
        $candidates = $extractor->extract($html);

        $this->assertSame(['+1-555-0100', '+1 (555) 0100', '+15550100'], $candidates);
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize_digits(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, (new WebsiteTelephoneExtractor)->normalize($raw));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'e164' => ['+1 555-0100', '15550100'],
            'parens' => ['(555) 010-1234', '5550101234'],
            'tel_prefix' => ['tel:+44 20 7946 0958', '442079460958'],
            'too_short' => ['12345', null],
            'empty' => ['', null],
        ];
    }
}
