<?php

namespace App\Services\Analyst;

use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Analyst\Contracts\DownloadsDecision;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Read / act API of the brand workspace cards: the week's top list across channels, one channel's cards, the card
 * presentation (title, why, button, Kanıt table) and the operator actions (run the action, done, snooze, dismiss).
 */
final class AnalystWorkspace
{
    /** Evidence key => column label (Kanıt tables). Unknown keys are left out. */
    public const array EVIDENCE_COLUMNS = [
        'text' => 'Sorgu', 'label' => 'Konu', 'name' => 'Ad', 'title' => 'Başlık', 'rule' => 'Kural', 'path' => 'URL', 'owner' => 'Sayfa',
        'service' => 'Hizmet', 'area' => 'Bölge', 'verdict' => 'Karar', 'status' => 'Durum', 'display' => 'Değer', 'issue' => 'Sorun',
        'cost' => 'Harcama', 'conversions' => 'Dönüşüm', 'cpa' => 'CPA',
        'impressions' => 'Gösterim', 'clicks' => 'Tık', 'position' => 'Sıra', 'owner_position' => 'Sıra', 'key_events' => 'Dönüşüm',
        'urls' => 'URL', 'queries' => 'Sorgu', 'extra_clicks' => '+Tık', 'note' => 'Not',
    ];

    public const int EVIDENCE_MAX_COLUMNS = 6;

    public function __construct(
        private readonly AnalystRegistry $registry,
        private readonly AnalystDecisionStore $store,
        private readonly AnalystEngine $engine,
    ) {}

    /**
     * "Bu hafta yapılacaklar": the most urgent open cards across channels.
     *
     * @return list<array<string, mixed>>
     */
    public function top(Brand $brand, int $limit = 7): array
    {
        return Suggestion::query()->with('brand')->where('brand_id', $brand->id)->whereIn('channel', $this->registry->liveChannels() ?: [''])->actionable()
            ->orderBy('priority')->orderByDesc('last_seen_at')->orderBy('id')->limit($limit)->get()
            ->map(fn (Suggestion $d): array => $this->present($d))->all();
    }

    /** @return list<array<string, mixed>> */
    public function forChannel(Brand $brand, string $channel, int $limit = 20): array
    {
        return Suggestion::query()->with('brand')->where('brand_id', $brand->id)->where('channel', $channel)->actionable()
            ->orderBy('priority')->orderByDesc('last_seen_at')->orderBy('id')->limit($limit)->get()
            ->map(fn (Suggestion $d): array => $this->present($d))->all();
    }

    /**
     * Open-card count per channel (portfolio list).
     *
     * @return array<string, int>
     */
    public function counts(Brand $brand): array
    {
        return Suggestion::query()->where('brand_id', $brand->id)->actionable()->selectRaw('channel, count(*) as n')->groupBy('channel')
            ->pluck('n', 'channel')->map(fn ($n): int => (int) $n)->all();
    }

    /**
     * @return array{id: int, channel: string, channel_label: string, title: string, why: string, priority: int, effort: ?string, impact: ?string, action: array{label: string, kind: string, url: string|null}|null, evidence: array{columns: array<string, string>, rows: list<array<string, mixed>>}}
     */
    public function present(Suggestion $decision): array
    {
        $action = null;
        try {
            $action = $this->registry->has($decision->channel) ? $this->registry->get($decision->channel)->presentAction($decision) : null;
        } catch (Throwable $exception) {
            report($exception);
        }

        return [
            'id' => (int) $decision->id, 'channel' => $decision->channel, 'channel_label' => $decision->channelLabel(),
            'title' => (string) $decision->title, 'why' => (string) $decision->reason, 'priority' => (int) $decision->priority,
            'effort' => $decision->effort, 'impact' => filled($decision->impact['estimate'] ?? null) ? (string) $decision->impact['estimate'] : null,
            'action' => $action, 'evidence' => $this->evidenceTable((array) $decision->evidence),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{columns: array<string, string>, rows: list<array<string, mixed>>}
     */
    public function evidenceTable(array $rows): array
    {
        $present = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (isset(self::EVIDENCE_COLUMNS[$key]) && $value !== null && $value !== '' && ! is_array($value)) {
                    $present[$key] = true;
                }
            }
        }
        $columns = [];
        foreach (self::EVIDENCE_COLUMNS as $key => $label) {
            if (isset($present[$key]) && count($columns) < self::EVIDENCE_MAX_COLUMNS && ! in_array($label, $columns, true)) {
                $columns[$key] = $label;
            }
        }

        return ['columns' => $columns, 'rows' => array_map(fn (array $row): array => array_intersect_key($row, $columns), $rows)];
    }

    public function find(Brand $brand, int $decisionId): Suggestion
    {
        $decision = Suggestion::query()->where('brand_id', $brand->id)->find($decisionId);
        if ($decision === null) {
            throw ValidationException::withMessages(['analyst' => 'Kart artık yok; sayfayı yenileyin.']);
        }

        return $decision;
    }

    public function perform(Brand $brand, int $decisionId, User $user): string
    {
        $decision = $this->find($brand, $decisionId);
        if (! $this->registry->has($decision->channel)) {
            throw ValidationException::withMessages(['analyst' => 'Bu kanalın işlemleri henüz hazır değil.']);
        }

        return $this->registry->get($decision->channel)->perform($decision, $user);
    }

    /** The card's file (channels implementing DownloadsDecision), or null when its action is not a file. */
    public function download(Brand $brand, int $decisionId, User $user): ?StreamedResponse
    {
        $decision = $this->find($brand, $decisionId);
        if (! $this->registry->has($decision->channel)) {
            return null;
        }
        $analyst = $this->registry->get($decision->channel);

        return $analyst instanceof DownloadsDecision ? $analyst->download($decision, $user) : null;
    }

    public function done(Brand $brand, int $decisionId, ?User $user): void
    {
        $this->store->markDone($this->find($brand, $decisionId), $user);
    }

    public function snooze(Brand $brand, int $decisionId, int $days = 7): void
    {
        $this->store->snooze($this->find($brand, $decisionId), $days);
    }

    public function dismiss(Brand $brand, int $decisionId, ?User $user): void
    {
        $this->store->dismiss($this->find($brand, $decisionId), $user);
    }

    public function reanalyze(Brand $brand, string $channel, ?User $user): AnalystRun
    {
        return $this->engine->queue($brand, $channel, $user, 'manual');
    }

    /** @return array{status: string, at: string|null, error: string|null, active: bool}|null the last run line of a channel */
    public function lastRun(Brand $brand, string $channel): ?array
    {
        $run = $this->engine->latest((int) $brand->id, $channel);

        return $run === null ? null : [
            'status' => (string) $run->status, 'at' => ($run->finished_at ?? $run->created_at)?->timezone(config('app.timezone'))->format('d.m H:i'),
            'error' => $run->error, 'active' => $run->isActive(),
        ];
    }
}
