<?php

namespace Tests\Unit\SeoTasks;

use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeoTextMatchesPhraseTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'exact' => ['diş implant fiyatları', 'implant', true],
            'accusative' => ['implantı ne kadar', 'implant', true],
            'plural possessive' => ['implant fiyatları istanbul', 'implant fiyat', true],
            'k softening' => ['burun estetiği yorumlar', 'burun estetik', true],
            'locative' => ['kadıköyde diş hekimi', 'diş hekimi', true],
            'two suffixes' => ['implantlarını', 'implant', true],
            'short word exact only' => ['kasık ağrısı', 'kas', false],
            'different word' => ['implantasyonel', 'implant', false],
            'phrase order' => ['fiyat implant', 'implant fiyat', false],
            'turkish case folding' => ['İMPLANT FİYATI', 'implant fiyat', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_suffix_tolerant_phrase_matching(string $haystack, string $needle, bool $expected): void
    {
        $this->assertSame($expected, SeoText::matchesPhrase($haystack, $needle));
    }

    public function test_one_fold_implementation(): void
    {
        $this->assertSame(SeoText::fold('Kadıköy Diş İmplantı'), LocationOptions::fold('Kadıköy Diş İmplantı'));
    }
}
