<?php

namespace App\Services\ReportSnapshots;

use App\Enums\ReportSnapshotSchemaVersion;
use App\Enums\ReportType;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\ReportSnapshot;
use App\Support\ReportSnapshots\ClientValueStorySnapshotSerializer;
use App\Support\ReportSnapshots\ReportSnapshotChecksum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Report Snapshot read boundary (Prompt 59).
 * Detail always uses frozen payload — never ClientValueStoryReadService.
 */
final class ReportSnapshotReadService
{
    public function __construct(
        private readonly ClientValueStorySnapshotSerializer $serializer,
    ) {}

    /**
     * @param  array{
     *     report_type?: string|null,
     *     brand_id?: int|null,
     *     period_start?: string|null,
     *     period_end?: string|null,
     *     per_page?: int
     * }  $filters
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     * @return LengthAwarePaginator<int, ReportSnapshot>
     */
    public function listForCustomer(
        Customer $customer,
        array $filters = [],
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): LengthAwarePaginator {
        $this->assertCustomerAuthorized($customer, $authorizedCustomerIds);

        $query = ReportSnapshot::query()
            ->where('customer_id', $customer->id)
            ->select([
                'id',
                'customer_id',
                'brand_id',
                'report_type',
                'period_start',
                'period_end',
                'comparison_period_start',
                'comparison_period_end',
                'title_snapshot',
                'customer_name_snapshot',
                'brand_name_snapshot',
                'locale',
                'reporting_timezone',
                'snapshot_schema_version',
                'source_manifest_fingerprint',
                'content_checksum',
                'generated_by',
                'generated_at',
                'supersedes_snapshot_id',
                'created_at',
            ])
            ->orderByDesc('generated_at')
            ->orderByDesc('id');

        if ($authorizedBrandIds !== []) {
            $query->whereIn('brand_id', array_map('intval', $authorizedBrandIds));
        }
        if (! empty($filters['brand_id'])) {
            $brandId = (int) $filters['brand_id'];
            if ($authorizedBrandIds !== [] && ! in_array($brandId, array_map('intval', $authorizedBrandIds), true)) {
                throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
            }
            $query->where('brand_id', $brandId);
        }
        if (! empty($filters['report_type'])) {
            $query->where('report_type', (string) $filters['report_type']);
        }
        if (! empty($filters['period_start'])) {
            $query->whereDate('period_start', '>=', (string) $filters['period_start']);
        }
        if (! empty($filters['period_end'])) {
            $query->whereDate('period_end', '<=', (string) $filters['period_end']);
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return $query->paginate($perPage);
    }

    /**
     * @param  array{
     *     report_type?: string|null,
     *     period_start?: string|null,
     *     period_end?: string|null,
     *     per_page?: int
     * }  $filters
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     * @return LengthAwarePaginator<int, ReportSnapshot>
     */
    public function listForBrand(
        Brand $brand,
        array $filters = [],
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): LengthAwarePaginator {
        $this->assertBrandAuthorized($brand, $authorizedCustomerIds, $authorizedBrandIds);

        return $this->listForCustomer(
            Customer::query()->findOrFail((int) $brand->customer_id),
            array_merge($filters, ['brand_id' => (int) $brand->id]),
            $authorizedCustomerIds,
            $authorizedBrandIds !== [] ? $authorizedBrandIds : [(int) $brand->id],
        );
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     * @return array<string, mixed>
     */
    public function detail(
        int $snapshotId,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
        bool $verifyChecksum = true,
    ): array {
        $snapshot = ReportSnapshot::query()->find($snapshotId);
        if ($snapshot === null) {
            throw ValidationException::withMessages(['report_snapshot' => 'SNAPSHOT_NOT_FOUND']);
        }

        $this->assertSnapshotAuthorized($snapshot, $authorizedCustomerIds, $authorizedBrandIds);

        $schema = $snapshot->snapshot_schema_version;
        if (! $schema instanceof ReportSnapshotSchemaVersion || ! $schema->isReadable()) {
            throw ValidationException::withMessages([
                'snapshot_schema_version' => 'UNSUPPORTED_SNAPSHOT_SCHEMA',
            ]);
        }

        $content = $snapshot->content_payload ?? [];
        if (! is_array($content)) {
            throw ValidationException::withMessages(['content_payload' => 'INVALID_SNAPSHOT_PAYLOAD']);
        }

        try {
            $this->serializer->validate($content);
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($verifyChecksum && ! ReportSnapshotChecksum::verify($content, (string) $snapshot->content_checksum)) {
            throw ValidationException::withMessages(['content_checksum' => 'CONTENT_CHECKSUM_MISMATCH']);
        }

        return [
            'id' => (int) $snapshot->id,
            'customer_id' => (int) $snapshot->customer_id,
            'brand_id' => (int) $snapshot->brand_id,
            'report_type' => $snapshot->report_type instanceof ReportType
                ? $snapshot->report_type->value
                : (string) $snapshot->report_type,
            'period_start' => $snapshot->period_start?->toDateString(),
            'period_end' => $snapshot->period_end?->toDateString(),
            'comparison_period_start' => $snapshot->comparison_period_start?->toDateString(),
            'comparison_period_end' => $snapshot->comparison_period_end?->toDateString(),
            'title' => (string) $snapshot->title_snapshot,
            'customer_name' => (string) $snapshot->customer_name_snapshot,
            'brand_name' => (string) $snapshot->brand_name_snapshot,
            'locale' => (string) $snapshot->locale,
            'reporting_timezone' => (string) $snapshot->reporting_timezone,
            'snapshot_schema_version' => $schema->value,
            'content' => $content,
            'source_manifest' => $snapshot->source_manifest_payload,
            'source_manifest_fingerprint' => (string) $snapshot->source_manifest_fingerprint,
            'content_checksum' => (string) $snapshot->content_checksum,
            'generated_by' => (int) $snapshot->generated_by,
            'generated_at' => $snapshot->generated_at?->toIso8601String(),
            'supersedes_snapshot_id' => $snapshot->supersedes_snapshot_id !== null
                ? (int) $snapshot->supersedes_snapshot_id
                : null,
            'delivery' => [
                'pdf' => false,
                'download' => false,
                'share' => false,
                'email' => false,
                'owner' => 'prompt_60',
            ],
            // Hard invariant: detail is frozen payload only.
            'rebuilt_from_live_story' => false,
        ];
    }

    /**
     * Customer Reports presentation — real snapshots only, no Demo fallback.
     *
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     * @return array<string, mixed>
     */
    public function forCustomerReportsPresentation(
        int $customerId,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
        int $page = 1,
        int $perPage = 20,
    ): array {
        $customer = Customer::query()->find($customerId);
        if ($customer === null) {
            return [
                'customer_id' => $customerId,
                'snapshots' => [],
                'brands' => [],
                'empty' => true,
                'aggregation_note' => __('operator.reports.no_blind_aggregation'),
                'demo' => false,
            ];
        }

        $this->assertCustomerAuthorized($customer, $authorizedCustomerIds);

        $query = ReportSnapshot::query()
            ->where('customer_id', $customer->id)
            ->select([
                'id',
                'customer_id',
                'brand_id',
                'report_type',
                'period_start',
                'period_end',
                'title_snapshot',
                'customer_name_snapshot',
                'brand_name_snapshot',
                'locale',
                'generated_by',
                'generated_at',
                'created_at',
            ])
            ->orderByDesc('generated_at')
            ->orderByDesc('id');

        if ($authorizedBrandIds !== []) {
            $query->whereIn('brand_id', array_map('intval', $authorizedBrandIds));
        }

        $paginator = $query->paginate(
            perPage: max(1, min(100, $perPage)),
            page: max(1, $page),
        );

        $rows = [];
        foreach ($paginator->items() as $snapshot) {
            /** @var ReportSnapshot $snapshot */
            $rows[] = [
                'id' => (int) $snapshot->id,
                'title' => (string) $snapshot->title_snapshot,
                'brand_id' => (int) $snapshot->brand_id,
                'brand_name' => (string) $snapshot->brand_name_snapshot,
                'report_type' => $snapshot->report_type instanceof ReportType
                    ? $snapshot->report_type->value
                    : (string) $snapshot->report_type,
                'period_start' => $snapshot->period_start?->toDateString(),
                'period_end' => $snapshot->period_end?->toDateString(),
                'generated_at' => $snapshot->generated_at?->toIso8601String(),
                'generated_by' => (int) $snapshot->generated_by,
                'locale' => (string) $snapshot->locale,
                'view_url' => route('demo.brand', [
                    'brand' => (string) $snapshot->brand_id,
                    'tab' => 'value',
                    'value' => 'reports',
                    'snapshot' => (string) $snapshot->id,
                ]),
            ];
        }

        $brands = Brand::query()
            ->where('customer_id', $customerId)
            ->when($authorizedBrandIds !== [], fn ($q) => $q->whereIn('id', $authorizedBrandIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        $brandCards = $brands->map(static function (Brand $brand): array {
            return [
                'brand_id' => (int) $brand->id,
                'brand_name' => (string) $brand->name,
                'report_url' => route('demo.brand', [
                    'brand' => (string) $brand->id,
                    'tab' => 'value',
                    'value' => 'reports',
                ]),
                'value_url' => route('demo.brand', [
                    'brand' => (string) $brand->id,
                    'tab' => 'value',
                ]),
            ];
        })->all();

        return [
            'customer_id' => $customerId,
            'snapshots' => $rows,
            'brands' => $brandCards,
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'empty' => $rows === [],
            'aggregation_note' => __('operator.reports.no_blind_aggregation'),
            'demo' => false,
            'fake_reports' => false,
        ];
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     */
    private function assertCustomerAuthorized(Customer $customer, array $authorizedCustomerIds): void
    {
        if ($authorizedCustomerIds !== [] && ! in_array((int) $customer->id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertBrandAuthorized(Brand $brand, array $authorizedCustomerIds, array $authorizedBrandIds): void
    {
        if ($authorizedBrandIds !== [] && ! in_array((int) $brand->id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $brand->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertSnapshotAuthorized(
        ReportSnapshot $snapshot,
        array $authorizedCustomerIds,
        array $authorizedBrandIds,
    ): void {
        if ($authorizedBrandIds !== [] && ! in_array((int) $snapshot->brand_id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $snapshot->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }
}
