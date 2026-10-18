<?php

namespace App\Services\Epicrisis;

use App\Ai\Agents\Site\EpicrisisSynthesisAgent;
use App\Models\Brand;
use App\Models\BrandEpicrisis;
use App\Models\BrandEpicrisisItem;
use App\Models\BrandOffering;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteScope;
use Illuminate\Support\Collection;

/**
 * Epikriz katman 3 — sentez (yakup, 2026-10-16 "AI yazıp doğrudan kullansın"): positioning, audiences, objections and
 * their answers, proof, voice, say / don't say and the main message per channel, written by AI from the brand's live
 * facts, evidence and lessons and used by every AI step without a person reading it first. So rules hold it:
 * every line must cite at least one record line it rests on, every number in it must be in a cited line, and no
 * forbidden phrase may stand in it. Written again monthly, or sooner when a quarter of its basis changed; a line the
 * operator edited (locked) is kept.
 */
final class EpicrisisSynthesis
{
    public function __construct(private readonly EpicrisisStore $store, private readonly SiteAi $ai) {}

    /** The record lines a synthesis is written from. @return Collection<int, BrandEpicrisisItem> */
    public function basis(Brand $brand): Collection
    {
        return BrandEpicrisisItem::query()->where('brand_id', $brand->id)->whereIn('layer', [BrandEpicrisisItem::FACT, BrandEpicrisisItem::EVIDENCE, BrandEpicrisisItem::LESSON])
            ->where('status', BrandEpicrisisItem::ACTIVE)->orderBy('layer')->orderBy('field')->orderBy('id')->limit(160)->get();
    }

    public function due(Brand $brand): bool
    {
        $head = BrandEpicrisis::for($brand);
        $keys = $this->basis($brand)->pluck('fingerprint')->all();
        if ($keys === []) {
            return false;
        }
        if ($head->synthesized_at === null || $head->synthesized_at->lt(now()->subDays((int) config('moxdop-epicrisis.synthesis_days', 30)))) {
            return true;
        }
        $before = (array) ($head->synthesis_keys ?? []);
        $changed = count(array_diff($keys, $before)) + count(array_diff($before, $keys));

        return $changed / max(1, count($before)) >= (float) config('moxdop-epicrisis.synthesis_change', 0.25);
    }

    /**
     * Writes the synthesis when due (or forced). Runs inside a resumable job: "queued" while Claude has not answered.
     *
     * @return array{status: string, items?: int, rejected?: int}
     */
    public function run(Brand $brand, bool $force = false): array
    {
        if (! $force && ! $this->due($brand)) {
            return ['status' => 'unchanged'];
        }
        $basis = $this->basis($brand);
        if ($basis->isEmpty()) {
            return ['status' => 'no_basis'];
        }
        $keys = $basis->pluck('fingerprint')->all();
        $hash = hash('sha256', implode('|', $keys));
        $forbidden = ForbiddenTerms::forBrand($brand);
        $line = fn (BrandEpicrisisItem $i): array => ['id' => (int) $i->id, 'line' => $i->line()];
        $result = $this->ai->run(new EpicrisisSynthesisAgent, [
            'brand' => (string) $brand->name,
            'sector' => $brand->sectorLabel(),
            'services' => SiteScope::offerings($brand)->map(fn (BrandOffering $o): string => $o->displayName())->filter()->values()->all(),
            'facts' => $basis->where('layer', BrandEpicrisisItem::FACT)->map(fn (BrandEpicrisisItem $i): array => $line($i) + ['confidence' => $i->confidence])->values()->all(),
            'evidence' => $basis->where('layer', BrandEpicrisisItem::EVIDENCE)->map($line)->values()->all(),
            'lessons' => $basis->where('layer', BrandEpicrisisItem::LESSON)->map($line)->values()->all(),
            'kinds' => collect((array) config('moxdop-epicrisis.synthesis'))->map(fn (array $k, string $key): array => ['kind' => $key, 'label' => $k['label']])->values()->all(),
            'forbidden' => $forbidden->phrases(),
        ], 600, 'epicrisis-synthesis-'.$brand->id.'-'.substr($hash, 0, 12));
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status']];
        }
        $rows = [];
        $rejected = 0;
        foreach ((array) ($result['data']['items'] ?? []) as $item) {
            $row = $this->grounded($item, $basis->keyBy('id'), $forbidden);
            $row === null ? $rejected++ : $rows[] = $row;
        }
        // An empty answer is not a reason to throw away the last good synthesis.
        if ($rows !== []) {
            $this->store->sync($brand, BrandEpicrisisItem::SYNTHESIS, $rows, 'synthesis');
        }
        BrandEpicrisis::for($brand)->forceFill(['synthesis_basis' => $hash, 'synthesis_keys' => $keys, 'synthesized_at' => now()])->save();

        return ['status' => 'ready', 'items' => count($rows), 'rejected' => $rejected];
    }

    /**
     * A line kept only when it cites live record lines, every number in it stands in them and it holds no forbidden
     * phrase (a "söyleme" line may name one).
     *
     * @param  Collection<int, BrandEpicrisisItem>  $basis
     * @return array<string, mixed>|null
     */
    public function grounded(mixed $item, Collection $basis, ForbiddenTerms $forbidden): ?array
    {
        if (! is_array($item)) {
            return null;
        }
        $kind = (string) ($item['kind'] ?? '');
        $text = trim((string) ($item['text'] ?? ''));
        $answer = trim((string) ($item['answer'] ?? ''));
        $refs = collect((array) ($item['refs'] ?? []))->map(fn ($r): int => (int) $r)->filter(fn (int $r): bool => $basis->has($r))->unique()->values();
        if (! array_key_exists($kind, (array) config('moxdop-epicrisis.synthesis')) || mb_strlen($text) < 8 || $refs->isEmpty()) {
            return null;
        }
        $cited = $refs->map(fn (int $r): string => $basis->get($r)->line().' '.$basis->get($r)->source_text)->implode(' ');
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text.' '.$answer, $numbers);
        foreach ($numbers[0] as $number) {
            if (! str_contains($cited, $number)) {
                return null;
            }
        }
        // "Söyleme" lines name what not to say, so they may quote a forbidden phrase.
        if ($kind !== 'dont_say' && $forbidden->blocking($text.' '.$answer) !== []) {
            return null;
        }

        return ['field' => $kind, 'value' => mb_substr($text, 0, 400), 'source_kind' => 'ai', 'source_ref' => 'Kayıt #'.$refs->implode(', #'), 'confidence' => BrandEpicrisisItem::INFERRED,
            'detail' => array_filter(['refs' => $refs->all(), 'answer' => $answer !== '' ? mb_substr($answer, 0, 400) : null])];
    }
}
