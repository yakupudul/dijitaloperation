<?php

namespace App\Services\ReportSnapshots;

use App\Enums\ReportSnapshotSchemaVersion;
use App\Enums\ReportType;
use App\Models\Brand;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Support\ReportSnapshots\ClientValueStorySnapshotSerializer;
use App\Support\ReportSnapshots\ReportSnapshotChecksum;
use App\Support\ReportSnapshots\ReportTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Report Snapshot creation boundary (Prompt 59).
 *
 * Browser supplies scope/period options only — never content or manifest.
 * Zero AI / provider / domain writes other than the Snapshot row itself.
 */
final class CreateReportSnapshotService
{
    public function __construct(
        private readonly ClientValueStoryReadService $stories,
        private readonly ClientValueStorySnapshotSerializer $serializer,
        private readonly ReportTypeRegistry $reportTypes,
    ) {}

    /**
     * @param  array{
     *     period_start: string,
     *     period_end: string,
     *     locale?: string,
     *     title?: string|null,
     *     comparison_period_start?: string|null,
     *     comparison_period_end?: string|null,
     *     reporting_timezone?: string|null,
     *     supersedes_snapshot_id?: int|null,
     *     idempotency_key?: string|null,
     *     report_type?: string
     * }  $input
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function create(
        Brand $brand,
        User $actor,
        array $input,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): ReportSnapshot {
        $this->assertAuthorized($brand, $authorizedCustomerIds, $authorizedBrandIds);

        $reportTypeValue = (string) ($input['report_type'] ?? ReportType::ClientValueStory->value);
        if (! $this->reportTypes->has($reportTypeValue)) {
            throw ValidationException::withMessages(['report_type' => 'UNSUPPORTED_REPORT_TYPE']);
        }
        $reportType = ReportType::from($reportTypeValue);
        if ($reportType !== ReportType::ClientValueStory) {
            throw ValidationException::withMessages(['report_type' => 'UNSUPPORTED_REPORT_TYPE']);
        }

        // Reject browser-supplied business truth.
        foreach (['content_payload', 'source_manifest_payload', 'source_manifest', 'content', 'checksum', 'fingerprint'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw ValidationException::withMessages([$forbidden => 'BROWSER_SNAPSHOT_CONTENT_FORBIDDEN']);
            }
        }

        $periodStart = $this->parseDate((string) ($input['period_start'] ?? ''), 'period_start');
        $periodEnd = $this->parseDate((string) ($input['period_end'] ?? ''), 'period_end');
        if ($periodEnd->lt($periodStart)) {
            throw ValidationException::withMessages(['period' => 'INVALID_REPORT_PERIOD']);
        }

        $comparisonStart = null;
        $comparisonEnd = null;
        if (! empty($input['comparison_period_start']) || ! empty($input['comparison_period_end'])) {
            $comparisonStart = $this->parseDate((string) ($input['comparison_period_start'] ?? ''), 'comparison_period_start');
            $comparisonEnd = $this->parseDate((string) ($input['comparison_period_end'] ?? ''), 'comparison_period_end');
            if ($comparisonEnd->lt($comparisonStart)) {
                throw ValidationException::withMessages(['comparison_period' => 'INVALID_COMPARISON_PERIOD']);
            }
        }

        $locale = (string) ($input['locale'] ?? app()->getLocale() ?: 'en');
        $locale = in_array($locale, ['en', 'tr'], true) ? $locale : 'en';
        $timezone = (string) ($input['reporting_timezone'] ?? config('app.timezone', 'UTC'));
        $customTitle = isset($input['title']) ? (string) $input['title'] : null;
        if ($customTitle === '') {
            $customTitle = null;
        }

        $idempotencyKey = isset($input['idempotency_key']) && is_string($input['idempotency_key']) && $input['idempotency_key'] !== ''
            ? mb_substr($input['idempotency_key'], 0, 128)
            : null;

        if ($idempotencyKey !== null) {
            $existing = ReportSnapshot::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $supersedesId = isset($input['supersedes_snapshot_id']) ? (int) $input['supersedes_snapshot_id'] : null;
        if ($supersedesId !== null && $supersedesId > 0) {
            $this->assertSupersedes($brand, $reportType, $supersedesId);
        } else {
            $supersedesId = null;
        }

        $brand->loadMissing('customer');

        try {
            return DB::transaction(function () use (
                $brand,
                $actor,
                $periodStart,
                $periodEnd,
                $comparisonStart,
                $comparisonEnd,
                $locale,
                $timezone,
                $customTitle,
                $idempotencyKey,
                $supersedesId,
                $reportType,
                $authorizedCustomerIds,
                $authorizedBrandIds,
            ): ReportSnapshot {
                $this->applyConsistentReadIsolation();

                if ($idempotencyKey !== null) {
                    $existing = ReportSnapshot::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing !== null) {
                        return $existing;
                    }
                }

                $story = $this->stories->forBrand(
                    $brand,
                    $periodStart->toDateString(),
                    $periodEnd->toDateString(),
                    $authorizedCustomerIds,
                    $authorizedBrandIds,
                );

                $packed = $this->serializer->serialize(
                    story: $story,
                    brand: $brand,
                    actor: $actor,
                    locale: $locale,
                    reportingTimezone: $timezone,
                    customTitle: $customTitle,
                    comparisonPeriodStart: $comparisonStart?->toDateString(),
                    comparisonPeriodEnd: $comparisonEnd?->toDateString(),
                );

                $this->serializer->validate($packed['content']);
                $this->assertManifestMatchesStory($packed['source_manifest'], $story->sourceManifest->toArray());
                $this->assertBrandConsistency($brand, $packed['source_manifest'], $packed['content']);

                if (! ReportSnapshotChecksum::verify($packed['content'], $packed['content_checksum'])) {
                    throw ValidationException::withMessages(['content_checksum' => 'CONTENT_CHECKSUM_MISMATCH']);
                }
                if ($packed['source_manifest_fingerprint'] !== $story->sourceManifest->fingerprint()) {
                    throw ValidationException::withMessages(['source_manifest_fingerprint' => 'SOURCE_FINGERPRINT_MISMATCH']);
                }

                $now = CarbonImmutable::now();

                return ReportSnapshot::query()->create([
                    'customer_id' => (int) $brand->customer_id,
                    'brand_id' => (int) $brand->id,
                    'report_type' => $reportType,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'comparison_period_start' => $comparisonStart?->toDateString(),
                    'comparison_period_end' => $comparisonEnd?->toDateString(),
                    'title_snapshot' => $packed['title'],
                    'customer_name_snapshot' => (string) ($brand->customer?->name ?? 'Customer'),
                    'brand_name_snapshot' => (string) $brand->name,
                    'locale' => $locale,
                    'reporting_timezone' => $timezone,
                    'snapshot_schema_version' => ReportSnapshotSchemaVersion::ClientValueStoryV1,
                    'content_payload' => $packed['content'],
                    'source_manifest_payload' => $packed['source_manifest'],
                    'source_manifest_fingerprint' => $packed['source_manifest_fingerprint'],
                    'content_checksum' => $packed['content_checksum'],
                    'generated_by' => (int) $actor->id,
                    'generated_at' => $now,
                    'supersedes_snapshot_id' => $supersedesId,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => $now,
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Technical source failure — never freeze fake zeros.
            throw ValidationException::withMessages([
                'report_snapshot' => 'SNAPSHOT_SOURCE_READ_FAILED',
                'detail' => $e->getMessage(),
            ]);
        }
    }

    private function applyConsistentReadIsolation(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        // SQLite: single transaction provides a consistent snapshot for this create path.
    }

    /**
     * @param  array<string, mixed>  $persisted
     * @param  array<string, mixed>  $storyManifest
     */
    private function assertManifestMatchesStory(array $persisted, array $storyManifest): void
    {
        if (($persisted['finding_ids'] ?? null) !== ($storyManifest['finding_ids'] ?? null)
            || ($persisted['opportunity_ids'] ?? null) !== ($storyManifest['opportunity_ids'] ?? null)
            || ($persisted['task_ids'] ?? null) !== ($storyManifest['task_ids'] ?? null)
            || ($persisted['outcome_observation_revision_ids'] ?? null) !== ($storyManifest['outcome_observation_revision_ids'] ?? null)
        ) {
            throw ValidationException::withMessages(['source_manifest' => 'SOURCE_MANIFEST_MISMATCH']);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $content
     */
    private function assertBrandConsistency(Brand $brand, array $manifest, array $content): void
    {
        if ((int) ($manifest['brand_id'] ?? 0) !== (int) $brand->id
            || (int) ($manifest['customer_id'] ?? 0) !== (int) $brand->customer_id) {
            throw ValidationException::withMessages(['source_manifest' => 'CROSS_BRAND_SOURCE_REF']);
        }
        if ((int) ($content['header']['brand_id'] ?? 0) !== (int) $brand->id
            || (int) ($content['header']['customer_id'] ?? 0) !== (int) $brand->customer_id) {
            throw ValidationException::withMessages(['content_payload' => 'CROSS_BRAND_CONTENT']);
        }
    }

    private function assertSupersedes(Brand $brand, ReportType $reportType, int $supersedesId): void
    {
        $prior = ReportSnapshot::query()->find($supersedesId);
        if ($prior === null) {
            throw ValidationException::withMessages(['supersedes_snapshot_id' => 'SUPERSEDES_NOT_FOUND']);
        }
        if ((int) $prior->brand_id !== (int) $brand->id
            || (int) $prior->customer_id !== (int) $brand->customer_id) {
            throw ValidationException::withMessages(['supersedes_snapshot_id' => 'SUPERSEDES_CROSS_BRAND_FORBIDDEN']);
        }
        if ($prior->report_type !== $reportType) {
            throw ValidationException::withMessages(['supersedes_snapshot_id' => 'SUPERSEDES_TYPE_MISMATCH']);
        }
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertAuthorized(Brand $brand, array $authorizedCustomerIds, array $authorizedBrandIds): void
    {
        if ($authorizedBrandIds !== [] && ! in_array((int) $brand->id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $brand->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }

    private function parseDate(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'INVALID_DATE']);
        }
    }
}
