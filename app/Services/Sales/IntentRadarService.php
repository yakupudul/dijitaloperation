<?php

namespace App\Services\Sales;

use App\Enums\IntentRadarRunStatus;
use App\Enums\IntentSignalStatus;
use App\Enums\IntentSourceVerificationState;
use App\Models\SalesIntentRadarRun;
use App\Models\SalesIntentSignal;
use App\Models\SalesSearchProfile;
use App\Models\User;
use App\Services\Prospects\ProspectDuplicateDetector;
use App\Support\Sales\IntentSearchConfig;
use App\Support\Sales\IntentSearchFixtures;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use MoxDop\Website\Discovery\PublicUrlSafety;
use Throwable;

final class IntentRadarService
{
    public function __construct(
        private readonly IntentQueryPlanner $planner = new IntentQueryPlanner,
        private readonly IntentClassificationService $classifier = new IntentClassificationService,
        private readonly IntentActivityRecorder $activities = new IntentActivityRecorder,
        private readonly PublicHttpFetcher $fetcher = new PublicHttpFetcher,
        private readonly PublicUrlSafety $urlSafety = new PublicUrlSafety,
    ) {}

    public function run(SalesSearchProfile $profile, User $actor, bool $paidConsent = false): SalesIntentRadarRun
    {
        $queries = $this->planner->plan($profile);
        $fixtures = IntentSearchConfig::fixturesEnabled();
        $paidEnabled = IntentSearchConfig::paidCallsEnabled();

        $run = SalesIntentRadarRun::query()->create([
            'sales_search_profile_id' => $profile->id,
            'status' => IntentRadarRunStatus::Running,
            'provider' => IntentSearchConfig::PROVIDER,
            'provider_reality' => $fixtures ? 'partial' : 'unavailable',
            'query_count' => count($queries),
            'signal_count' => 0,
            'paid_call' => false,
            'query_plan' => $queries,
            'started_at' => now(),
            'metadata' => [
                'capability' => IntentSearchConfig::CAPABILITY,
                'trigger' => 'manual',
            ],
        ]);

        $this->activities->record(
            'intent_run.started',
            __('operator.sales_intent.activity.run_started'),
            $profile,
            $run,
            actor: $actor,
        );

        if ($queries === []) {
            return $this->finish($run, IntentRadarRunStatus::Failed, ['message' => __('operator.sales_intent.no_queries')]);
        }

        if (! $fixtures && ! $paidEnabled) {
            return $this->finish($run, IntentRadarRunStatus::Failed, [
                'message' => __('operator.sales_intent.paid_calls_off'),
            ], providerReality: 'unavailable');
        }

        if (! $fixtures && $paidEnabled && ! $paidConsent) {
            return $this->finish($run, IntentRadarRunStatus::Failed, [
                'message' => __('operator.sales_intent.paid_consent_required'),
            ], providerReality: 'unavailable');
        }

        $adapter = $fixtures ? null : app(DataForSeoIntentSearchAdapter::class);
        if (! $fixtures) {
            $reality = $adapter->reality();
            $run->provider_reality = $reality['reality'];
            $run->save();
            if ($reality['reality'] !== 'real') {
                return $this->finish($run, IntentRadarRunStatus::Failed, [
                    'message' => $reality['message'],
                ], providerReality: $reality['reality']);
            }
        }

        $errors = [];
        $created = 0;
        $updated = 0;
        $cost = 0.0;
        $paid = false;

        foreach ($queries as $query) {
            try {
                $rows = $fixtures
                    ? IntentSearchFixtures::results($query)
                    : $adapter->search(
                        $query,
                        (string) ($profile->language ?: 'tr'),
                        $this->locationName($profile),
                    );
                if (! $fixtures) {
                    $paid = true;
                }

                foreach ($rows as $row) {
                    $result = $this->upsertSignal($profile, $run, $query, $row);
                    if ($result === 'created') {
                        $created++;
                    }
                    if ($result === 'updated') {
                        $updated++;
                    }
                    if (isset($row['cost_usd']) && is_numeric($row['cost_usd'])) {
                        $cost += (float) $row['cost_usd'];
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = [
                    'query' => $query,
                    'error' => class_basename($exception),
                ];
            }
        }

        $status = match (true) {
            $errors !== [] && ($created + $updated) === 0 => IntentRadarRunStatus::Failed,
            $errors !== [] => IntentRadarRunStatus::Partial,
            default => IntentRadarRunStatus::Completed,
        };

        $finished = $this->finish($run, $status, [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ], $fixtures ? 'partial' : 'real', $created + $updated, $paid, $paid ? $cost : null);

        $this->activities->record(
            'intent_run.completed',
            __('operator.sales_intent.activity.run_completed'),
            $profile,
            $finished,
            actor: $actor,
            metadata: ['status' => $status->value, 'signals' => $created + $updated],
        );

        return $finished;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertSignal(SalesSearchProfile $profile, SalesIntentRadarRun $run, string $query, array $row): string
    {
        $url = $this->normalizeSourceUrl((string) ($row['source_url'] ?? ''));
        if ($url === null) {
            return 'skipped';
        }

        $snippet = trim((string) ($row['observed_snippet'] ?? ''));
        if ($snippet === '') {
            return 'skipped';
        }

        if ($this->matchesExclude($profile, $snippet) && $this->isOnlyExcluded($profile, $snippet)) {
            // Keep informational excluded how-tos; still persist so operators can review.
        }

        $fingerprint = hash('sha256', implode('|', [
            (string) $profile->id,
            (string) ($profile->service_definition_code ?? ''),
            $url,
            hash('sha256', mb_strtolower($snippet)),
        ]));

        $existing = SalesIntentSignal::query()->where('fingerprint', $fingerprint)->first();
        if ($existing instanceof SalesIntentSignal) {
            $existing->last_seen_at = now();
            $existing->sales_intent_radar_run_id = $run->id;
            $existing->save();

            return 'updated';
        }

        $verification = IntentSourceVerificationState::Unverified;
        $fetched = null;
        if (isset($row['verification']) && $row['verification'] === 'verified') {
            $verification = IntentSourceVerificationState::Verified;
            $fetched = isset($row['fetched_excerpt']) ? (string) $row['fetched_excerpt'] : null;
        } elseif (! IntentSearchConfig::fixturesEnabled()) {
            [$verification, $fetched] = $this->verifySource($url);
        }

        $classified = $this->classifier->classify($profile, $snippet, $fetched, $url);

        SalesIntentSignal::query()->create([
            'sales_search_profile_id' => $profile->id,
            'sales_intent_radar_run_id' => $run->id,
            'source_type' => 'search_result',
            'source_url' => $url,
            'source_title' => isset($row['source_title']) ? (string) $row['source_title'] : null,
            'observed_snippet' => $snippet,
            'fetched_source_excerpt' => $fetched,
            'source_verification_state' => $verification,
            'discovered_at' => now(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'intent_category' => $classified['intent_category'],
            'service_definition_code' => $classified['service_definition_code'],
            'intent_confidence' => $classified['intent_confidence'],
            'purchase_stage' => $classified['purchase_stage'],
            'classification_status' => $classified['classification_status'],
            'classification_reason' => $classified['classification_reason'] ?? $classified['reason'] ?? null,
            'negative_signals' => $this->mergeNegatives($profile, $snippet, $classified['negative_signals']),
            'identity_status' => $classified['identity_status'],
            'identity_confidence' => $classified['identity_confidence'],
            'detected_company_name' => $classified['detected_company_name'],
            'detected_domain' => $classified['detected_domain'],
            'status' => IntentSignalStatus::New,
            'fingerprint' => $fingerprint,
            'provenance' => [
                'provider' => IntentSearchConfig::PROVIDER,
                'capability' => IntentSearchConfig::CAPABILITY,
                'query' => $query,
                'retrieval' => IntentSearchConfig::fixturesEnabled() ? 'fixture' : 'serp_live_regular',
                'snippet_vs_fetch' => [
                    'snippet' => 'search_result_snippet',
                    'fetched' => $verification->value,
                ],
            ],
        ]);

        return 'created';
    }

    /**
     * @return array{0: IntentSourceVerificationState, 1: ?string}
     */
    private function verifySource(string $url): array
    {
        try {
            $this->urlSafety->assertSafePublicHttpUrl($url);
            $result = $this->fetcher->fetch($url);
            if (! ($result['ok'] ?? false) || ! is_string($result['body'] ?? null)) {
                return [IntentSourceVerificationState::Unreachable, null];
            }

            $text = trim(html_entity_decode(strip_tags((string) $result['body'])));
            $excerpt = mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 500);

            return [IntentSourceVerificationState::Verified, $excerpt !== '' ? $excerpt : null];
        } catch (Throwable) {
            return [IntentSourceVerificationState::Unreachable, null];
        }
    }

    private function matchesExclude(SalesSearchProfile $profile, string $snippet): bool
    {
        $text = mb_strtolower($snippet);
        foreach (is_array($profile->exclude_concepts) ? $profile->exclude_concepts : [] as $phrase) {
            if (is_string($phrase) && $phrase !== '' && str_contains($text, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    private function isOnlyExcluded(SalesSearchProfile $profile, string $snippet): bool
    {
        return $this->matchesExclude($profile, $snippet);
    }

    /**
     * @param  list<string>  $negatives
     * @return list<string>
     */
    private function mergeNegatives(SalesSearchProfile $profile, string $snippet, array $negatives): array
    {
        if ($this->matchesExclude($profile, $snippet)) {
            $negatives[] = 'exclude_concept_match';
        }

        return array_values(array_unique($negatives));
    }

    private function normalizeSourceUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        try {
            $this->urlSafety->assertSafePublicHttpUrl($url);
        } catch (Throwable) {
            if (IntentSearchConfig::fixturesEnabled() && str_contains($url, 'intent-fixture.moxdop-e2e.test')) {
                return rtrim($url, '/');
            }

            return null;
        }

        $host = ProspectDuplicateDetector::normalizeDomain($url);
        $path = parse_url($url, PHP_URL_PATH);
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        if (! is_string($host) || $host === '') {
            return null;
        }

        $path = is_string($path) && $path !== '' ? rtrim($path, '/') : '';

        return $scheme.'://'.$host.$path;
    }

    private function locationName(SalesSearchProfile $profile): string
    {
        if (is_string($profile->location) && trim($profile->location) !== '') {
            return trim($profile->location);
        }

        return match ($profile->country) {
            'TR' => 'Turkey',
            'DE' => 'Germany',
            'GB' => 'United Kingdom',
            'US' => 'United States',
            default => 'Turkey',
        };
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private function finish(
        SalesIntentRadarRun $run,
        IntentRadarRunStatus $status,
        array $errors,
        ?string $providerReality = null,
        int $signalCount = 0,
        bool $paid = false,
        ?float $cost = null,
    ): SalesIntentRadarRun {
        $run->status = $status;
        $run->finished_at = now();
        $run->error_summary = $errors;
        $run->signal_count = $signalCount;
        $run->paid_call = $paid;
        $run->reported_cost_usd = $cost;
        if ($providerReality !== null) {
            $run->provider_reality = $providerReality;
        }
        $run->save();

        return $run->fresh() ?? $run;
    }
}
