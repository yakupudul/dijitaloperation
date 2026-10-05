<?php

namespace App\Services\Observability;

use App\Enums\Observability\OperationalAlertState;
use App\Models\Observability\OperationalAlert;
use App\Services\Verification\LiveVerifier;
use App\Support\Operator\CollectionErrorExplainer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * Latest live check problem per account, read once for one groups() call (null outside it, so cause() from the
     * notifier always reads the table).
     *
     * @var array<int, ?string>|null
     */
    private static ?array $liveProblems = null;

    /** live_checks exists (only a positive answer is kept: a table that is missing now may be migrated later). */
    private static bool $hasLiveChecks = false;

    public function __construct(private readonly OperationalAlertExplainer $explainer) {}

    /** Bucket of one alert (cause only, escalation applied). */
    public function bucket(OperationalAlert $alert): string
    {
        return self::escalate($alert, self::cause($alert));
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
            // The morning live check says the account itself is closed / disabled / not accessible: retrying never helps.
            str_starts_with($rule, 'resource-automation.') && self::liveProblem((int) $alert->scope_key) !== null => self::YOU,
            str_starts_with($rule, 'resource-automation.') => self::byReason((string) ($observed['reason'] ?? 'collection_failed'), self::firstCategory($observed)),
            in_array($rule, ['dataset_stale', 'collection_repeated_failure'], true) => self::needsAction($observed) ? self::YOU : self::byCategories($observed),
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
        self::$liveProblems = self::liveProblems($alerts);
        try {
            foreach ($this->grouped($alerts) as $bucket => $groups) {
                $out[$bucket] = $groups;
            }
        } finally {
            self::$liveProblems = null;
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
            $cause = self::cause($alert);
            $bucket = self::escalate($alert, $cause);
            $message = $this->explainer->explain($alert);
            $key = OperationalAlertExplainer::topicRule((string) $alert->rule_key).'|'.(self::firstCategory(is_array($alert->observed) ? $alert->observed : []) ?? '');
            $title = trim(explode(' · ', $message->title, 2)[0]);
            $buckets[$bucket][$key] ??= ['key' => $key, 'title' => $title, 'count' => 0, 'items' => []];
            $buckets[$bucket][$key]['count']++;
            $buckets[$bucket][$key]['items'][] = [
                'id' => (int) $alert->id,
                'title' => $message->title,
                'what' => trim($message->what.(str_starts_with((string) $alert->rule_key, 'resource-automation.') && ($live = self::liveProblem((int) $alert->scope_key)) !== null
                    ? ' Canlı doğrulama: '.$live.' Hesabı açın / yetki verin ya da kullanılmıyorsa markadan ayırın.' : '')),
                'action' => $message->action,
                'link_url' => $message->linkUrl,
                'link_label' => $message->linkLabel,
                'button' => $message->button,
                'since' => (string) ($alert->opened_at ?? $alert->first_observed_at),
                'escalated' => $bucket === self::YOU && $cause === self::AUTO,
            ];
        }

        return collect($buckets)->map(fn (array $groups): array => collect($groups)->sortByDesc('count')->values()->all())->all();
    }

    /** An "auto" cause still open after ESCALATE_HOURS becomes the operator's work. */
    private static function escalate(OperationalAlert $alert, string $cause): string
    {
        if ($cause === self::AUTO && ($opened = $alert->opened_at ?? $alert->first_observed_at) !== null
            && $opened->lt(now()->subHours(self::ESCALATE_HOURS))) {
            return self::YOU;
        }

        return $cause;
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

    /**
     * The latest failed live check of an account ("Hesap okunuyor ama reklam yayınlayamaz: DISABLED", "PERMISSION_DENIED"),
     * or null when the account answered normally.
     */
    public static function liveProblem(int $resourceId): ?string
    {
        if ($resourceId <= 0) {
            return null;
        }
        if (self::$liveProblems !== null && array_key_exists($resourceId, self::$liveProblems)) {
            return self::$liveProblems[$resourceId];
        }
        if (! self::hasLiveChecks()) {
            return null;
        }
        $row = DB::table('live_checks')->where('subject_type', 'external_resource')->where('subject_id', $resourceId)->orderByDesc('id')->first(['status', 'message']);

        return $row !== null && $row->status === LiveVerifier::FAIL ? (string) $row->message : null;
    }

    /**
     * liveProblem() of every account the alerts name (a resource-automation alert's scope, each affected account),
     * from one query on the latest live check per account instead of one per account and alert.
     *
     * @param  Collection<int, OperationalAlert>  $alerts
     * @return array<int, ?string> resource id => problem (null: answered normally or never checked)
     */
    private static function liveProblems(Collection $alerts): array
    {
        $ids = [];
        foreach ($alerts as $alert) {
            if (str_starts_with((string) $alert->rule_key, 'resource-automation.') && (int) $alert->scope_key > 0) {
                $ids[(int) $alert->scope_key] = null;
            }
            foreach ((array) ((is_array($alert->observed) ? $alert->observed : [])['affected'] ?? []) as $row) {
                if (is_array($row) && ($resource = (int) ($row['resource_id'] ?? 0)) > 0) {
                    $ids[$resource] = null;
                }
            }
        }
        if ($ids === [] || ! self::hasLiveChecks()) {
            return $ids;
        }
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $latest = DB::table('live_checks')->selectRaw('max(id) as id')->where('subject_type', 'external_resource')
                ->whereIn('subject_id', $chunk)->groupBy('subject_id');
            foreach (DB::table('live_checks')->whereIn('id', $latest)->get(['subject_id', 'status', 'message']) as $row) {
                $ids[(int) $row->subject_id] = $row->status === LiveVerifier::FAIL ? (string) $row->message : null;
            }
        }

        return $ids;
    }

    private static function hasLiveChecks(): bool
    {
        return self::$hasLiveChecks = self::$hasLiveChecks || Schema::hasTable('live_checks');
    }

    /**
     * Several accounts in one alert: one that needs a person wins; otherwise the system's own retries come before a
     * software error (one account with a code error must not label fourteen transient ones "yazılım hatası"; it
     * escalates after ESCALATE_HOURS like the rest).
     *
     * @param  array<string, mixed>  $observed
     */
    private static function byCategories(array $observed): string
    {
        $buckets = [];
        foreach ((array) ($observed['affected'] ?? []) as $row) {
            if (is_array($row)) {
                $buckets[] = filled($row['error_category'] ?? null) ? self::byKind((string) $row['error_category']) : self::AUTO;
                if (($resource = (int) ($row['resource_id'] ?? 0)) > 0 && self::liveProblem($resource) !== null) {
                    $buckets[] = self::YOU;
                }
            }
        }
        if ($buckets === []) {
            return self::byKind(self::firstCategory($observed) ?? 'provider');
        }

        return in_array(self::YOU, $buckets, true) ? self::YOU : (in_array(self::AUTO, $buckets, true) ? self::AUTO : self::CODE);
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
