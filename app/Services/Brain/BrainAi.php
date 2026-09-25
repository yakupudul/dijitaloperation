<?php

namespace App\Services\Brain;

use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use Laravel\Ai\Contracts\Agent;
use RuntimeException;

/** Runs one Brain agent on its AI route (failover chain, budget, usage recording) and returns the structured answer. */
final class BrainAi
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    public function available(string $routeKey): bool
    {
        return ! $this->routes->resolve($routeKey)->isEmpty();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function ask(Agent $agent, string $routeKey, array $input, int $timeout = 180): array
    {
        $route = $this->routes->resolve($routeKey);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).');
        }
        $this->runtime->prepare(array_keys($route->providerModels));
        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return (array) $agent->prompt("INPUT_JSON\n".$json, provider: $route->providerModels, timeout: $timeout)->toArray();
    }
}
