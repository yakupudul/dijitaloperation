<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\ClaudeNote;
use App\Models\Reminder;
use App\Services\Finance\MissingAgreements;
use App\Support\AssetIntent;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Bugün (arayüz kararı 2026-11-17): every served brand in one list, the one that needs the operator most on top.
 * Urgency comes from two sides and both are shown as their own number:
 *  - what the system found: a channel losing money or data (bad), a channel to look at (look), an expected asset missing,
 *  - a served brand with no running agreement in Finans (MissingAgreements): money nobody follows,
 *  - what the operator wrote himself: his open to-dos of that brand, late ones first.
 * One sentence per brand says why it is where it is. A known intent on an asset (AssetIntent) is never a gap.
 */
final class BrandToday
{
    public const int AUDIT_LIMIT = 3;

    private const array CHANNEL_NAMES = ['web' => 'Site', 'gbp' => 'İşletme profili', 'google_ads' => 'Google Ads', 'meta' => 'Meta'];

    public function __construct(private readonly PortfolioReader $portfolio, private readonly BrandShortcuts $shortcuts) {}

    /**
     * @return array{rows: list<array{id: int, name: string, logo: ?string, gaps: int, todos: int, late: int, line: string, tone: string, score: int, next: ?string, doors: list<array<string, mixed>>}>, loose: int, calm: int}
     */
    public function rows(): array
    {
        $brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'logo_url', 'customer_id']);
        $ids = $brands->modelKeys();
        $system = $this->safely(fn (): array => Cache::remember('brand-today.system', 300, fn (): array => $this->system($ids))) ?? [];
        $todos = $this->safely(fn () => BrandWorkplace::openReminders()->get()) ?? collect();
        $byBrand = $todos->whereNotNull('brand_id')->groupBy('brand_id');
        $start = now()->timezone('Europe/Istanbul')->startOfDay();
        $noDeal = $this->safely(fn (): array => app(MissingAgreements::class)->ids()) ?? [];

        $rows = [];
        foreach ($brands as $brand) {
            $gaps = $system[(int) $brand->id] ?? ['bad' => [], 'look' => [], 'missing' => []];
            $own = BrandWorkplace::sortReminders($byBrand->get($brand->id, collect()));
            $late = $own->filter(fn (Reminder $r): bool => $r->remind_at !== null && $r->remind_at->timezone('Europe/Istanbul')->lessThan($start))->count();
            $unpaid = isset($noDeal[(int) $brand->id]);
            $gapCount = count($gaps['bad']) + count($gaps['look']) + count($gaps['missing']) + ($unpaid ? 1 : 0);
            $parts = array_values(array_filter([
                $unpaid ? 'Finans\'ta anlaşması yok, ödemesi takip edilmiyor' : null,
                $gaps['bad'] !== [] ? implode(', ', $gaps['bad']).' kayıpta' : null,
                $gaps['look'] !== [] ? implode(', ', $gaps['look']).' bakılmalı' : null,
                $gaps['missing'] !== [] ? implode(', ', $gaps['missing']).' bağlı değil' : null,
                $late > 0 ? $late.' işin gecikti' : null,
            ]));
            $rows[] = [
                'id' => (int) $brand->id,
                'name' => (string) $brand->name,
                'logo' => $brand->logo_url ?: null,
                'gaps' => $gapCount,
                'todos' => $own->count(),
                'late' => $late,
                'line' => $parts !== [] ? implode('. ', $parts).'.' : ($own->isNotEmpty() ? 'Sistem tarafı yolunda, sırada senin işin var.' : 'Yolunda, senden bir şey beklemiyor.'),
                'tone' => $gaps['bad'] !== [] || $late > 0 || $unpaid ? 'bad' : ($gapCount > 0 || $own->isNotEmpty() ? 'warn' : 'ok'),
                'score' => 100 * $late + 40 * count($gaps['bad']) + 12 * count($gaps['look']) + 10 * count($gaps['missing']) + 8 * $own->count() + ($unpaid ? 60 : 0),
                'next' => $own->first()?->title,
                // The doors change only when an account is bound: kept ten minutes so the list costs no query per brand.
                'doors' => $this->safely(fn (): array => Cache::remember('brand-today.doors.'.$brand->id, 600, fn (): array => $this->shortcuts->doors($brand))) ?? [],
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

        return ['rows' => $rows, 'loose' => $todos->whereNull('brand_id')->count(), 'calm' => count(array_filter($rows, fn (array $r): bool => $r['score'] === 0))];
    }

    /**
     * Denetçi: the open remarks (newest first, at most AUDIT_LIMIT).
     *
     * @return list<array{id: int, brand_id: ?int, brand: ?string, text: string, refs: list<string>, at: string}>
     */
    public function audits(): array
    {
        return $this->safely(fn (): array => ClaudeNote::query()->with('brand:id,name')->where('kind', ClaudeNote::AUDIT)->where('status', ClaudeNote::OPEN)
            ->latest('id')->limit(self::AUDIT_LIMIT)->get()->map(fn (ClaudeNote $note): array => [
                'id' => (int) $note->id,
                'brand_id' => $note->brand_id !== null ? (int) $note->brand_id : null,
                'brand' => $note->brand?->name,
                'text' => (string) $note->text,
                'refs' => array_values(array_filter((array) ($note->refs ?? []), fn ($ref): bool => is_string($ref) && str_starts_with($ref, 'http'))),
                'at' => (string) $note->created_at?->timezone('Europe/Istanbul')->format('d.m'),
            ])->all()) ?? [];
    }

    /**
     * @param  list<int>  $brandIds
     * @return array<int, array{bad: list<string>, look: list<string>, missing: list<string>}>
     */
    private function system(array $brandIds): array
    {
        $out = [];
        foreach ($this->portfolio->health($brandIds) as $brandId => $channels) {
            $row = ['bad' => [], 'look' => [], 'missing' => []];
            foreach ($channels as $channel => $state) {
                $name = self::CHANNEL_NAMES[$channel] ?? $channel;
                if ($state === 'bad' || $state === 'look') {
                    $row[$state][] = $name;
                } elseif ($state === 'none' && in_array($channel, ['web', 'gbp'], true)) {
                    $row['missing'][] = $name;
                }
            }
            $out[(int) $brandId] = $row;
        }

        return $out;
    }

    public static function forget(): void
    {
        Cache::forget('brand-today.system');
        AssetIntent::forget();
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
}
