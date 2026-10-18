<?php

namespace App\Services\Epicrisis;

use App\Models\Brand;
use App\Models\BrandEpicrisis;
use App\Models\BrandEpicrisisItem;
use App\Models\User;
use App\Services\Compliance\ForbiddenTerms;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Marka epikrizi (yakup, 2026-10-16 "AI yazıp doğrudan kullansın, bu şekilde yap"): one record per brand that every AI
 * step reads instead of scanning everything again, in five layers: facts (with source, date, confidence and expiry),
 * evidence, synthesis, lessons and open questions to the operator.
 *
 *  - build(): the nightly rule pass (facts, evidence, weekly lessons, confidence / conflicts / expiry, questions,
 *    summary). The AI passes (site facts, synthesis) run from RunBrandEpicrisisJob.
 *  - forPrompt(): the slice one AI work reads (content, ads, gbp, reply, meta): the brand's positioning, its usable
 *    facts (expired and conflicting ones never), the synthesis lines, evidence, lessons and the fields nobody knows
 *    (so the AI does not invent them). Deterministic, so an unchanged record gives the same AI input.
 *  - record() / answer() / dismiss(): what the operator says, from the Epikriz tab, the questions box or the chat (MCP).
 */
final class Epicrisis
{
    public const array SLICES = ['content', 'ads', 'gbp', 'reply', 'meta'];

    public const int LESSON_DAYS = 7;

    private const int MAX_FACTS = 28;

    private const int MAX_INSIGHTS = 12;

    private const int MAX_EVIDENCE = 8;

    private const int MAX_LESSONS = 6;

    public function __construct(
        private readonly EpicrisisStore $store,
        private readonly EpicrisisFacts $facts,
        private readonly EpicrisisEvidence $evidence,
        private readonly EpicrisisLessons $lessons,
    ) {}

    /** The rule pass: no AI. @return array{fill: int, counts: array<string, int>} */
    public function build(Brand $brand): array
    {
        $head = BrandEpicrisis::for($brand);
        foreach ([fn () => $this->facts->collect($brand), fn () => $this->evidence->collect($brand)] as $step) {
            try {
                $step();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        if ($head->lessons_at === null || $head->lessons_at->lt(now()->subDays(self::LESSON_DAYS))) {
            try {
                $this->lessons->collect($brand);
                $head->forceFill(['lessons_at' => now()])->save();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $this->store->reconcile($brand);
        $this->questions($brand);

        return $this->summarize($brand);
    }

    /** Fields the brand's sector requires. @return list<string> */
    public static function required(Brand $brand): array
    {
        $sectors = (array) config('moxdop-epicrisis.sectors');
        $fields = [];
        foreach ($brand->sectorCodes() as $code) {
            array_push($fields, ...(array) ($sectors[$code] ?? []));
        }

        return array_values(array_unique($fields !== [] ? $fields : (array) $sectors['default']));
    }

    /**
     * Open questions: a required field nobody knows, two sources disagreeing on a one-value field, an operator line past
     * its date. A dismissed question is never asked again; an answered one stays answered.
     */
    public function questions(Brand $brand): void
    {
        $facts = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->where('layer', BrandEpicrisisItem::FACT)
            ->whereIn('status', [BrandEpicrisisItem::ACTIVE, BrandEpicrisisItem::CONFLICT, BrandEpicrisisItem::EXPIRED])->orderBy('id')->get();
        $rows = [];
        foreach (self::required($brand) as $field) {
            $ask = config('moxdop-epicrisis.fields.'.$field.'.ask');
            if ($ask !== null && $facts->where('field', $field)->whereIn('status', [BrandEpicrisisItem::ACTIVE, BrandEpicrisisItem::CONFLICT])->isEmpty()) {
                $rows[] = ['field' => $field, 'value' => (string) $ask, 'source_kind' => 'rules', 'detail' => ['kind' => 'missing']];
            }
        }
        $facts->where('status', BrandEpicrisisItem::CONFLICT)->groupBy(fn (BrandEpicrisisItem $i): string => $i->field.'|'.(string) $i->scope)
            ->each(function (Collection $group) use (&$rows): void {
                $first = $group->first();
                $options = $group->map(fn (BrandEpicrisisItem $i): array => ['id' => (int) $i->id, 'value' => (string) $i->value, 'source' => $i->sourceLabel(), 'ref' => (string) $i->source_ref])
                    ->sortBy('value')->values()->all();
                $rows[] = ['field' => $first->field, 'scope' => $first->scope, 'value' => $first->fieldLabel().($first->scope ? ' ('.$first->scope.')' : '').' için kaynaklar farklı: '
                    .implode(' / ', array_map(fn (array $o): string => $o['value'].' — '.$o['source'], $options)).'. Doğrusu hangisi?', 'source_kind' => 'rules',
                    'detail' => ['kind' => 'conflict', 'options' => $options]];
            });
        $facts->where('status', BrandEpicrisisItem::EXPIRED)->filter(fn (BrandEpicrisisItem $i): bool => in_array($i->source_kind, EpicrisisStore::OPERATOR_SOURCES, true))
            ->each(function (BrandEpicrisisItem $i) use (&$rows): void {
                $rows[] = ['field' => $i->field, 'scope' => $i->scope, 'value' => $i->line().' hâlâ geçerli mi?', 'source_kind' => 'rules',
                    'detail' => ['kind' => 'expired', 'options' => [['id' => (int) $i->id, 'value' => (string) $i->value, 'source' => $i->sourceLabel(), 'ref' => (string) $i->source_ref]]]];
            });
        $this->store->sync($brand, BrandEpicrisisItem::QUESTION, $rows, 'questions');
    }

    /** The one-page summary, fill and counts, stored on the brand's head row. @return array{fill: int, counts: array<string, int>} */
    public function summarize(Brand $brand): array
    {
        $items = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->get(['id', 'layer', 'field', 'status', 'confidence', 'source_kind']);
        $live = $items->where('status', BrandEpicrisisItem::ACTIVE);
        $required = self::required($brand);
        $known = $live->where('layer', BrandEpicrisisItem::FACT)->pluck('field')->unique()->intersect($required)->count();
        $fill = $required === [] ? 0 : (int) round(100 * $known / count($required));
        $counts = [
            'facts' => $live->where('layer', BrandEpicrisisItem::FACT)->count(),
            'verified' => $live->where('layer', BrandEpicrisisItem::FACT)->where('confidence', BrandEpicrisisItem::VERIFIED)->count(),
            'evidence' => $live->where('layer', BrandEpicrisisItem::EVIDENCE)->count(),
            'synthesis' => $live->where('layer', BrandEpicrisisItem::SYNTHESIS)->count(),
            'lessons' => $live->where('layer', BrandEpicrisisItem::LESSON)->count(),
            'questions' => $items->where('layer', BrandEpicrisisItem::QUESTION)->where('status', BrandEpicrisisItem::OPEN)->count(),
            'conflicts' => $items->where('layer', BrandEpicrisisItem::FACT)->where('status', BrandEpicrisisItem::CONFLICT)->count(),
            'expired' => $items->where('layer', BrandEpicrisisItem::FACT)->where('status', BrandEpicrisisItem::EXPIRED)->count(),
            'required' => count($required), 'known' => $known,
        ];
        $summary = $this->markdown($brand);
        BrandEpicrisis::for($brand)->forceFill(['summary' => $summary, 'summary_hash' => hash('sha256', $summary), 'fill_percent' => $fill, 'counts' => $counts, 'built_at' => now()])->save();

        return ['fill' => $fill, 'counts' => $counts];
    }

    /** The record as Markdown (Epikriz tab, MCP get-brand). */
    public function markdown(Brand $brand): string
    {
        $pack = $this->forPrompt($brand, null, false);
        $out = ['# '.$brand->name.' — epikriz', ''];
        if ($pack['positioning'] !== null) {
            $out[] = $pack['positioning'];
            $out[] = '';
        }
        foreach (['facts' => 'Bilgiler', 'insights' => 'Sentez', 'evidence' => 'Kanıt', 'lessons' => 'Dersler'] as $key => $title) {
            if ($pack[$key] !== []) {
                $out[] = '## '.$title;
                foreach ($pack[$key] as $line) {
                    $out[] = '- '.$line;
                }
                $out[] = '';
            }
        }
        if ($pack['unknown'] !== []) {
            $out[] = '## Bilinmiyor';
            $out[] = '- '.implode(', ', $pack['unknown']);
        }

        return trim(implode("\n", $out));
    }

    /**
     * The slice of the record one AI work reads. Null brand or an empty record → null (the AI input stays as it was).
     *
     * @param  ?string  $slice  content | ads | gbp | reply | meta; null = everything
     * @return array{brand: string, positioning: ?string, facts: list<string>, insights: list<string>, evidence: list<string>, lessons: list<string>, unknown: list<string>}|null
     */
    public function forPrompt(?Brand $brand, ?string $slice = null, bool $track = true): ?array
    {
        if ($brand === null) {
            return null;
        }
        $items = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->where('status', BrandEpicrisisItem::ACTIVE)
            ->whereIn('layer', [BrandEpicrisisItem::FACT, BrandEpicrisisItem::EVIDENCE, BrandEpicrisisItem::SYNTHESIS, BrandEpicrisisItem::LESSON])->orderBy('id')->get();
        if ($items->isEmpty()) {
            return null;
        }
        // The writers copy what they read: a line in the business's own words that holds a forbidden phrase of its sector
        // ("garanti" in health) cannot be written anyway, so it is left out rather than cut into another meaning
        // ("söyleme" lines and lessons name what not to say, so they keep theirs).
        $terms = ForbiddenTerms::forBrand($brand);
        $usable = fn (BrandEpicrisisItem $i): bool => $i->field === 'dont_say' || $terms->blocking($i->line().' '.data_get($i->detail, 'answer')) === [];
        $in = fn (string $group, string $key): bool => $slice === null || in_array($slice, (array) config('moxdop-epicrisis.'.$group.'.'.$key.'.slices', self::SLICES), true);
        $order = array_flip(array_keys((array) config('moxdop-epicrisis.fields')));
        $facts = $items->where('layer', BrandEpicrisisItem::FACT)->filter(fn (BrandEpicrisisItem $i): bool => $in('fields', $i->field) && $usable($i))
            ->sortBy(fn (BrandEpicrisisItem $i): array => [$i->confidence === BrandEpicrisisItem::VERIFIED ? 0 : 1, $order[$i->field] ?? 99, data_get($i->detail, 'main') ? 0 : 1, $i->id])
            ->take(self::MAX_FACTS)->values();
        $synthesis = $items->where('layer', BrandEpicrisisItem::SYNTHESIS);
        $positioning = $synthesis->where('field', 'positioning')->first($usable);
        $kinds = array_flip(array_keys((array) config('moxdop-epicrisis.synthesis')));
        $insights = $synthesis->filter(fn (BrandEpicrisisItem $i): bool => $i->field !== 'positioning' && $in('synthesis', $i->field) && $usable($i))
            ->sortBy(fn (BrandEpicrisisItem $i): array => [$kinds[$i->field] ?? 99, $i->locked ? 0 : 1, $i->id])->take(self::MAX_INSIGHTS)->values();
        $evidence = $items->where('layer', BrandEpicrisisItem::EVIDENCE)->filter(fn (BrandEpicrisisItem $i): bool => $in('evidence', $i->field) && $usable($i))
            ->sortBy(fn (BrandEpicrisisItem $i): array => [(int) data_get($i->detail, 'rank', 9), $i->field, $i->id])->take(self::MAX_EVIDENCE)->values();
        $lessons = $items->where('layer', BrandEpicrisisItem::LESSON)->filter(fn (BrandEpicrisisItem $i): bool => $in('lessons', $i->field))
            ->sortBy(fn (BrandEpicrisisItem $i): array => [$i->field, $i->id])->take(self::MAX_LESSONS)->values();
        $known = $items->where('layer', BrandEpicrisisItem::FACT)->pluck('field')->unique()->all();
        $unknown = collect(self::required($brand))->reject(fn (string $f): bool => in_array($f, $known, true))->filter(fn (string $f): bool => $in('fields', $f))
            ->map(fn (string $f): string => (string) config('moxdop-epicrisis.fields.'.$f.'.label'))->values()->all();

        if ($track) {
            $this->track([...$facts->pluck('id'), ...$insights->pluck('id'), ...$evidence->pluck('id'), ...$lessons->pluck('id'), ...($positioning ? [$positioning->id] : [])]);
        }

        return [
            'brand' => (string) $brand->name,
            'positioning' => $positioning?->value,
            'facts' => $facts->map(fn (BrandEpicrisisItem $i): string => $i->line().' ('.mb_strtolower(BrandEpicrisisItem::CONFIDENCE_LABELS[$i->confidence] ?? $i->confidence).')')->all(),
            'insights' => $insights->map(fn (BrandEpicrisisItem $i): string => $i->line().(data_get($i->detail, 'answer') ? ' → Cevap: '.data_get($i->detail, 'answer') : ''))->all(),
            'evidence' => $evidence->map(fn (BrandEpicrisisItem $i): string => $i->line())->all(),
            'lessons' => $lessons->map(fn (BrandEpicrisisItem $i): string => $i->line())->all(),
            'unknown' => $unknown,
        ];
    }

    /** forPrompt() for an AI input: [] when the brand has no record, so `+` leaves the input as it was. @return array<string, mixed> */
    public static function input(?Brand $brand, string $slice): array
    {
        $pack = $brand !== null ? app(self::class)->forPrompt($brand, $slice) : null;

        return $pack !== null ? ['brand_epicrisis' => $pack] : [];
    }

    /** Usage count, at most once per half hour per line (a resumable job rebuilding its input is not a new use). @param list<int> $ids */
    private function track(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        try {
            BrandEpicrisisItem::query()->whereIn('id', $ids)->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinutes(30)))
                ->update(['used_count' => DB::raw('used_count + 1'), 'last_used_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The operator (or the chat) states a fact or a synthesis line: verified, locked, it wins its field.
     *
     * @param  string  $source  operator | chat
     */
    public function record(Brand $brand, string $field, string $value, ?string $scope = null, string $source = 'operator', ?int $userId = null, ?BrandEpicrisisItem $replaces = null): BrandEpicrisisItem
    {
        $layer = match (true) {
            array_key_exists($field, (array) config('moxdop-epicrisis.fields')) => BrandEpicrisisItem::FACT,
            array_key_exists($field, (array) config('moxdop-epicrisis.synthesis')) => BrandEpicrisisItem::SYNTHESIS,
            default => throw new InvalidArgumentException('Bilinmeyen alan: '.$field),
        };
        if (trim($value) === '') {
            throw new InvalidArgumentException('Boş değer.');
        }
        $item = $this->store->put($brand, $replaces?->layer ?? $layer, $field, $value, $scope, $source, $userId, $replaces);
        $this->store->reconcile($brand);
        $this->questions($brand);
        $this->summarize($brand);

        return $item;
    }

    /** Answers an open question: the answer becomes the operator's fact (a conflict's other values are set aside). */
    public function answer(BrandEpicrisisItem $question, string $value, ?User $by = null, string $source = 'operator'): BrandEpicrisisItem
    {
        if ($question->layer !== BrandEpicrisisItem::QUESTION) {
            throw new InvalidArgumentException('Soru değil.');
        }
        $brand = Brand::query()->findOrFail($question->brand_id);
        $fact = $this->store->put($brand, BrandEpicrisisItem::FACT, (string) $question->field, $value, $question->scope, $source, $by?->id);
        foreach ((array) data_get($question->detail, 'options', []) as $option) {
            $other = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->find((int) ($option['id'] ?? 0));
            if ($other !== null && $other->id !== $fact->id && EpicrisisStore::norm((string) $other->field, (string) $other->value) !== EpicrisisStore::norm((string) $fact->field, (string) $fact->value)) {
                $this->store->setAside($other, (int) $fact->id);
            }
        }
        $question->forceFill(['status' => BrandEpicrisisItem::ANSWERED, 'answered_by' => $by?->id, 'detail' => array_merge((array) $question->detail, ['answer' => mb_substr(trim($value), 0, 400), 'answered_at' => now()->toIso8601String()])])->save();
        $this->store->reconcile($brand);
        $this->questions($brand);
        $this->summarize($brand);

        return $fact;
    }

    /** "Bilmiyorum / gerek yok": never asked again. */
    public function dismiss(BrandEpicrisisItem $question, ?User $by = null): void
    {
        $question->forceFill(['status' => BrandEpicrisisItem::DISMISSED, 'answered_by' => $by?->id])->save();
        $brand = Brand::query()->find($question->brand_id);
        if ($brand !== null) {
            $this->summarize($brand);
        }
    }

    /** The operator says a line is wrong: no AI work uses it again, the rules do not bring it back. */
    public function reject(BrandEpicrisisItem $item): void
    {
        $this->store->setAside($item);
        $brand = Brand::query()->find($item->brand_id);
        if ($brand !== null) {
            $this->store->reconcile($brand);
            $this->questions($brand);
            $this->summarize($brand);
        }
    }
}
