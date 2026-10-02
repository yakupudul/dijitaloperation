<?php

namespace App\Ai\Agents\Site;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiProviderOptions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Faz 4a Site ekranı agents: instructions from the PromptRegistry (operation = AI route key), data sent as DATA_JSON,
 * structured output validated against the data pack by the calling service before anything is stored or shown.
 */
abstract class SiteAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
