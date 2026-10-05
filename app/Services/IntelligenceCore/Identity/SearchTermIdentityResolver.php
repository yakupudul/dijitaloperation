<?php

namespace App\Services\IntelligenceCore\Identity;

use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Enums\IntelligenceCore\SearchTermKind;
use App\Models\Brand;
use App\Models\IntelligenceCore\IntelligenceSearchTermAlias;
use App\Models\IntelligenceCore\IntelligenceSearchTermIdentity;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SearchTermIdentityResolver
{
    /** Observed terms resolved per transaction (one read and one write of identities and of aliases each). */
    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly SearchTermNormalizer $normalizer,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function resolve(
        Brand $brand,
        string $observedText,
        SearchTermKind $termKind,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $time,
        ?string $locale = null,
        array $metadata = [],
    ): IntelligenceSearchTermIdentity {
        $ids = $this->resolveMany($brand, [[
            'observed_text' => $observedText,
            'term_kind' => $termKind,
            'source' => $source,
            'time' => $time,
            'locale' => $locale,
            'metadata' => $metadata,
        ]]);

        return IntelligenceSearchTermIdentity::query()->findOrFail($ids[0]);
    }

    /**
     * Resolves many observed terms of one Brand with a few statements per 500 terms (identities and aliases are
     * each read with one whereIn and written with one insert and one upsert) instead of a transaction and up to
     * six statements per term. The stored rows are those of calling resolve() once per term in the given order:
     * a term seen twice widens first/last seen, the last observation fills the alias. A term that normalizes to
     * nothing still fails, after the terms before it were resolved.
     *
     * @param  list<array{observed_text: string, term_kind: SearchTermKind, source: IntelligenceSourceReference, time: IntelligenceTimeContext, locale?: ?string, metadata?: array<string, mixed>}>  $observations
     * @return list<int> the identity id of each observation, in order
     */
    public function resolveMany(Brand $brand, array $observations): array
    {
        if ($brand->getKey() === null) {
            throw new InvalidArgumentException('Search-term identity requires a persisted Brand.');
        }

        $prepared = [];
        $failure = null;
        foreach ($observations as $observation) {
            $time = $observation['time'];
            $locale = $observation['locale'] ?? null;
            $normalized = $this->normalizer->normalize($observation['observed_text'], $time->languageCode, $locale);
            if ($normalized->canonicalText === '') {
                $failure = new InvalidArgumentException('Search-term identity cannot be empty.');
                break;
            }
            $prepared[] = [
                ...$observation,
                'locale' => $locale,
                'metadata' => $observation['metadata'] ?? [],
                'normalized' => $normalized,
                'observed_at' => $time->observedAt ?? now(),
                'identity_hash' => hash('sha256', json_encode([
                    'brand_id' => $brand->getKey(),
                    'canonical_text' => $normalized->canonicalText,
                    'language_code' => $time->languageCode,
                    'locale' => $locale,
                    'market_code' => $time->marketCode,
                    'normalization_version' => $normalized->normalizationVersion,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'fingerprint' => $observation['source']->fingerprint(
                    'search_term:brand:'.$brand->getKey().':'.$observation['term_kind']->value,
                    $normalized->canonicalText,
                ),
            ];
        }

        $ids = [];
        foreach (array_chunk($prepared, self::BATCH_SIZE) as $batch) {
            array_push($ids, ...DB::transaction(fn (): array => $this->resolveBatch($brand, $batch)));
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @return list<int>
     */
    private function resolveBatch(Brand $brand, array $batch): array
    {
        $identities = $this->identities($brand, $batch);

        $existing = IntelligenceSearchTermAlias::query()
            ->whereIn('source_fingerprint', array_values(array_unique(array_column($batch, 'fingerprint'))))
            ->get()->keyBy('source_fingerprint')->all();
        $aliases = [];
        $ids = [];
        $columns = [];
        foreach ($batch as $item) {
            $identityId = (int) $identities[$item['identity_hash']]->getKey();
            $ids[] = $identityId;
            $observedAt = $item['observed_at'];
            $fingerprint = $item['fingerprint'];
            // An alias first created earlier in this batch counts as existing, as it would after its own save().
            $seen = isset($aliases[$fingerprint]) || isset($existing[$fingerprint]);
            $alias = $aliases[$fingerprint] ?? $existing[$fingerprint] ?? new IntelligenceSearchTermAlias(['source_fingerprint' => $fingerprint]);
            if (! $seen) {
                $alias->first_observed_at = $observedAt;
                $alias->last_observed_at = $observedAt;
            } else {
                if ($alias->first_observed_at->greaterThan($observedAt)) {
                    $alias->first_observed_at = $observedAt;
                }
                if ($alias->last_observed_at->lessThan($observedAt)) {
                    $alias->last_observed_at = $observedAt;
                }
            }
            $attributes = $this->aliasAttributes($identityId, $item);
            $columns = array_keys($attributes);
            $alias->fill($attributes);
            $aliases[$fingerprint] = $alias;
        }
        $this->write(new IntelligenceSearchTermAlias, array_values($aliases), [...$columns, 'first_observed_at', 'last_observed_at', 'updated_at']);

        return $ids;
    }

    /**
     * The identity of every observed term, created when missing (insertOrIgnore, so a concurrent resolve keeps its
     * row) and with first / last seen widened over the batch, in batch order.
     *
     * @param  list<array<string, mixed>>  $batch
     * @return array<string, IntelligenceSearchTermIdentity> identity hash => identity
     */
    private function identities(Brand $brand, array $batch): array
    {
        $hashes = array_values(array_unique(array_column($batch, 'identity_hash')));
        $loaded = IntelligenceSearchTermIdentity::query()->whereIn('identity_hash', $hashes)->get()->keyBy('identity_hash')->all();

        $created = [];
        foreach ($batch as $item) {
            if (! isset($loaded[$item['identity_hash']]) && ! isset($created[$item['identity_hash']])) {
                $created[$item['identity_hash']] = $this->newIdentity($brand, $item);
            }
        }
        if ($created !== []) {
            IntelligenceSearchTermIdentity::query()->insertOrIgnore(array_values(array_map(
                static fn (IntelligenceSearchTermIdentity $identity): array => $identity->updateTimestamps()->getAttributes(),
                $created,
            )));
            $loaded += IntelligenceSearchTermIdentity::query()->whereIn('identity_hash', array_keys($created))->get()->keyBy('identity_hash')->all();
        }

        // The rows this batch inserted already hold the first observation of each hash; every later one (and every
        // observation of a row that existed, or that a concurrent resolve inserted first) widens first / last seen.
        $first = [];
        foreach ($batch as $item) {
            $hash = $item['identity_hash'];
            $identity = $loaded[$hash];
            if (! isset($first[$hash])) {
                $first[$hash] = true;
                if (isset($created[$hash]) && $created[$hash]->uuid === $identity->uuid) {
                    continue;
                }
            }
            $this->widenSeen($identity, $item['observed_at']);
        }
        $this->write(new IntelligenceSearchTermIdentity, array_values($loaded), ['first_seen_at', 'last_seen_at', 'updated_at']);

        return $loaded;
    }

    /** @param array<string, mixed> $item */
    private function newIdentity(Brand $brand, array $item): IntelligenceSearchTermIdentity
    {
        $time = $item['time'];

        return new IntelligenceSearchTermIdentity([
            'identity_hash' => $item['identity_hash'],
            'uuid' => (string) Str::uuid(),
            'brand_id' => $brand->getKey(),
            'canonical_text' => $item['normalized']->canonicalText,
            'folded_text' => $item['normalized']->foldedText,
            'language_code' => $time->languageCode,
            'locale' => $item['locale'],
            'market_code' => $time->marketCode,
            'resolution_status' => IdentityResolutionStatus::Resolved,
            'normalization_version' => $item['normalized']->normalizationVersion,
            'first_seen_at' => $item['observed_at'],
            'last_seen_at' => $item['observed_at'],
        ]);
    }

    private function widenSeen(IntelligenceSearchTermIdentity $identity, DateTimeInterface $observedAt): void
    {
        if ($identity->last_seen_at->lessThan($observedAt)) {
            $identity->last_seen_at = $observedAt;
        }
        if ($identity->first_seen_at->greaterThan($observedAt)) {
            $identity->first_seen_at = $observedAt;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function aliasAttributes(int $identityId, array $item): array
    {
        $source = $item['source'];
        $time = $item['time'];
        $normalized = $item['normalized'];

        return [
            'search_term_identity_id' => $identityId,
            'source_digital_asset_id' => $source->sourceDigitalAssetId,
            'external_resource_id' => $source->externalResourceId,
            'collection_run_id' => $source->collectionRunId,
            'dataset_run_id' => $source->datasetRunId,
            'provider_or_source' => $source->providerOrSource,
            'source_class' => $source->sourceClass,
            'term_kind' => $item['term_kind'],
            'source_dataset_id' => $source->datasetId,
            'source_record_key' => $source->sourceRecordKey,
            'observed_text' => $item['observed_text'],
            'normalized_text' => $normalized->canonicalText,
            'folded_text' => $normalized->foldedText,
            'match_method' => IdentityMatchMethod::SyntacticExact,
            'resolution_status' => IdentityResolutionStatus::Resolved,
            'source_timezone' => $time->sourceTimezone,
            'market_code' => $time->marketCode,
            'language_code' => $time->languageCode,
            'metadata' => array_merge([
                'locale' => $item['locale'],
                'normalization_version' => $normalized->normalizationVersion,
                'source_contract_version' => $source->contractVersion,
            ], $item['metadata']),
        ];
    }

    /**
     * Inserts the new models and upserts (by id) the changed ones, BATCH_SIZE rows a statement; unchanged ones are
     * not written, as save() would not write them. Updates go in id order: batches of one Brand running at once
     * (two sites, two workers) then lock their shared identity rows in the same order instead of deadlocking.
     *
     * @param  list<Model>  $models
     * @param  list<string>  $updateColumns
     */
    private function write(Model $prototype, array $models, array $updateColumns): void
    {
        $key = $prototype->getKeyName();
        $inserts = [];
        $updates = [];
        foreach ($models as $model) {
            if ($model->exists && ! $model->isDirty()) {
                continue;
            }
            $model->updateTimestamps();
            if ($model->exists) {
                $updates[] = $model->getAttributes();
            } else {
                $inserts[] = $model->getAttributes();
            }
        }
        usort($updates, static fn (array $left, array $right): int => (int) $left[$key] <=> (int) $right[$key]);
        foreach (array_chunk($inserts, self::BATCH_SIZE) as $chunk) {
            DB::table($prototype->getTable())->insert($chunk);
        }
        foreach (array_chunk($updates, self::BATCH_SIZE) as $chunk) {
            DB::table($prototype->getTable())->upsert($chunk, [$key], array_values(array_unique($updateColumns)));
        }
    }
}
