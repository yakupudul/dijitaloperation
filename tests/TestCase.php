<?php

namespace Tests;

use App\Services\DataPool\Compact\CompactFactStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CI/gate does not always produce public/build; Filament HTTP tests must not require Vite assets.
        $this->withoutVite();

        // Compact fact storage caches dictionary ids per process; each test's rolled-back data must not leak.
        CompactFactStore::forgetCache();
    }
}
