<?php

namespace App\Services\Observability;

use App\Enums\Observability\ProviderQuotaVisibility;
use App\Enums\Observability\ProviderRequestOutcome;
use App\Models\Observability\ProviderApiCounter;
use App\Support\Security\SecurityRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Central provider HTTP telemetry — counters only, no secrets/raw bodies.
 */
final class ProviderApiTelemetryService
{
    public function __construct(
        private readonly SecurityRedactor $redactor,
        private readonly OperationalTelemetryRecorder $telemetry,
    ) {}

    /**
     * @param  array{
     *     provider: string,
     *     operation: string,
     *     outcome: ProviderRequestOutcome|string,
     *     duration_ms?: int|null,
     *     http_status?: int|null,
     *     attempt?: int|null,
     *     integration_id?: int|null,
     *     retry_after_seconds?: int|null,
     *     provider_request_id?: string|null,
     *     quota_visibility?: ProviderQuotaVisibility|string|null,
     *     quota_limit?: int|null,
     *     quota_remaining?: int|null,
     *     quota_reset_at?: string|null
     * }  $input
     */
    public function recordAttempt(array $input): void
    {
        if (! config('moxdop-observability.enabled', true)) {
            return;
        }

        try {
            $provider = strtolower(trim((string) $input['provider']));
            $operation = trim((string) $input['operation']);
            if ($provider === '' || $operation === '') {
                return;
            }

            $outcome = $input['outcome'] instanceof ProviderRequestOutcome
                ? $input['outcome']
                : ProviderRequestOutcome::tryFrom((string) $input['outcome']) ?? ProviderRequestOutcome::Unknown;

            $windowSeconds = max(60, (int) config('moxdop-observability.provider_api.window_seconds', 900));
            // Bucket to 5-minute windows for bounded cardinality.
            $bucket = 300;
            $started = CarbonImmutable::now()->utc()->startOfMinute();
            $minute = (int) $started->format('i');
            $started = $started->subMinutes($minute % ($bucket / 60));

            $duration = max(0, (int) ($input['duration_ms'] ?? 0));

            DB::transaction(function () use ($provider, $operation, $started, $outcome, $duration): void {
                $row = ProviderApiCounter::query()->firstOrCreate(
                    [
                        'provider' => $provider,
                        'operation' => substr($operation, 0, 80),
                        'window_started_at' => $started,
                    ],
                    [
                        'attempts' => 0,
                        'successes' => 0,
                        'auth_errors' => 0,
                        'rate_limits' => 0,
                        'client_errors' => 0,
                        'server_errors' => 0,
                        'timeouts' => 0,
                        'network_errors' => 0,
                        'latency_sum_ms' => 0,
                    ],
                );

                $row->attempts++;
                $row->latency_sum_ms += $duration;
                match ($outcome) {
                    ProviderRequestOutcome::Success => $row->successes++,
                    ProviderRequestOutcome::Auth => $row->auth_errors++,
                    ProviderRequestOutcome::RateLimit => $row->rate_limits++,
                    ProviderRequestOutcome::Provider4xx => $row->client_errors++,
                    ProviderRequestOutcome::Provider5xx => $row->server_errors++,
                    ProviderRequestOutcome::Timeout => $row->timeouts++,
                    ProviderRequestOutcome::Network => $row->network_errors++,
                    default => null,
                };
                $row->save();
            });

            $quotaVisibility = $input['quota_visibility'] ?? null;
            if (is_string($quotaVisibility)) {
                $quotaVisibility = ProviderQuotaVisibility::tryFrom($quotaVisibility);
            }

            $this->telemetry->info('provider.request', $this->redactor->redactContext([
                'provider' => $provider,
                'operation' => $operation,
                'outcome' => $outcome->value,
                'duration_ms' => $duration,
                'http_status' => $input['http_status'] ?? null,
                'attempt' => $input['attempt'] ?? null,
                'integration_id' => $input['integration_id'] ?? null,
                'retry_after_seconds' => $input['retry_after_seconds'] ?? null,
                'provider_request_id' => isset($input['provider_request_id'])
                    ? substr((string) $input['provider_request_id'], 0, 64)
                    : null,
                'quota_visibility' => $quotaVisibility instanceof ProviderQuotaVisibility
                    ? $quotaVisibility->value
                    : ProviderQuotaVisibility::Unknown->value,
                'quota_limit' => $input['quota_limit'] ?? null,
                'quota_remaining' => $input['quota_remaining'] ?? null,
            ]));
        } catch (Throwable) {
            // Non-critical telemetry must not fail provider calls.
        }
    }

    /**
     * @return array{
     *     provider: string,
     *     operation: string,
     *     window_seconds: int,
     *     attempts: int,
     *     successes: int,
     *     auth_errors: int,
     *     rate_limits: int,
     *     server_errors: int,
     *     timeouts: int,
     *     error_rate: float|null,
     *     rate_limit_rate: float|null,
     *     avg_latency_ms: float|null,
     *     numerator_errors: int,
     *     denominator_attempts: int
     * }
     */
    public function rateSummary(string $provider, string $operation, ?int $windowSeconds = null): array
    {
        $windowSeconds ??= (int) config('moxdop-observability.provider_api.window_seconds', 900);
        $since = now()->subSeconds($windowSeconds);

        $agg = ProviderApiCounter::query()
            ->where('provider', strtolower($provider))
            ->where('operation', $operation)
            ->where('window_started_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(attempts),0) as attempts')
            ->selectRaw('COALESCE(SUM(successes),0) as successes')
            ->selectRaw('COALESCE(SUM(auth_errors),0) as auth_errors')
            ->selectRaw('COALESCE(SUM(rate_limits),0) as rate_limits')
            ->selectRaw('COALESCE(SUM(server_errors),0) as server_errors')
            ->selectRaw('COALESCE(SUM(timeouts),0) as timeouts')
            ->selectRaw('COALESCE(SUM(client_errors),0) as client_errors')
            ->selectRaw('COALESCE(SUM(network_errors),0) as network_errors')
            ->selectRaw('COALESCE(SUM(latency_sum_ms),0) as latency_sum_ms')
            ->first();

        $attempts = (int) ($agg->attempts ?? 0);
        $errors = (int) ($agg->auth_errors ?? 0)
            + (int) ($agg->server_errors ?? 0)
            + (int) ($agg->timeouts ?? 0)
            + (int) ($agg->network_errors ?? 0);
        $rateLimits = (int) ($agg->rate_limits ?? 0);
        $latencySum = (int) ($agg->latency_sum_ms ?? 0);

        return [
            'provider' => strtolower($provider),
            'operation' => $operation,
            'window_seconds' => $windowSeconds,
            'attempts' => $attempts,
            'successes' => (int) ($agg->successes ?? 0),
            'auth_errors' => (int) ($agg->auth_errors ?? 0),
            'rate_limits' => $rateLimits,
            'server_errors' => (int) ($agg->server_errors ?? 0),
            'timeouts' => (int) ($agg->timeouts ?? 0),
            'error_rate' => $attempts > 0 ? round($errors / $attempts, 4) : null,
            'rate_limit_rate' => $attempts > 0 ? round($rateLimits / $attempts, 4) : null,
            'avg_latency_ms' => $attempts > 0 ? round($latencySum / $attempts, 2) : null,
            'numerator_errors' => $errors,
            'denominator_attempts' => $attempts,
        ];
    }

    public function classifyHttpStatus(?int $status, bool $timeout = false, bool $network = false): ProviderRequestOutcome
    {
        if ($timeout) {
            return ProviderRequestOutcome::Timeout;
        }
        if ($network) {
            return ProviderRequestOutcome::Network;
        }
        if ($status === null) {
            return ProviderRequestOutcome::Unknown;
        }
        if ($status === 401 || $status === 403) {
            return ProviderRequestOutcome::Auth;
        }
        if ($status === 429) {
            return ProviderRequestOutcome::RateLimit;
        }
        if ($status >= 500) {
            return ProviderRequestOutcome::Provider5xx;
        }
        if ($status >= 400) {
            return ProviderRequestOutcome::Provider4xx;
        }
        if ($status >= 200 && $status < 300) {
            return ProviderRequestOutcome::Success;
        }

        return ProviderRequestOutcome::Unknown;
    }

    public function classifyQuotaVisibility(
        bool $hasLimit,
        bool $hasRemaining,
        bool $hasReset,
        bool $saw429Only,
    ): ProviderQuotaVisibility {
        if ($hasLimit && $hasRemaining) {
            return ProviderQuotaVisibility::ProviderReportedUsageAndLimit;
        }
        if ($hasRemaining) {
            return ProviderQuotaVisibility::ProviderReportedRemaining;
        }
        if ($hasReset) {
            return ProviderQuotaVisibility::ProviderReportedReset;
        }
        if ($saw429Only) {
            return ProviderQuotaVisibility::RateLimitSignalOnly;
        }

        return ProviderQuotaVisibility::NotExposed;
    }
}
