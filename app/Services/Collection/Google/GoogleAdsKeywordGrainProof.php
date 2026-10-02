<?php

namespace App\Services\Collection\Google;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Warehouse-side proof of the Google Ads keyword snapshot grain
 * (`customer_id × ad_group_id × criterion_id`). Never returns raw customer,
 * ad-group, or criterion identifiers.
 *
 * Acceptance proof is exact-resource scoped. `UPSERT_CURRENT_STATE` does not
 * delete keys absent from a payload, so leftovers in this resource slice are
 * not current-run proof.
 */
final class GoogleAdsKeywordGrainProof
{
    public const string TABLE = 'google_ads_keyword_snapshot';

    public const int HASH_PREFIX_LENGTH = 12;

    /**
     * @param  array{
     *     digital_asset_id: int,
     *     external_resource_id: int,
     *     ads_customer_id?: string|null
     * }  $scope
     * @return array{
     *     table_present: bool,
     *     ad_group_id_column_present: bool,
     *     digital_asset_id: int,
     *     external_resource_id: int,
     *     ads_customer_id_hash: string|null,
     *     last_dataset_run_id: int|null,
     *     row_count: int,
     *     distinct_composite_count: int,
     *     non_null_ad_group_id_count: int,
     *     rows_missing_ad_group_id: int,
     *     distinct_criterion_count: int,
     *     criterion_ids_in_multiple_ad_groups: int,
     *     rows_last_written_by_dataset_run: int|null,
     *     rows_not_touched_by_dataset_run: int|null,
     *     repeated_criterion_samples: list<array{criterion_hash: string, ad_group_count: int}>,
     *     grain_matches_current_schema: bool,
     *     current_run_grain_proven: bool,
     *     current_run_proof_reasons: list<string>,
     *     snapshot_write_mode: string,
     *     notes: list<string>
     * }
     */
    public function prove(array $scope, ?int $lastDatasetRunId = null, int $sampleLimit = 8): array
    {
        $digitalAssetId = $this->requiredPositiveInt($scope, 'digital_asset_id');
        $externalResourceId = $this->requiredPositiveInt($scope, 'external_resource_id');
        $adsCustomerId = $this->optionalAdsCustomerId($scope);
        $adsCustomerHash = $adsCustomerId === null ? null : self::hashIdentifier($adsCustomerId);

        $notes = [];
        $tablePresent = Schema::hasTable(self::TABLE);
        $columnPresent = $tablePresent && Schema::hasColumn(self::TABLE, 'ad_group_id');

        if (! $tablePresent) {
            return $this->emptyProof(
                tablePresent: false,
                columnPresent: false,
                digitalAssetId: $digitalAssetId,
                externalResourceId: $externalResourceId,
                adsCustomerHash: $adsCustomerHash,
                lastDatasetRunId: $lastDatasetRunId,
                reasons: ['google_ads_keyword_snapshot table is missing on this connection'],
                notes: ['google_ads_keyword_snapshot table is missing on this connection'],
            );
        }

        if (! $columnPresent) {
            return $this->emptyProof(
                tablePresent: true,
                columnPresent: false,
                digitalAssetId: $digitalAssetId,
                externalResourceId: $externalResourceId,
                adsCustomerHash: $adsCustomerHash,
                lastDatasetRunId: $lastDatasetRunId,
                reasons: ['ad_group_id column is missing; current grain schema is not deployed'],
                notes: ['ad_group_id column is missing; current grain schema is not deployed'],
            );
        }

        $query = $this->scopedQuery($digitalAssetId, $externalResourceId, $adsCustomerId);

        $rowCount = (int) (clone $query)->count();
        $distinctComposite = (int) (clone $query)->selectRaw(
            "COUNT(DISTINCT customer_id || '|' || ad_group_id || '|' || criterion_id) as aggregate",
        )->value('aggregate');
        $nonNullAdGroup = (int) (clone $query)->whereNotNull('ad_group_id')->where('ad_group_id', '!=', '')->count();
        $distinctCriterion = (int) (clone $query)->distinct()->count('criterion_id');

        $repeated = $this->scopedQuery($digitalAssetId, $externalResourceId, $adsCustomerId)
            ->select('criterion_id', DB::raw('COUNT(DISTINCT ad_group_id) as ad_group_count'))
            ->groupBy('criterion_id')
            ->havingRaw('COUNT(DISTINCT ad_group_id) > 1')
            ->orderByDesc('ad_group_count')
            ->limit(max(1, $sampleLimit))
            ->get();

        $repeatedCount = $this->scopedQuery($digitalAssetId, $externalResourceId, $adsCustomerId)
            ->select('criterion_id')
            ->groupBy('criterion_id')
            ->havingRaw('COUNT(DISTINCT ad_group_id) > 1')
            ->get()
            ->count();

        $rowsWrittenByRun = null;
        $rowsNotTouched = null;
        if ($lastDatasetRunId !== null) {
            $rowsWrittenByRun = (int) (clone $query)->where('last_dataset_run_id', $lastDatasetRunId)->count();
            $rowsNotTouched = $rowCount - $rowsWrittenByRun;
        }

        $missingAdGroup = $rowCount - $nonNullAdGroup;
        $schemaOk = $rowCount === $distinctComposite && $missingAdGroup === 0;
        $reasons = [
            'exact resource scope is digital_asset_id + external_resource_id'
                .($adsCustomerId !== null ? ' + Ads customer_id' : ''),
            'UPSERT_CURRENT_STATE does not delete keys absent from the payload; leftovers in this resource slice fail current-run proof',
        ];

        if ($rowCount === 0) {
            $notes[] = 'zero keyword snapshot rows in exact resource scope; not keyword-grain proof';
            $reasons[] = 'zero in-scope rows';
        }
        if ($missingAdGroup > 0) {
            $notes[] = 'rows with null/empty ad_group_id remain in exact resource scope; those are not current-grain rows';
            $reasons[] = 'in-scope rows are missing ad_group_id';
        }
        if ($rowCount !== $distinctComposite) {
            $notes[] = 'COUNT(*) does not equal COUNT(DISTINCT customer|ad_group|criterion) in exact resource scope';
            $reasons[] = 'in-scope composite key count does not match row count';
        }
        if ($repeatedCount === 0) {
            $notes[] = 'provider payload has no criterion_id in more than one ad group in this resource slice; duplicate-preservation is unobserved, not disproven';
        }
        if ($lastDatasetRunId === null) {
            $reasons[] = 'no dataset run id; current-run coverage was not evaluated';
        } elseif ($rowsNotTouched !== null && $rowsNotTouched > 0) {
            $notes[] = 'in-scope rows were not last-written by this dataset run; historical leftovers are not current-grain proof';
            $reasons[] = 'in-scope leftovers were not touched by this dataset run';
        } elseif ($rowsWrittenByRun !== null && $rowsWrittenByRun === $rowCount && $rowCount > 0) {
            $reasons[] = 'every in-scope row was last-written by this dataset run';
        }

        $currentRunProven = $schemaOk
            && $lastDatasetRunId !== null
            && $rowCount > 0
            && $rowsNotTouched === 0
            && $rowsWrittenByRun === $rowCount;

        if ($currentRunProven) {
            $reasons[] = 'current-run grain proof is valid for this exact resource';
        } else {
            $reasons[] = 'current-run grain proof is not valid';
        }

        return [
            'table_present' => true,
            'ad_group_id_column_present' => true,
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => $externalResourceId,
            'ads_customer_id_hash' => $adsCustomerHash,
            'last_dataset_run_id' => $lastDatasetRunId,
            'row_count' => $rowCount,
            'distinct_composite_count' => $distinctComposite,
            'non_null_ad_group_id_count' => $nonNullAdGroup,
            'rows_missing_ad_group_id' => $missingAdGroup,
            'distinct_criterion_count' => $distinctCriterion,
            'criterion_ids_in_multiple_ad_groups' => $repeatedCount,
            'rows_last_written_by_dataset_run' => $rowsWrittenByRun,
            'rows_not_touched_by_dataset_run' => $rowsNotTouched,
            'repeated_criterion_samples' => $repeated->map(fn ($row): array => [
                'criterion_hash' => self::hashIdentifier((string) $row->criterion_id),
                'ad_group_count' => (int) $row->ad_group_count,
            ])->all(),
            'grain_matches_current_schema' => $schemaOk,
            'current_run_grain_proven' => $currentRunProven,
            'current_run_proof_reasons' => $reasons,
            'snapshot_write_mode' => 'UPSERT_CURRENT_STATE',
            'notes' => $notes,
        ];
    }

    public static function hashIdentifier(string $value): string
    {
        return 'sha256:'.substr(hash('sha256', $value), 0, self::HASH_PREFIX_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function requiredPositiveInt(array $scope, string $key): int
    {
        $value = $scope[$key] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('Keyword grain proof requires '.$key.' of the current binding.');
        }

        $id = (int) $value;
        if ($id < 1) {
            throw new InvalidArgumentException('Keyword grain proof requires '.$key.' of the current binding.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function optionalAdsCustomerId(array $scope): ?string
    {
        $value = $scope['ads_customer_id'] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function scopedQuery(int $digitalAssetId, int $externalResourceId, ?string $adsCustomerId): Builder
    {
        $query = DB::table(self::TABLE)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId);

        if ($adsCustomerId !== null) {
            $query->where('customer_id', $adsCustomerId);
        }

        return $query;
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $notes
     * @return array{
     *     table_present: bool,
     *     ad_group_id_column_present: bool,
     *     digital_asset_id: int,
     *     external_resource_id: int,
     *     ads_customer_id_hash: string|null,
     *     last_dataset_run_id: int|null,
     *     row_count: int,
     *     distinct_composite_count: int,
     *     non_null_ad_group_id_count: int,
     *     rows_missing_ad_group_id: int,
     *     distinct_criterion_count: int,
     *     criterion_ids_in_multiple_ad_groups: int,
     *     rows_last_written_by_dataset_run: int|null,
     *     rows_not_touched_by_dataset_run: int|null,
     *     repeated_criterion_samples: list<array{criterion_hash: string, ad_group_count: int}>,
     *     grain_matches_current_schema: bool,
     *     current_run_grain_proven: bool,
     *     current_run_proof_reasons: list<string>,
     *     snapshot_write_mode: string,
     *     notes: list<string>
     * }
     */
    private function emptyProof(
        bool $tablePresent,
        bool $columnPresent,
        int $digitalAssetId,
        int $externalResourceId,
        ?string $adsCustomerHash,
        ?int $lastDatasetRunId,
        array $reasons,
        array $notes,
    ): array {
        return [
            'table_present' => $tablePresent,
            'ad_group_id_column_present' => $columnPresent,
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => $externalResourceId,
            'ads_customer_id_hash' => $adsCustomerHash,
            'last_dataset_run_id' => $lastDatasetRunId,
            'row_count' => 0,
            'distinct_composite_count' => 0,
            'non_null_ad_group_id_count' => 0,
            'rows_missing_ad_group_id' => 0,
            'distinct_criterion_count' => 0,
            'criterion_ids_in_multiple_ad_groups' => 0,
            'rows_last_written_by_dataset_run' => $lastDatasetRunId === null ? null : 0,
            'rows_not_touched_by_dataset_run' => $lastDatasetRunId === null ? null : 0,
            'repeated_criterion_samples' => [],
            'grain_matches_current_schema' => false,
            'current_run_grain_proven' => false,
            'current_run_proof_reasons' => $reasons,
            'snapshot_write_mode' => 'UPSERT_CURRENT_STATE',
            'notes' => $notes,
        ];
    }
}
