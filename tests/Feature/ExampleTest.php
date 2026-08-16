<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Guests hitting `/` are sent to the Filament login used for auth,
     * then land in the TailAdmin `/app` product after sign-in.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect('/app/login');
    }
}
