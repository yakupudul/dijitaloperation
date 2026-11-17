<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Reminder;
use App\Services\DataStatus\DataStatus;
use App\Services\Repair\RepairDesk;
use App\Services\Work\FlowFeed;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Marka sayfası, tek çalışma yeri (arayüz kararı 2026-11-17). Under the outcome numbers the page shows, in the order of
 * the spine Müşteri → Marka → Dijital varlıklar → ölçen araçlar:
 *  - one row per digital asset with its measuring tools, one sentence of state and its own doors,
 *  - what is not connected, by name, and who closes it,
 *  - what Claude did lately (with where to undo),
 *  - what is left to the operator (his own to-dos first, at most three).
 * Every part is failure-safe on its own: a broken reader empties its part, never the page.
 */
final class BrandWorkplace
{
    public const int DONE_LIMIT = 6;

    public const int DONE_DAYS = 14;

    public const int YOURS_LIMIT = 3;

    /** Asset types the brand page expects every served brand to have. */
    private const array EXPECTED = ['website' => 'Web sitesi', 'google_business_profile' => 'İşletme profili'];

    public function __construct(private readonly BrandOverviewReader $overview, private readonly ExternalLinks $links) {}

    /**
     * @return list<array{id: int, name: string, type: string, type_label: string, url: string, sources_url: string, tone: string, line: string, intent: ?string, intent_label: ?string, intent_note: ?string,
     *     tools: list<array{label: string, tone: string, state: string, last: ?string}>, doors: list<array<string, mixed>>}>
     */
    public function assets(Brand $brand): array
    {
        try {
            $models = $this->overview->assetModels($brand)->load('assetBindings.externalResource');
            $cards = collect($this->overview->assetCards($models))->keyBy('id');
            $this->safely(fn () => $this->links->preload($models));
        } catch (Throwable $error) {
            report($error);

            return [];
        }
        $order = ['website' => 0, 'google_business_profile' => 1, 'google_ads' => 2, 'meta_ads' => 3];
        $rows = [];
        foreach ($models->sortBy(fn (DigitalAsset $a): int => $order[(string) $a->type] ?? 9)->values() as $asset) {
            $card = $cards[(int) $asset->id] ?? null;
            if ($card === null) {
                continue;
            }
            $intent = $asset->intent ?: null;
            $rows[] = [
                'id' => (int) $asset->id,
                'name' => $card['name'],
                'type' => $card['type'],
                'type_label' => $card['type_label'],
                'url' => $card['url'],
                'sources_url' => $card['sources_url'],
                // Known intent is information, never an alarm.
                'tone' => $intent !== null ? 'muted' : $card['tone'],
                'line' => $intent !== null ? trim(DigitalAsset::INTENTS[$intent].($asset->intent_note ? ': '.$asset->intent_note : '')) : $card['summary'],
                'intent' => $intent,
                'intent_label' => $intent !== null ? DigitalAsset::INTENTS[$intent] : null,
                'intent_note' => $intent !== null ? (string) $asset->intent_note : null,
                'tools' => array_map(fn (array $s): array => ['label' => (string) $s['label'], 'tone' => (string) $s['tone'], 'state' => (string) $s['state_label'], 'last' => $s['last_data_date']], $card['sources']),
                'doors' => $this->safely(fn (): array => $this->links->forAsset($asset)) ?? [],
            ];
        }

        return $rows;
    }

    /**
     * What is not connected, by name. `who`: claude (Claude sets it up, nothing for the operator) or you (an access or a
     * click only he can give, with the door). Assets with a known intent are left out.
     *
     * @param  list<array<string, mixed>>  $assets  output of assets()
     * @param  array<string, mixed>|null  $outcome  BrandOutcome::for()
     * @return list<array{text: string, who: string, url: ?string, action: ?string}>
     */
    public function missing(Brand $brand, array $assets, ?array $outcome = null): array
    {
        $out = [];
        $types = array_column($assets, 'type');
        foreach (self::EXPECTED as $type => $label) {
            if (! in_array($type, $types, true)) {
                $out[] = ['text' => $label.' bağlı değil', 'who' => 'you', 'url' => route('operator.brand', ['brand' => $brand->id, 'tab' => 'varliklar']), 'action' => 'Bağla'];
            }
        }
        foreach ($assets as $asset) {
            if ($asset['intent'] !== null) {
                continue;
            }
            foreach ($asset['tools'] as $tool) {
                $state = (string) $tool['state'];
                if ($tool['tone'] === 'bad' || $tool['tone'] === 'warn' || Str::contains(Str::lower($state), ['bağlı değil', 'bağlanmadı'])) {
                    // "Web sitesi › Search Console: Bağlı değil"; a tool named like its asset is said once, and the asset's
                    // own name is added when the brand has several of that type.
                    $subject = mb_strtolower($tool['label']) === mb_strtolower($asset['type_label']) ? $asset['type_label'] : $asset['type_label'].' › '.$tool['label'];
                    if (count(array_keys($types, $asset['type'], true)) > 1) {
                        $subject .= ' ('.$asset['name'].')';
                    }
                    $out[] = ['text' => $subject.': '.$state, 'who' => 'you', 'url' => $asset['sources_url'], 'action' => 'Aç'];
                }
            }
        }
        // A contact kind nobody measures: Claude's own job (measurement setup), never the operator's.
        if ($outcome !== null && ($outcome['has_sources'] ?? false)) {
            foreach ($outcome['kinds'] as $kind) {
                if (! $kind['measured'] && $kind['key'] !== 'other') {
                    $out[] = ['text' => $kind['label'].' ölçülmüyor', 'who' => 'claude', 'url' => null, 'action' => null];
                }
            }
        } elseif ($outcome !== null && $assets !== []) {
            $out[] = ['text' => 'Müşteri teması hiç ölçülmüyor (arama, WhatsApp, form)', 'who' => 'claude', 'url' => null, 'action' => null];
        }

        return $out;
    }

    /**
     * What was written for this brand lately (site, profile, ads tracking), newest first.
     *
     * @return array{items: list<array{id: int, at: string, label: string, subject: string, undoable: bool}>, more: int, flow_url: string}
     */
    public function done(Brand $brand): array
    {
        $flow = route('operator.flow');
        try {
            $query = ExternalWriteAction::query()->where('brand_id', $brand->id)->whereIn('status', ['succeeded', 'partial'])
                ->where('updated_at', '>=', now()->subDays(self::DONE_DAYS));
            $total = (clone $query)->count();
            $items = $query->orderByDesc('updated_at')->limit(self::DONE_LIMIT)->get()->map(fn (ExternalWriteAction $write): array => [
                'id' => (int) $write->id,
                'at' => (string) $write->updated_at?->timezone('Europe/Istanbul')->format('d.m H:i'),
                'label' => RepairDesk::WRITE_LABELS[$write->action] ?? 'İş',
                'subject' => FlowFeed::writeSubject($write),
                'undoable' => $write->isUndoable(),
            ])->all();

            return ['items' => $items, 'more' => max(0, $total - count($items)), 'flow_url' => $flow];
        } catch (Throwable $error) {
            report($error);

            return ['items' => [], 'more' => 0, 'flow_url' => $flow];
        }
    }

    /**
     * Sana kalan: the operator's own open to-dos of this brand (late first, then dated, then undated) and Claude's
     * questions waiting for his yes. At most YOURS_LIMIT rows; the rest is one link to Takvim.
     *
     * @return array{items: list<array{id: int, title: string, when: ?string, late: bool, ask: bool, url: ?string}>, more: int, calendar_url: string}
     */
    public function yours(Brand $brand): array
    {
        $calendar = route('operator.calendar');
        try {
            $rows = self::openReminders()->where('brand_id', $brand->id)->get();
            $sorted = self::sortReminders($rows);

            return ['items' => $sorted->take(self::YOURS_LIMIT)->map(fn (Reminder $r): array => self::reminderRow($r))->values()->all(),
                'more' => max(0, $sorted->count() - self::YOURS_LIMIT), 'calendar_url' => $calendar];
        } catch (Throwable $error) {
            report($error);

            return ['items' => [], 'more' => 0, 'calendar_url' => $calendar];
        }
    }

    /** Open jobs of the operator: tasks not ticked, and questions (asks) not answered. */
    public static function openReminders(): \Illuminate\Database\Eloquent\Builder
    {
        return Reminder::query()->where('kind', 'task')->whereNull('done_at')->where('repeat', 'none');
    }

    /**
     * @param  Collection<int, Reminder>  $rows
     * @return Collection<int, Reminder>
     */
    public static function sortReminders(Collection $rows): Collection
    {
        $today = now()->timezone('Europe/Istanbul')->endOfDay();

        return $rows->sortBy(fn (Reminder $r): array => [
            $r->remind_at !== null && $r->remind_at->lessThanOrEqualTo($today) ? 0 : ($r->remind_at !== null ? 1 : 2),
            $r->remind_at?->getTimestamp() ?? PHP_INT_MAX,
            -$r->id,
        ])->values();
    }

    /** @return array{id: int, title: string, when: ?string, late: bool, ask: bool, url: ?string} */
    public static function reminderRow(Reminder $r): array
    {
        $start = now()->timezone('Europe/Istanbul')->startOfDay();

        return [
            'id' => (int) $r->id,
            'title' => (string) $r->title,
            'when' => $r->remind_at?->timezone('Europe/Istanbul')->format('d.m'),
            'late' => $r->remind_at !== null && $r->remind_at->timezone('Europe/Istanbul')->lessThan($start),
            'ask' => (string) ($r->approval ?? '') === Reminder::ASKED,
            'url' => filled($r->url) ? (string) $r->url : null,
        ];
    }

    private function safely(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }

    public static function isAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole(Roles::ADMIN);
    }
}
