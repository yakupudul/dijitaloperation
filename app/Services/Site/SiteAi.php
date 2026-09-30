<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\SiteAgent;
use App\Services\Ai\AiCancellation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One structured call of a website-screen AI operation: route (budget, providers) → DATA_JSON → structured output.
 * Never throws; the caller validates the output against its own data pack before storing anything.
 */
final class SiteAi
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, data: array<string, mixed>, prompt_version_id: ?int} status: ready | no_provider | error
     */
    public function run(SiteAgent $agent, array $data, int $timeout = 180): array
    {
        AiCancellation::throwIfRequested();
        try {
            $route = $this->routes->resolve($agent->promptOperation());
            if ($route->isEmpty()) {
                return ['status' => 'no_provider', 'data' => [], 'prompt_version_id' => null];
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $output = $agent->prompt(
                "DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: $timeout,
            )->toArray();

            return ['status' => 'ready', 'data' => is_array($output) ? $output : [], 'prompt_version_id' => $agent->promptVersionId()];
        } catch (Throwable $exception) {
            Log::warning('Site AI operation failed.', ['operation' => $agent->promptOperation(), 'error' => $exception->getMessage()]);

            return ['status' => 'error', 'data' => [], 'prompt_version_id' => null];
        }
    }
}
