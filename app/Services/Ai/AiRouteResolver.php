<?php

namespace App\Services\Ai;

use App\Models\AiRouteStep;
use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Ai\ResolvedAiRoute;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resolves workflow-specific AI provider/model chains for Laravel native failover.
 */
final class AiRouteResolver
{
    public function __construct(
        private readonly AiRouteRegistry $registry,
        private readonly OpenAiCredentialResolver $openAiCredentials,
        private readonly AnthropicCredentialResolver $anthropicCredentials,
        private readonly GeminiCredentialResolver $geminiCredentials,
    ) {}

    public function resolve(string $routeKey): ResolvedAiRoute
    {
        $descriptor = $this->registry->get($routeKey);
        $persisted = AiRouteStep::query()
            ->where('route_key', $routeKey)
            ->where('enabled', true)
            ->orderBy('position')
            ->get();

        $usingPersisted = $persisted->isNotEmpty();
        $rawSteps = $usingPersisted
            ? $persisted->map(fn (AiRouteStep $step): array => [
                'provider' => $step->provider,
                'model' => $step->model,
            ])->all()
            : $descriptor['default_steps'];

        $steps = [];
        $providerModels = [];

        foreach (array_values($rawSteps) as $index => $raw) {
            $provider = (string) ($raw['provider'] ?? '');
            $model = (string) ($raw['model'] ?? '');
            $role = $index === 0 ? 'PRIMARY' : 'FALLBACK';
            $eligibility = $this->eligibility($provider);

            $steps[] = [
                'provider' => $provider,
                'model' => $model !== '' ? $model : AiProviderCatalog::defaultModel($provider),
                'role' => $role,
                'eligible' => $eligibility['eligible'],
                'reason' => $eligibility['reason'],
            ];

            if ($eligibility['eligible'] && AiProviderCatalog::isSupported($provider)) {
                $providerModels[$provider] = $model !== '' ? $model : AiProviderCatalog::defaultModel($provider);
            }
        }

        return new ResolvedAiRoute(
            routeKey: $routeKey,
            routeName: $descriptor['name'],
            providerModels: $providerModels,
            steps: $steps,
            signature: $this->signature($routeKey, $providerModels),
            usingPersistedSteps: $usingPersisted,
        );
    }

    /**
     * Stable sanitized route signature for fingerprints — never includes secrets.
     *
     * @param  array<string, string>  $providerModels
     */
    public function signature(string $routeKey, array $providerModels): string
    {
        $parts = [$routeKey];
        foreach ($providerModels as $provider => $model) {
            $parts[] = $provider.':'.$model;
        }

        return implode('|', $parts);
    }

    /**
     * @param  list<array{provider: string, model: string, enabled?: bool}>  $steps
     */
    public function saveSteps(string $routeKey, array $steps): void
    {
        $this->registry->get($routeKey);

        if ($steps === []) {
            throw ValidationException::withMessages([
                'steps' => 'At least one AI provider step is required.',
            ]);
        }

        $seen = [];
        $normalized = [];

        foreach (array_values($steps) as $index => $step) {
            $provider = (string) ($step['provider'] ?? '');
            $model = trim((string) ($step['model'] ?? ''));
            $enabled = array_key_exists('enabled', $step) ? (bool) $step['enabled'] : true;

            if (! AiProviderCatalog::isSupported($provider)) {
                throw ValidationException::withMessages([
                    'steps' => 'Unsupported AI provider: '.$provider,
                ]);
            }

            if (isset($seen[$provider])) {
                throw ValidationException::withMessages([
                    'steps' => 'Each provider may appear only once in a route (V1).',
                ]);
            }

            $seen[$provider] = true;

            if ($model === '') {
                $model = AiProviderCatalog::defaultModel($provider);
            }

            $normalized[] = [
                'route_key' => $routeKey,
                'provider' => $provider,
                'model' => $model,
                'position' => $index + 1,
                'enabled' => $enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($routeKey, $normalized): void {
            AiRouteStep::query()->where('route_key', $routeKey)->delete();
            AiRouteStep::query()->insert($normalized);
        });
    }

    /**
     * @return array{eligible: bool, reason: ?string}
     */
    private function eligibility(string $provider): array
    {
        if (! AiProviderCatalog::isSupported($provider)) {
            return ['eligible' => false, 'reason' => 'unsupported_provider'];
        }

        $integration = CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', $provider)
            ->orderBy('id')
            ->first();

        if ($integration instanceof CoreIntegration && $integration->status === CoreIntegration::STATUS_DISABLED) {
            return ['eligible' => false, 'reason' => 'integration_disabled'];
        }

        $configured = match ($provider) {
            ProviderRegistry::OPENAI, AiProviderCatalog::OPENAI => $this->isOpenAiConfigured($integration),
            AiProviderCatalog::ANTHROPIC => $this->isAnthropicConfigured($integration),
            AiProviderCatalog::GEMINI => $this->isGeminiConfigured($integration),
            default => false,
        };

        if (! $configured) {
            return ['eligible' => false, 'reason' => 'credential_missing'];
        }

        if ($integration instanceof CoreIntegration) {
            $connectionStatus = data_get($integration->config, 'connection_status');
            if ($connectionStatus === 'issue') {
                return ['eligible' => false, 'reason' => 'health_auth_failed'];
            }
        }

        return ['eligible' => true, 'reason' => null];
    }

    private function isOpenAiConfigured(?CoreIntegration $integration): bool
    {
        if ($integration instanceof CoreIntegration) {
            return $this->openAiCredentials->isConfigured($integration);
        }

        return $this->openAiCredentials->envApiKey() !== null;
    }

    private function isAnthropicConfigured(?CoreIntegration $integration): bool
    {
        if ($integration instanceof CoreIntegration) {
            return $this->anthropicCredentials->isConfigured($integration);
        }

        return $this->anthropicCredentials->envApiKey() !== null;
    }

    private function isGeminiConfigured(?CoreIntegration $integration): bool
    {
        if ($integration instanceof CoreIntegration) {
            return $this->geminiCredentials->isConfigured($integration);
        }

        return $this->geminiCredentials->envApiKey() !== null;
    }
}
