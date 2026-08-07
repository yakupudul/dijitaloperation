<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationBootTest extends TestCase
{
    public function test_the_application_boots(): void
    {
        $this->assertSame('MoxDOP', config('app.name'));
        $this->assertTrue($this->app->isBooted());
    }

    public function test_health_endpoint_is_ok(): void
    {
        $this->get('/up')->assertOk();
    }
}
