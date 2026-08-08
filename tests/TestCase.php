<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CI/gate does not always produce public/build; Filament HTTP tests must not require Vite assets.
        $this->withoutVite();
    }
}
