<?php

namespace App\Services\SearchDemand;

use App\Exceptions\QueryExcluded;
use App\Jobs\Async\QueryExclusionJob;
use App\Models\SearchQueryLibraryItem;
use App\Models\User;
use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use App\Support\Options\LocationOptions;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QueryExclusionService
{
    public function authorize(?User $actor): void
    {
        abort_unless($actor?->is_active && $actor->can(Permissions::ACCESS_APP), 403);
    }

    public function rules(): array
    {
        return DB::table('query_exclusion_rules')->where('active', true)->orderBy('id')
            ->get(['id', 'label', 'normalized'])->map(fn ($row): array => (array) $row)->all();
    }

    public function fingerprint(array $rules): string
    {
        return hash('sha256', json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function matches(string $text, array $rules): array
    {
        $text = ' '.LocationOptions::fold($text).' ';

        return array_values(array_filter($rules, fn (array $rule): bool =>
            str_contains($text, ' '.$rule['normalized'].' ')));
    }

    public function exceptionHash(string $text, ?string $language = 'tr', ?string $locale = null): string
    {
        $text = app(SearchTermNormalizer::class)->normalize(LocationOptions::strip($text)['text'], $language ?: 'tr', $locale)->canonicalText;

        return hash('sha256', $text);
    }

    public function isProtected(string $text, ?string $language = 'tr', ?string $locale = null): bool
    {
        return DB::table('query_exclusion_exceptions')->where('identity_hash', $this->exceptionHash($text, $language, $locale))->exists();
    }

    public function protect(SearchQueryLibraryItem $item, ?User $actor): void
    {
        $this->authorize($actor);
        DB::table('query_exclusion_exceptions')->updateOrInsert(
            ['identity_hash' => $this->exceptionHash($item->canonical_text, $item->language_code, $item->locale)],
            ['query_text' => $item->canonical_text, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function checkImport(string $raw, ?string $language = 'tr', ?string $locale = null): void
    {
        $matches = $this->matches($raw, $this->rules());
        if ($matches !== [] && ! $this->isProtected($raw, $language, $locale)) {
            throw new QueryExcluded($matches);
        }
    }

    public function version(SearchQueryLibraryItem $item): string
    {
        return hash('sha256', $item->canonical_text.'|'.$item->getRawOriginal('updated_at'));
    }

    public function matchItem(SearchQueryLibraryItem $item, array $rules): ?array
    {
        if ($this->isProtected($item->canonical_text, $item->language_code, $item->locale)) {
            return null;
        }
        $expressions = $this->matches($item->canonical_text, $rules);
        if ($expressions !== []) {
            return ['text' => $item->canonical_text, 'expressions' => $expressions];
        }
        foreach ($item->sourceRecords()->orderBy('id')->lazyById(250) as $source) {
            $expressions = $this->matches($source->observed_text, $rules);
            if ($expressions !== []) {
                return ['text' => $source->observed_text, 'expressions' => $expressions];
            }
        }

        return null;
    }

    public function start(User $actor): int
    {
        $this->authorize($actor);
        $rules = $this->rules();
        if ($rules === []) {
            throw ValidationException::withMessages(['exclusions' => __('query-exclusions.no_rules')]);
        }

        return DB::transaction(function () use ($actor, $rules): int {
            $id = DB::table('query_exclusion_runs')->insertGetId([
                'created_by' => $actor->id, 'status' => 'scanning',
                'rules' => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'rules_hash' => $this->fingerprint($rules),
                'max_item_id' => SearchQueryLibraryItem::query()->max('id') ?? 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            QueryExclusionJob::dispatch($id)->afterCommit();

            return $id;
        });
    }

    public function approve(int $id, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($id, $actor): void {
            $run = DB::table('query_exclusion_runs')->where('id', $id)->where('created_by', $actor->id)->lockForUpdate()->first();
            abort_unless($run && $run->status === 'ready', 409);
            if ($run->rules_hash !== $this->fingerprint($this->rules())) {
                throw ValidationException::withMessages(['exclusions' => __('query-exclusions.rules_changed')]);
            }
            DB::table('query_exclusion_matches')->where('run_id', $id)->where('selected', false)->update(['decision' => 'kept', 'updated_at' => now()]);
            DB::table('query_exclusion_runs')->where('id', $id)->update(['status' => 'applying', 'approved_at' => now(), 'updated_at' => now()]);
            QueryExclusionJob::dispatch($id)->afterCommit();
        });
    }

    public function execute(int $id): void
    {
        $run = DB::table('query_exclusion_runs')->find($id);
        if (! $run || ! in_array($run->status, ['scanning', 'applying'], true)) {
            return;
        }
        $actor = User::query()->find($run->created_by);
        $this->authorize($actor);
        $rules = json_decode($run->rules, true, 512, JSON_THROW_ON_ERROR);
        if ($run->status === 'scanning') {
            $items = SearchQueryLibraryItem::query()->where('id', '>', $run->cursor)->where('id', '<=', $run->max_item_id)->orderBy('id')->limit(100)->get();
            foreach ($items as $item) {
                $match = $this->matchItem($item, $rules);
                DB::transaction(function () use ($id, $item, $match): void {
                    if ($match !== null) {
                        DB::table('query_exclusion_matches')->insertOrIgnore([
                            'run_id' => $id, 'item_id' => $item->id, 'query_text' => $item->canonical_text,
                            'matched_text' => $match['text'],
                            'expressions' => json_encode($match['expressions'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'version' => $this->version($item), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    DB::table('query_exclusion_runs')->where('id', $id)->update(['cursor' => $item->id, 'updated_at' => now()]);
                    DB::table('query_exclusion_runs')->where('id', $id)->increment('scanned');
                });
            }
            DB::table('query_exclusion_runs')->where('id', $id)->update([
                'matched' => DB::table('query_exclusion_matches')->where('run_id', $id)->count(), 'updated_at' => now(),
            ]);
            if ($items->count() < 100) {
                DB::table('query_exclusion_runs')->where('id', $id)->update(['status' => 'ready', 'updated_at' => now()]);

                return;
            }
        } else {
            abort_unless($run->approved_at !== null, 409);
            $matches = DB::table('query_exclusion_matches')->where('run_id', $id)->where('selected', true)->where('decision', 'pending')->orderBy('id')->limit(100)->get();
            foreach ($matches as $match) {
                if ($run->rules_hash !== $this->fingerprint($this->rules())) {
                    throw ValidationException::withMessages(['exclusions' => __('query-exclusions.rules_changed')]);
                }
                DB::transaction(function () use ($id, $match, $rules, $actor): void {
                    $item = SearchQueryLibraryItem::query()->lockForUpdate()->find($match->item_id);
                    $current = $item && $this->version($item) === $match->version ? $this->matchItem($item, $rules) : null;
                    $remove = $current !== null && $current['text'] === $match->matched_text;
                    if ($remove) {
                        $item->updated_by = $actor->id;
                        $item->save();
                        $item->delete();
                    }
                    DB::table('query_exclusion_matches')->where('id', $match->id)->update([
                        'decision' => $remove ? 'removed' : 'skipped', 'updated_at' => now(),
                    ]);
                    DB::table('query_exclusion_runs')->where('id', $id)->increment($remove ? 'removed' : 'skipped', 1, ['updated_at' => now()]);
                });
            }
            if ($matches->count() < 100) {
                DB::table('query_exclusion_runs')->where('id', $id)->update(['status' => 'completed', 'updated_at' => now()]);

                return;
            }
        }
        QueryExclusionJob::dispatch($id)->delay(now()->addSeconds(1));
    }

    public function fail(int $id, ?\Throwable $error): void
    {
        DB::table('query_exclusion_runs')->where('id', $id)->whereIn('status', ['scanning', 'applying'])->update([
            'status' => 'failed',
            'error' => $error instanceof ValidationException ? collect($error->errors())->flatten()->implode(' ') : __('query-exclusions.failed_help'),
            'updated_at' => now(),
        ]);
    }
}
