<?php

namespace App\Services\Sales;

use App\Agents\SalesIntentClassificationAnalyst;
use App\Ai\Agents\SalesIntentClassificationAgent;
use App\Enums\IntentClassificationStatus;
use App\Enums\IntentPurchaseStage;
use App\Enums\ProspectIdentityStatus;
use App\Enums\ServiceCatalogStatus;
use App\Models\SalesSearchProfile;
use App\Models\ServiceDefinition;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Sales\IntentSearchConfig;
use Illuminate\Support\Facades\Log;
use Throwable;

final class IntentClassificationService
{
    /**
     * @return array{
     *     purchase_stage: IntentPurchaseStage,
     *     intent_confidence: ?int,
     *     intent_category: ?string,
     *     service_definition_code: ?string,
     *     reason: ?string,
     *     negative_signals: list<string>,
     *     identity_status: ProspectIdentityStatus,
     *     identity_confidence: ?int,
     *     detected_company_name: ?string,
     *     detected_domain: ?string,
     *     classification_status: IntentClassificationStatus
     * }
     */
    public function classify(
        SalesSearchProfile $profile,
        string $snippet,
        ?string $fetchedExcerpt = null,
        ?string $sourceUrl = null,
    ): array {
        if (IntentSearchConfig::fixturesEnabled()) {
            return $this->fixtureClassify($profile, $snippet, $fetchedExcerpt);
        }

        $catalogCodes = $this->catalogCodes();

        try {
            $routes = app(AiRouteResolver::class);
            $agents = app(AgentProfileRegistry::class);
            $aiRuntime = app(AiProviderRuntimeConfig::class);
            $agentProfile = $agents->get(SalesIntentClassificationAnalyst::SLUG);
            $route = $routes->resolve($agentProfile->aiRouteKey);

            if ($route->isEmpty()) {
                return $this->unavailable($profile);
            }

            $aiRuntime->prepare(array_keys($route->providerModels));
            $response = (new SalesIntentClassificationAgent)->prompt(
                "CONTEXT_JSON\n".json_encode([
                    'snippet' => $snippet,
                    'fetched_excerpt' => $fetchedExcerpt,
                    'source_url' => $sourceUrl,
                    'profile_service' => $profile->service_definition_code,
                    'service_catalog' => $catalogCodes,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                provider: $route->providerModels,
            );

            $structured = is_array($response->toArray()) ? $response->toArray() : (array) $response;

            return $this->normalize($structured, $profile, $catalogCodes);
        } catch (Throwable $exception) {
            Log::warning('intent_classification_failed', [
                'profile_id' => $profile->id,
                'error_class' => class_basename($exception),
            ]);

            return $this->unavailable($profile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureClassify(SalesSearchProfile $profile, string $snippet, ?string $fetchedExcerpt): array
    {
        $text = mb_strtolower($snippet.' '.($fetchedExcerpt ?? ''));
        $informational = str_contains($text, 'nasıl yapılır') || str_contains($text, 'nedir') || str_contains($text, 'how to');
        $high = str_contains($text, 'ajans arıyoruz')
            || str_contains($text, 'arıyoruz') && (str_contains($text, 'yaptırmak') || str_contains($text, 'ajans'));

        if ($informational && ! $high) {
            return [
                'purchase_stage' => IntentPurchaseStage::Informational,
                'intent_confidence' => 20,
                'intent_category' => $profile->service_definition_code,
                'service_definition_code' => $profile->service_definition_code,
                'reason' => 'Informational how-to query without an agency hiring request.',
                'negative_signals' => ['how_to', 'informational'],
                'identity_status' => ProspectIdentityStatus::Unknown,
                'identity_confidence' => 0,
                'detected_company_name' => null,
                'detected_domain' => null,
                'classification_status' => IntentClassificationStatus::Available,
            ];
        }

        return [
            'purchase_stage' => IntentPurchaseStage::HighIntent,
            'intent_confidence' => 85,
            'intent_category' => $profile->service_definition_code,
            'service_definition_code' => $profile->service_definition_code,
            'reason' => 'Explicit request to hire an agency for a billed service.',
            'negative_signals' => [],
            'identity_status' => ProspectIdentityStatus::Unknown,
            'identity_confidence' => 10,
            'detected_company_name' => null,
            'detected_domain' => null,
            'classification_status' => IntentClassificationStatus::Available,
        ];
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  list<string>  $catalogCodes
     * @return array<string, mixed>
     */
    private function normalize(array $structured, SalesSearchProfile $profile, array $catalogCodes): array
    {
        $code = isset($structured['service_definition_code']) ? trim((string) $structured['service_definition_code']) : '';
        if ($code !== '' && ! in_array($code, $catalogCodes, true)) {
            $code = $profile->service_definition_code ?? '';
        }
        if ($code === '') {
            $code = $profile->service_definition_code;
        }

        $stage = IntentPurchaseStage::tryFrom((string) ($structured['purchase_stage'] ?? '')) ?? IntentPurchaseStage::Unknown;
        $identity = ProspectIdentityStatus::tryFrom((string) ($structured['identity_status'] ?? '')) ?? ProspectIdentityStatus::Unknown;

        $confidence = isset($structured['intent_confidence']) ? (int) $structured['intent_confidence'] : null;
        if ($confidence !== null) {
            $confidence = max(0, min(100, $confidence));
        }

        $identityConfidence = isset($structured['identity_confidence']) ? (int) $structured['identity_confidence'] : null;
        if ($identityConfidence !== null) {
            $identityConfidence = max(0, min(100, $identityConfidence));
        }

        $negatives = [];
        if (is_array($structured['negative_signals'] ?? null)) {
            foreach ($structured['negative_signals'] as $signal) {
                if (is_string($signal) && trim($signal) !== '') {
                    $negatives[] = trim($signal);
                }
            }
        }

        $company = isset($structured['detected_company_name']) ? trim((string) $structured['detected_company_name']) : '';
        $domain = isset($structured['detected_domain']) ? trim((string) $structured['detected_domain']) : '';

        return [
            'purchase_stage' => $stage,
            'intent_confidence' => $confidence,
            'intent_category' => $code,
            'service_definition_code' => $code,
            'reason' => isset($structured['reason']) ? trim((string) $structured['reason']) : null,
            'negative_signals' => $negatives,
            'identity_status' => $identity,
            'identity_confidence' => $identityConfidence,
            'detected_company_name' => $company !== '' ? $company : null,
            'detected_domain' => $domain !== '' ? $domain : null,
            'classification_status' => IntentClassificationStatus::Available,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(SalesSearchProfile $profile): array
    {
        return [
            'purchase_stage' => IntentPurchaseStage::Unknown,
            'intent_confidence' => null,
            'intent_category' => $profile->service_definition_code,
            'service_definition_code' => $profile->service_definition_code,
            'reason' => 'AI not configured — classification unavailable.',
            'negative_signals' => [],
            'identity_status' => ProspectIdentityStatus::Unknown,
            'identity_confidence' => null,
            'detected_company_name' => null,
            'detected_domain' => null,
            'classification_status' => IntentClassificationStatus::Unavailable,
        ];
    }

    /**
     * @return list<string>
     */
    private function catalogCodes(): array
    {
        return ServiceDefinition::query()
            ->where('catalog_status', ServiceCatalogStatus::Available)
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->all();
    }
}
