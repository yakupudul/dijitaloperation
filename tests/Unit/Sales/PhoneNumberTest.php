<?php

namespace Tests\Unit\Sales;

use App\Support\Sales\PhoneNumber;
use PHPUnit\Framework\TestCase;

/** Form phone numbers: typed shapes become wa.me digits; a wrong number never opens WhatsApp. */
final class PhoneNumberTest extends TestCase
{
    public function test_common_turkish_shapes_become_one_number(): void
    {
        foreach (['+90 0538 861 02 88', '+90 538 861 02 88', '0538 861 02 88', '538 861 02 88', '0090 538 861 0288', '+90 90 538 861 02 88', '(0538) 861-02-88'] as $typed) {
            $this->assertSame('905388610288', PhoneNumber::check($typed)['digits'], $typed);
            $this->assertNull(PhoneNumber::check($typed)['issue'], $typed);
        }
        $this->assertSame('+90 538 861 02 88', PhoneNumber::pretty('+90 0538 861 02 88'));
    }

    public function test_wrong_numbers_give_a_reason_instead_of_digits(): void
    {
        foreach (['+90 538 861 02 8', '0538 861 02', '+90 538 861 02 888', '12345', '+90 638 861 02 88'] as $typed) {
            $check = PhoneNumber::check($typed);
            $this->assertNull($check['digits'], $typed);
            $this->assertNotNull($check['issue'], $typed);
            $this->assertNull(PhoneNumber::pretty($typed), $typed);
        }
        $this->assertSame(['digits' => null, 'issue' => null], PhoneNumber::check(''));
    }

    public function test_landlines_and_foreign_numbers(): void
    {
        $landline = PhoneNumber::check('0236 231 00 00');
        $this->assertSame('902362310000', $landline['digits']);
        $this->assertStringContainsString('Sabit hat', (string) $landline['issue']);
        $this->assertSame('4915112345678', PhoneNumber::check('+49 151 12345678')['digits']);
        $this->assertSame('4915112345678', PhoneNumber::check('0049 151 12345678')['digits']);
    }
}
