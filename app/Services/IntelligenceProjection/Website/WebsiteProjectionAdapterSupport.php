<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Enums\IntelligenceCore\IntelligenceValueState;
use App\Models\DigitalAsset;
use App\Services\IntelligenceCore\IntelligenceMetricFactory;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class WebsiteProjectionAdapterSupport
{
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
     * @param  Collection<int, object>  $rows
     * @return array<string, object>
     */
    public function latestBy(Collection $rows, callable $key): array
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

        return new IntelligenceSourceReference(
            providerOrSource: $provider,
            sourceClass: $sourceClass,
            sourceSemantic: $semantic,
            datasetId: $datasetId,
            sourceRecordKey: $recordKey ?? (($id = $read($row, 'id')) !== null ? (string) $id : null),
            sourceDigitalAssetId: ($assetId = $read($row, 'digital_asset_id')) !== null ? (int) $assetId : $fallbackAssetId,
            externalResourceId: ($resourceId = $read($row, 'external_resource_id')) !== null ? (int) $resourceId : $fallbackResourceId,
            collectionRunId: ($runId = $read($row, 'last_collection_run_id')) !== null ? (int) $runId : null,
            datasetRunId: ($datasetRunId = $read($row, 'last_dataset_run_id')) !== null ? (int) $datasetRunId : null,
            contractVersion: ($version = $read($row, 'contract_version')) !== null ? (int) $version : null,
        );
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
