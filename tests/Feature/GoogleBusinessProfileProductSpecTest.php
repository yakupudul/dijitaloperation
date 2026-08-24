<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Runtime replacement for markdown heading checks. GBP discovery stays gated.
 */
class GoogleBusinessProfileProductSpecTest extends TestCase
{
    public function test_gbp_discovery_is_disabled_until_api_access_exists(): void
    {
        $this->assertFalse((bool) config('moxdop.google.gbp_discovery_enabled'));
        $this->assertFalse((bool) config('moxdop.google.include_gbp_scope'));
        $this->assertTrue(is_dir(base_path('app-modules/google-business-profile')));
    }
}
