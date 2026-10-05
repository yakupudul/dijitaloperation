<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Enums\IntelligenceCore\IntelligenceValueState;
use App\Models\DigitalAsset;
use App\Services\IntelligenceCore\IntelligenceMetricFactory;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class WebsiteProjectionAdapterSupport
{
    private const int SOURCE_RECORD_KEY_MAX_BYTES = 255;

    public function __construct(
        private readonly IntelligenceMetricFactory $metrics,
    ) {}

    /** @return array<string, mixed> */
    public function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Rows arrive newest first; streamed (cursor) so a long snapshot history never sits in PHP memory at once.
     *
     * @param  iterable<int, object>  $rows
     * @return array<string, object>
     */
    public function latestBy(iterable $rows, callable $key): array
    {
        $latest = [];
        foreach ($rows as $row) {
            $value = trim((string) $key($row));
            if ($value !== '' && ! isset($latest[$value])) {
                $latest[$value] = $row;
            }
        }

        return $latest;
    }

    /**
     * One row per distinct raw dimension value of a daily fact table, aggregated in SQL instead of reading every
     * fact row into PHP. Rows of a group are taken in (reporting_date, id) order, the order the row loop used, so
     * sums accumulate in the same order and the latest row is the same one. Compact fact views have no row id
     * (NULL): their natural key gives one row per dimension value and day, so the date alone orders them.
     *
     * Each returned row carries the dimension aliases, the sum aliases, last_collected_at (latest), the latest
     * row's $latestColumns, first_date / first_id (where the group first appears) and latest_date / latest_id.
     * Rows are ordered by first appearance, then by the dimension values.
     *
     * @param  array<string, string>  $dimensions  alias => SQL expression of the raw dimension value
     * @param  array<string, string>  $sums  alias => SQL expression summed over the group (NULLs skipped)
     * @param  list<string>  $latestColumns
     * @return list<object>
     */
    public function factGroups(QueryBuilder $facts, array $dimensions, array $sums, array $latestColumns): array
    {
        $grammar = $facts->getGrammar();
        $over = $this->factWindow($facts, $dimensions, 'ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING');
        $select = [];
        foreach ($dimensions as $alias => $expression) {
            $select[] = $expression.' AS '.$alias;
        }
        foreach ($sums as $alias => $expression) {
            $select[] = 'SUM('.$expression.') '.$over.' AS '.$alias;
        }
        array_push(
            $select,
            'ROW_NUMBER() '.$over.' AS fact_rank',
            'COUNT(*) '.$over.' AS fact_count',
            'MAX('.$grammar->wrap('last_collected_at').') '.$over.' AS last_collected_at',
            'FIRST_VALUE('.$grammar->wrap('reporting_date').') '.$over.' AS first_date',
            'FIRST_VALUE('.$grammar->wrap('id').') '.$over.' AS first_id',
            $grammar->wrap('reporting_date').' AS latest_date',
            $grammar->wrap('id').' AS latest_id',
        );
        foreach ($latestColumns as $column) {
            $select[] = $grammar->wrap($column);
        }

        $query = DB::query()->fromSub((clone $facts)->selectRaw(implode(', ', $select)), 'fact_groups')
            ->whereColumn('fact_rank', 'fact_count')
            ->orderBy('first_date')->orderBy('first_id');
        foreach (array_keys($dimensions) as $alias) {
            $query->orderBy($alias);
        }

        return $query->get()->all();
    }

    /**
     * The first fact row (in reporting_date, id order) of every distinct combination of the raw dimension values
     * and $valueColumns, ordered by that first appearance: the order in which the row loop first met each value.
     * Each row carries the dimension aliases, the value columns, appearance_date and appearance_id.
     *
     * @param  array<string, string>  $dimensions  alias => SQL expression of the raw dimension value
     * @param  list<string>  $valueColumns
     * @return list<object>
     */
    public function factFirstAppearances(QueryBuilder $facts, array $dimensions, array $valueColumns): array
    {
        $grammar = $facts->getGrammar();
        $partition = array_merge($dimensions, array_map($grammar->wrap(...), $valueColumns));
        $select = [];
        foreach ($dimensions as $alias => $expression) {
            $select[] = $expression.' AS '.$alias;
        }
        foreach ($valueColumns as $column) {
            $select[] = $grammar->wrap($column);
        }
        array_push(
            $select,
            $grammar->wrap('reporting_date').' AS appearance_date',
            $grammar->wrap('id').' AS appearance_id',
            'ROW_NUMBER() '.$this->factWindow($facts, $partition).' AS appearance_rank',
        );

        $query = DB::query()->fromSub((clone $facts)->selectRaw(implode(', ', $select)), 'fact_appearances')
            ->where('appearance_rank', 1)
            ->orderBy('appearance_date')->orderBy('appearance_id');
        foreach ([...array_keys($dimensions), ...$valueColumns] as $column) {
            $query->orderBy($column);
        }

        return $query->get()->all();
    }

    /**
     * Whether $candidate is a later fact row than $current, in (reporting_date, id) order.
     *
     * @param  array{0:string,1:int|null}|null  $current
     * @param  array{0:string,1:int|null}  $candidate
     */
    public function isLaterFact(?array $current, array $candidate): bool
    {
        return $current === null || [$candidate[0], $candidate[1] ?? 0] > [$current[0], $current[1] ?? 0];
    }

    /** @param array<array-key, string> $partition SQL expressions */
    private function factWindow(QueryBuilder $facts, array $partition, string $frame = ''): string
    {
        $grammar = $facts->getGrammar();

        return 'OVER (PARTITION BY '.implode(', ', $partition).' ORDER BY '.$grammar->wrap('reporting_date').', '
            .$grammar->wrap('id').($frame !== '' ? ' '.$frame : '').')';
    }

    public function source(
        string $provider,
        IntelligenceSourceClass $sourceClass,
        string $semantic,
        string $datasetId,
        object|array|null $row,
        ?int $fallbackAssetId = null,
        ?int $fallbackResourceId = null,
        ?string $recordKey = null,
    ): IntelligenceSourceReference {
        $read = static function (object|array|null $source, string $key): mixed {
            if (is_array($source)) {
                return $source[$key] ?? null;
            }

            return is_object($source) ? ($source->{$key} ?? null) : null;
        };

        $sourceRecordKey = $recordKey ?? (($id = $read($row, 'id')) !== null ? (string) $id : null);

        return new IntelligenceSourceReference(
            providerOrSource: $provider,
            sourceClass: $sourceClass,
            sourceSemantic: $semantic,
            datasetId: $datasetId,
            sourceRecordKey: $this->boundedSourceRecordKey($sourceRecordKey),
            sourceDigitalAssetId: ($assetId = $read($row, 'digital_asset_id')) !== null ? (int) $assetId : $fallbackAssetId,
            externalResourceId: ($resourceId = $read($row, 'external_resource_id')) !== null ? (int) $resourceId : $fallbackResourceId,
            collectionRunId: ($runId = $read($row, 'last_collection_run_id')) !== null ? (int) $runId : null,
            datasetRunId: ($datasetRunId = $read($row, 'last_dataset_run_id')) !== null ? (int) $datasetRunId : null,
            contractVersion: ($version = $read($row, 'contract_version')) !== null ? (int) $version : null,
        );
    }

    private function boundedSourceRecordKey(?string $key): ?string
    {
        if ($key === null || strlen($key) <= self::SOURCE_RECORD_KEY_MAX_BYTES) {
            return $key;
        }

        return 'sha256:'.hash('sha256', $key);
    }

    public function time(
        string $timezone,
        ?string $reportingDate = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        mixed $observedAt = null,
        mixed $retrievedAt = null,
        ?string $marketCode = null,
        ?string $languageCode = null,
    ): IntelligenceTimeContext {
        return new IntelligenceTimeContext(
            sourceTimezone: $this->validTimezone($timezone),
            reportingDate: $reportingDate,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            observedAt: $this->dateTime($observedAt),
            retrievedAt: $this->dateTime($retrievedAt),
            marketCode: $marketCode,
            languageCode: $languageCode,
        );
    }

    /** @param array<string, int|string> $dimensions @return array<string, mixed> */
    public function metric(
        string $metricId,
        int|float|string|bool|null $value,
        string $grain,
        array $dimensions,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $time,
        ?string $currencyCode = null,
        array $metadata = [],
        bool $collected = true,
    ): array {
        $state = ! $collected || $value === null
            ? IntelligenceValueState::NotCollected
            : (is_numeric($value) && (float) $value === 0.0
                ? IntelligenceValueState::Zero
                : IntelligenceValueState::Value);

        return $this->metrics->make(
            metricId: $metricId,
            state: $state,
            value: $state->carriesValue() ? $value : null,
            grain: $grain,
            dimensions: $dimensions,
            source: $source,
            timeContext: $time,
            currencyCode: $currencyCode,
            metadata: $metadata,
        )->toArray();
    }

    public function absolutePageUrl(DigitalAsset $asset, string $observed): ?string
    {
        $observed = trim($observed);
        if ($observed === '' || in_array($observed, ['(not set)', '(not provided)'], true)) {
            return null;
        }

        if (filter_var($observed, FILTER_VALIDATE_URL)) {
            return $observed;
        }

        $base = trim((string) ($asset->primary_url ?: $asset->domain));
        if ($base === '') {
            return null;
        }
        if (! str_contains($base, '://')) {
            $base = 'https://'.$base;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = str_starts_with($observed, '/') ? $observed : '/'.$observed;

        return $scheme.'://'.strtolower((string) $parts['host']).$port.$path;
    }

    public function latestTimestamp(mixed ...$values): ?string
    {
        $dates = array_values(array_filter(array_map(
            fn (mixed $value): ?CarbonImmutable => $this->dateTime($value),
            $values,
        )));
        if ($dates === []) {
            return null;
        }

        usort(
            $dates,
            static fn (CarbonImmutable $left, CarbonImmutable $right): int => $right->getTimestamp() <=> $left->getTimestamp(),
        );

        return $dates[0]->toIso8601String();
    }

    private function validTimezone(string $timezone): string
    {
        try {
            new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');

            return $timezone !== '' ? $timezone : 'UTC';
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
