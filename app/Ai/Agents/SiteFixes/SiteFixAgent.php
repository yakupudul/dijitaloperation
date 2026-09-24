<?php

namespace App\Ai\Agents\SiteFixes;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/** Shared provider options of the ADR-070 site-fix agents. */
abstract class SiteFixAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
