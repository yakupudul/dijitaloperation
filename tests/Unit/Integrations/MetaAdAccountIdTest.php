<?php

namespace Tests\Unit\Integrations;

use App\Support\Integrations\Meta\MetaAdAccountId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaAdAccountIdTest extends TestCase
{
    #[DataProvider('canonicalProvider')]
    public function test_canonical_normalizes_act_and_digits(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, MetaAdAccountId::canonical($raw));
        $this->assertSame(
            $expected === null ? null : substr($expected, 4),
            MetaAdAccountId::digits($raw),
        );
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function canonicalProvider(): array
    {
        return [
            'act form' => ['act_123456789', 'act_123456789'],
            'digits only' => ['123456789', 'act_123456789'],
            'whitespace' => ['  act_99  ', 'act_99'],
            'empty' => ['', null],
            'null' => [null, null],
            'non numeric' => ['act_abc', null],
            'mixed' => ['act_12ab', null],
        ];
    }

    public function test_equals_treats_act_and_digits_as_same_identity(): void
    {
        $this->assertTrue(MetaAdAccountId::equals('123', 'act_123'));
        $this->assertTrue(MetaAdAccountId::equals('act_123', 'act_123'));
        $this->assertFalse(MetaAdAccountId::equals('123', '456'));
        $this->assertFalse(MetaAdAccountId::equals('act_123', null));
    }

    public function test_to_api_form_requires_valid_id(): void
    {
        $this->assertSame('act_42', MetaAdAccountId::toApiForm('42'));
        $this->assertSame('act_42', MetaAdAccountId::toApiForm('act_42'));

        $this->expectException(InvalidArgumentException::class);
        MetaAdAccountId::toApiForm('not-an-id');
    }
}
