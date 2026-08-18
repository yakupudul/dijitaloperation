<?php

namespace App\Services\Prospects;

use App\Agents\SalesProspectIntelligenceAnalyst;
use App\Ai\Agents\SalesProspectIntelligenceAgent;
use App\Enums\ProspectSalesIntelligenceStatus;
use App\Enums\ServiceCatalogStatus;
use App\Models\Prospect;
use App\Models\ProspectDiscoveryCandidate;
use App\Models\ProspectEvidence;
use App\Models\ProspectResearchRun;
use App\Models\ProspectSalesIntelligence;
use App\Models\ServiceDefinition;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Prospects\ProspectResearchFixtures;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates advisory Sales Intelligence for a Prospect research run.
 */
final class ProspectSalesIntelligenceService
{
    public function __construct(
        private readonly ?AiRouteResolver $routes = null,
        private readonly ?AiProviderRuntimeConfig $aiRuntime = null,
        private readonly ?AgentProfileRegistry $agents = null,
        private readonly ProspectActivityRecorder $activities = new ProspectActivityRecorder,
    ) {}

    public function generate(Prospect $prospect, ProspectResearchRun $run, ?User $actor = null): ProspectSalesIntelligence
    {
        $evidence = ProspectEvidence::query()
            ->where('prospect_id', $prospect->id)
            ->orderBy('id')
            ->get();

        $candidates = ProspectDiscoveryCandidate::query()
            ->where('prospect_id', $prospect->id)
            ->orderBy('id')
            ->get();

        if ($this->shouldUseFixtures($prospect)) {
            return $this->persistFromStructured(
                $prospect,
                $run,
                ProspectResearchFixtures::salesIntelligence($evidence->pluck('id')->map(static fn ($id): int => (int) $id)->all()),
                ProspectSalesIntelligenceStatus::Available,
                ['source' => 'fixture'],
                $actor,
            );
        }

        $routes = $this->routes ?? app(AiRouteResolver::class);
        $agents = $this->agents ?? app(AgentProfileRegistry::class);
        $aiRuntime = $this->aiRuntime ?? app(AiProviderRuntimeConfig::class);

        $profile = $agents->get(SalesProspectIntelligenceAnalyst::SLUG);
        $route = $routes->resolve($profile->aiRouteKey);

        if ($route->isEmpty()) {
            return $this->persistUnavailable($prospect, $run, 'AI not configured — no eligible providers.', $actor);
        }

        $catalog = ServiceDefinition::query()
            ->where('catalog_status', ServiceCatalogStatus::Available)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'description'])
            ->map(static fn (ServiceDefinition $row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'description' => $row->description,
            ])
            ->values()
            ->all();

        $context = [
            'prospect' => [
                'id' => $prospect->id,
                'company_name' => $prospect->company_name,
                'website_url' => $prospect->website_url,
                'source' => $prospect->source->value,
                'inquiry' => $prospect->inquiry,
                'identity_status' => $prospect->identity_status->value,
            ],
            'observed_evidence' => $evidence->map(static fn (ProspectEvidence $row): array => [
                'id' => $row->id,
                'type' => $row->type,
                'title' => $row->title,
                'provenance' => $row->provenance->value,
                'source_url' => $row->source_url,
                'payload' => $row->payload,
            ])->values()->all(),
            'fact_candidates' => $candidates->map(static fn (ProspectDiscoveryCandidate $row): array => [
                'type' => $row->candidate_type,
                'value' => $row->proposed_value,
                'provenance' => $row->provenance->value,
                'source_url' => data_get($row->support_json, 'source_url'),
            ])->values()->all(),
            'service_catalog' => $catalog,
            'instructions' => [
                'Use only catalog service codes in recommended_services and not_recommended_services.',
                'Never treat AI output as observed fact.',
            ],
        ];

        $aiRuntime->prepare(array_keys($route->providerModels));

        try {
            $response = (new SalesProspectIntelligenceAgent)->prompt(
                "AGENT CONTRACT\n".json_encode([
                    'agent' => $profile->slug.'@'.$profile->version,
                    'route' => $route->routeKey,
                    'forbidden' => $profile->forbiddenOperations,
                ], JSON_UNESCAPED_SLASHES)."\n\nCONTEXT_JSON\n".json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                provider: $route->providerModels,
            );

            /** @var array<string, mixed> $structured */
            $structured = is_array($response->toArray()) ? $response->toArray() : (array) $response;
            $normalized = $this->normalizeStructured($structured, $evidence->pluck('id')->map(static fn ($id): int => (int) $id)->all());

            return $this->persistFromStructured(
                $prospect,
                $run,
                $normalized,
                ProspectSalesIntelligenceStatus::Available,
                [
                    'route_key' => $route->routeKey,
                    'provider' => $route->primaryProvider(),
                    'model' => $route->primaryModel(),
                ],
                $actor,
            );
        } catch (Throwable $exception) {
            Log::warning('prospect_sales_intelligence_failed', [
                'prospect_id' => $prospect->id,
                'run_id' => $run->id,
                'error_class' => class_basename($exception),
            ]);

            return $this->persistFromStructured(
                $prospect,
                $run,
                [
                    'summary' => null,
                    'detected_needs' => [],
                    'recommended_services' => [],
                    'not_recommended_services' => [],
                    'sales_priorities' => [],
                    'first_meeting_focus' => null,
                    'diagnostic_questions' => [],
                    'suggested_positioning' => null,
                    'uncertainties' => ['AI generation failed: '.class_basename($exception)],
                    'overall_confidence' => null,
                ],
                ProspectSalesIntelligenceStatus::Failed,
                ['error' => class_basename($exception)],
                $actor,
            );
        }
    }

    private function shouldUseFixtures(Prospect $prospect): bool
    {
        if (ProspectResearchFixtures::isFixtureUrl($prospect->website_url)) {
            return true;
        }

        return (bool) config('moxdop.prospect_research.fixtures', false);
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  list<int>  $allowedEvidenceIds
     * @return array<string, mixed>
     */
    private function normalizeStructured(array $structured, array $allowedEvidenceIds): array
    {
        $allowedCodes = array_keys(AgencyServiceOptions::options());
        $allowedEvidence = array_fill_keys($allowedEvidenceIds, true);

        $recommended = [];
        foreach (is_array($structured['recommended_services'] ?? null) ? $structured['recommended_services'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['service_definition_code'] ?? '');
            if ($code === '' || ! in_array($code, $allowedCodes, true)) {
                continue;
            }
            $refs = [];
            foreach (is_array($row['evidence_refs'] ?? null) ? $row['evidence_refs'] : [] as $ref) {
                $id = is_array($ref) ? (int) ($ref['evidence_id'] ?? 0) : 0;
                if ($id > 0 && isset($allowedEvidence[$id])) {
                    $refs[] = ['evidence_id' => $id];
                }
            }
            $recommended[] = [
                'service_definition_code' => $code,
                'service_definition_label' => AgencyServiceOptions::label($code),
                'priority' => (string) ($row['priority'] ?? 'medium'),
                'rationale' => mb_substr((string) ($row['rationale'] ?? ''), 0, 1000),
                'evidence_refs' => $refs,
                'confidence' => (string) ($row['confidence'] ?? 'moderate'),
            ];
        }

        $notRecommended = [];
        foreach (is_array($structured['not_recommended_services'] ?? null) ? $structured['not_recommended_services'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['service_definition_code'] ?? '');
            if ($code === '' || ! in_array($code, $allowedCodes, true)) {
                continue;
            }
            $notRecommended[] = [
                'service_definition_code' => $code,
                'service_definition_label' => AgencyServiceOptions::label($code),
                'rationale' => mb_substr((string) ($row['rationale'] ?? ''), 0, 1000),
            ];
        }

        return [
            'summary' => isset($structured['summary']) ? mb_substr((string) $structured['summary'], 0, 2000) : null,
            'detected_needs' => $this->stringList($structured['detected_needs'] ?? [], 12),
            'recommended_services' => $recommended,
            'not_recommended_services' => $notRecommended,
            'sales_priorities' => $this->stringList($structured['sales_priorities'] ?? [], 10),
            'first_meeting_focus' => isset($structured['first_meeting_focus']) ? mb_substr((string) $structured['first_meeting_focus'], 0, 500) : null,
            'diagnostic_questions' => $this->stringList($structured['diagnostic_questions'] ?? [], 12),
            'suggested_positioning' => isset($structured['suggested_positioning']) ? mb_substr((string) $structured['suggested_positioning'], 0, 1000) : null,
            'uncertainties' => $this->stringList($structured['uncertainties'] ?? [], 10),
            'overall_confidence' => isset($structured['overall_confidence']) ? (string) $structured['overall_confidence'] : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }
            $out[] = mb_substr($trimmed, 0, 500);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $metadata
     */
    private function persistFromStructured(
        Prospect $prospect,
        ProspectResearchRun $run,
        array $structured,
        ProspectSalesIntelligenceStatus $status,
        array $metadata,
        ?User $actor,
    ): ProspectSalesIntelligence {
        $record = ProspectSalesIntelligence::query()->create([
            'prospect_id' => $prospect->id,
            'prospect_research_run_id' => $run->id,
            'summary' => $structured['summary'] ?? null,
            'detected_needs' => $structured['detected_needs'] ?? [],
            'recommended_services' => $structured['recommended_services'] ?? [],
            'not_recommended_services' => $structured['not_recommended_services'] ?? [],
            'sales_priorities' => $structured['sales_priorities'] ?? [],
            'first_meeting_focus' => $structured['first_meeting_focus'] ?? null,
            'diagnostic_questions' => $structured['diagnostic_questions'] ?? [],
            'suggested_positioning' => $structured['suggested_positioning'] ?? null,
            'uncertainties' => $structured['uncertainties'] ?? [],
            'overall_confidence' => $structured['overall_confidence'] ?? null,
            'status' => $status,
            'metadata' => $metadata,
        ]);

        if ($status === ProspectSalesIntelligenceStatus::Available) {
            $this->activities->record(
                $prospect,
                'prospect.sales_intelligence_generated',
                __('operator.prospects.activity.sales_intelligence_generated'),
                null,
                $actor,
                ['intelligence_id' => $record->id, 'run_id' => $run->id],
            );
        }

        return $record;
    }

    private function persistUnavailable(
        Prospect $prospect,
        ProspectResearchRun $run,
        string $reason,
        ?User $actor,
    ): ProspectSalesIntelligence {
        return ProspectSalesIntelligence::query()->create([
            'prospect_id' => $prospect->id,
            'prospect_research_run_id' => $run->id,
            'status' => ProspectSalesIntelligenceStatus::Unavailable,
            'uncertainties' => [$reason],
            'metadata' => ['reason' => $reason],
        ]);
    }
}
