<?php

namespace Tests\Unit;

use App\Services\Integrations\PaidRequestFingerprint;
use PHPUnit\Framework\TestCase;

class PaidRequestFingerprintTest extends TestCase
{
    public function test_nested_parameter_ordering_is_stable(): void
    {
        $a = PaidRequestFingerprint::make('dataforseo', 'labs', 'endpoint', [
            'filters' => ['b' => 2, 'a' => 1],
            'keyword' => 'shoes',
        ]);
        $b = PaidRequestFingerprint::make('dataforseo', 'labs', 'endpoint', [
            'keyword' => 'shoes',
            'filters' => ['a' => 1, 'b' => 2],
        ]);

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function test_strips_secret_keys_from_nested_arrays(): void
    {
        $a = PaidRequestFingerprint::make('dataforseo', 'labs', 'endpoint', [
            'keyword' => 'shoes',
            'auth' => ['password' => 'x', 'token' => 'y'],
        ]);
        $b = PaidRequestFingerprint::make('dataforseo', 'labs', 'endpoint', [
            'keyword' => 'shoes',
            'auth' => ['password' => 'different', 'token' => 'other'],
        ]);

        $this->assertSame($a, $b);
    }
}
