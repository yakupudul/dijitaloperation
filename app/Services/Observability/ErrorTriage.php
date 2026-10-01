<?php

namespace App\Services\Observability;

use App\Enums\Observability\OperationalAlertState;
use App\Models\Observability\OperationalAlert;
use App\Support\Operator\CollectionErrorExplainer;
use Illuminate\Support\Collection;

/**
 * Hata merkezi (operator decision 2026-11-17): every open system alert lands in one of three buckets, so the operator
 * sees only what needs a person.
 *
 *  - `you`: needs the operator's click (reconnect, grant access, a mapping to fix, a stopped worker);
 *  - `auto`: the system heals it by itself (provider hiccup, quota, rate limit, a stuck run, a queue backlog) — no bell;
 *    when it has not healed after ESCALATE_HOURS it moves to `you`;
 *  - `code`: a MoxDOP software error — retrying does not help, it is reported to the developer.
 *
 * Rule-based; no AI. Grouping joins alerts with the same bucket and cause ("Meta geçici hata · 3 hesap").
 */
final class ErrorTriage
{
    public const string YOU = 'you';

    public const string AUTO = 'auto';

    public const string CODE = 'code';

    public const array LABELS = [
        self::YOU => 'Senin işin',
        self::AUTO => 'Sistem hallediyor',
        self::CODE => 'Yazılım hatası — geliştiriciye ilet',
    ];

    /** An "auto" alert still open after this long needs a person. */
    public const int ESCALATE_HOURS = 48;

    public function __construct(private readonly OperationalAlertExplainer $explainer) {}

    /** Bucket of one alert (cause only, escalation applied). */
    public function bucket(OperationalAlert $alert): string
    {
        $bucket = self::cause($alert);
        if ($bucket === self::AUTO && ($opened = $alert->opened_at ?? $alert->first_observed_at) !== null
            && $opened->lt(now()->subHours(self::ESCALATE_HOURS))) {
            return self::YOU;
        }

        return $bucket;
    }

    /** Bucket by cause alone (no age): used when the alert opens, to decide whether it rings the bell. */
    public static function cause(OperationalAlert $alert): string
    {
        $rule = (string) $alert->rule_key;
        $observed = is_array($alert->observed) ? $alert->observed : [];

        return match (true) {
            in_array($rule, ['credential_reconnect_required', 'credential_expiring', 'worker_heartbeat_missing'], true) => self::YOU,
            in_array($rule, ['provider_rate_limited', 'provider_error_rate', 'collection_stuck'], true), str_starts_with($rule, 'queue_'),
            $rule === QueueWaitMonitor::RULE_KEY => self::AUTO,
            $rule === 'resource-automation.queries' => self::YOU,
            str_starts_with($rule, 'resource-automation.') => self::byReason((string) ($observed['reason'] ?? 'collection_failed'), self::firstCategory($observed)),
            in_array($rule, ['dataset_stale', 'collection_repeated_failure'], true) => self::needsAction($observed) ? self::YOU : self::byKind(self::firstCategory($observed) ?? 'provider'),
            default => self::YOU,
        };
    }

    /**
     * Open alerts grouped for the page: bucket => groups (same cause), each with its alerts explained.
     *
     * @return array<string, list<array{key: string, title: string, count: int, items: list<array<string, mixed>>}>>
     */
    public function groups(): array
    {
        $out = [self::YOU => [], self::CODE => [], self::AUTO => []];
        $alerts = OperationalAlert::query()->whereIn('state', [OperationalAlertState::Open->value, OperationalAlertState::Acknowledged->value])
            ->orderByDesc('last_observed_at')->limit(200)->get();
        foreach ($this->grouped($alerts) as $bucket => $groups) {
            $out[$bucket] = $groups;
        }

        return $out;
    }

    /** @return array<string, int> bucket => open alert count */
    public function counts(): array
    {
        return collect($this->groups())->map(fn (array $groups): int => (int) collect($groups)->sum('count'))->all();
    }

    /**
     * @param  Collection<int, OperationalAlert>  $alerts
     * @return array<string, list<array{key: string, title: string, count: int, items: list<array<string, mixed>>}>>
     */
    private function grouped(Collection $alerts): array
    {
        $buckets = [];
        foreach ($alerts as $alert) {
            $bucket = $this->bucket($alert);
            $message = $this->explainer->explain($alert);
            $key = OperationalAlertExplainer::topicRule((string) $alert->rule_key).'|'.(self::firstCategory(is_array($alert->observed) ? $alert->observed : []) ?? '');
            $title = trim(explode(' · ', $message->title, 2)[0]);
            $buckets[$bucket][$key] ??= ['key' => $key, 'title' => $title, 'count' => 0, 'items' => []];
            $buckets[$bucket][$key]['count']++;
            $buckets[$bucket][$key]['items'][] = [
                'id' => (int) $alert->id,
                'title' => $message->title,
                'what' => $message->what,
                'action' => $message->action,
                'link_url' => $message->linkUrl,
                'link_label' => $message->linkLabel,
                'button' => $message->button,
                'since' => (string) ($alert->opened_at ?? $alert->first_observed_at),
                'escalated' => $bucket === self::YOU && self::cause($alert) === self::AUTO,
            ];
        }

        return collect($buckets)->map(fn (array $groups): array => collect($groups)->sortByDesc('count')->values()->all())->all();
    }

    private static function byReason(string $reason, ?string $category): string
    {
        return match ($reason) {
            'reconnect' => self::YOU,
            'request_requires_fix' => self::CODE,
            'cancelled' => self::AUTO,
            default => self::byKind($category ?? 'provider'),
        };
    }

    private static function byKind(string $category): string
    {
        return match (CollectionErrorExplainer::explain($category)['kind']) {
            'retry', 'wait' => self::AUTO,
            'developer' => self::CODE,
            default => self::YOU,
        };
    }

    /** @param  array<string, mixed>  $observed */
    private static function needsAction(array $observed): bool
    {
        foreach ((array) ($observed['affected'] ?? []) as $row) {
            if (is_array($row) && array_intersect((array) ($row['states'] ?? []), ['ACTION_REQUIRED']) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $observed */
    private static function firstCategory(array $observed): ?string
    {
        foreach ((array) ($observed['affected'] ?? []) as $row) {
            if (is_array($row) && filled($row['error_category'] ?? null)) {
                return (string) $row['error_category'];
            }
        }

        return filled($observed['error_category'] ?? null) ? (string) $observed['error_category'] : null;
    }
}
