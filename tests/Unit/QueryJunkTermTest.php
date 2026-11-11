<?php

namespace Tests\Unit;

use App\Services\Queries\QueryNormalizer;
use PHPUnit\Framework\TestCase;

/** Bağlantı Düzeni 1.6: phone numbers and lone numbers never become filter terms by themselves. */
final class QueryJunkTermTest extends TestCase
{
    public function test_numbers_and_phone_numbers_are_junk_and_real_words_are_not(): void
    {
        foreach (['0532 111 22 33', '+90 (232) 555 44 33', '24', '2026', '7/24', 'a', '  ', 'tel 05321112233'] as $junk) {
            $this->assertTrue(QueryNormalizer::isJunkTerm($junk), $junk);
        }
        foreach (['ücretsiz', 'iş ilanı', 'ikinci el', 'implant 2026', 'e devlet'] as $word) {
            $this->assertFalse(QueryNormalizer::isJunkTerm($word), $word);
        }
    }
}
