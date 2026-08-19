<?php

namespace App\Services\Collection\Google;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse-side proof of the Google Ads keyword snapshot grain
 * (`customer_id × ad_group_id × criterion_id`). Never returns raw customer,
 * ad-group, or criterion identifiers.
 */
final class GoogleAdsKeywordGrainProof
{
    public const string TABLE = 'google_ads_keyword_snapshot';

    public const int HASH_PREFIX_LENGTH = 12;

    /**
     * @return array{
     *     table_present: bool,
     *     ad_group_id_column_present: bool,
     *     digital_asset_id: int|null,
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
     *     notes: list<string>
     * }
     */
    public function prove(?int $digitalAssetId = null, ?int $lastDatasetRunId = null, int $sampleLimit = 8): array
    {
        $notes = [];
        $tablePresent = Schema::hasTable(self::TABLE);
        $columnPresent = $tablePresent && Schema::hasColumn(self::TABLE, 'ad_group_id');

        if (! $tablePresent) {
            return $this->emptyProof(
                tablePresent: false,
                columnPresent: false,
                digitalAssetId: $digitalAssetId,
                lastDatasetRunId: $lastDatasetRunId,
                notes: ['google_ads_keyword_snapshot table is missing on this connection'],
            );
        }

        if (! $columnPresent) {
            return $this->emptyProof(
                tablePresent: true,
                columnPresent: false,
                digitalAssetId: $digitalAssetId,
                lastDatasetRunId: $lastDatasetRunId,
                notes: ['ad_group_id column is missing; current grain schema is not deployed'],
            );
        }

        $query = DB::table(self::TABLE);
        if ($digitalAssetId !== null) {
            $query->where('digital_asset_id', $digitalAssetId);
        }

        $rowCount = (int) (clone $query)->count();
        $distinctComposite = (int) (clone $query)->selectRaw(
            "COUNT(DISTINCT customer_id || '|' || ad_group_id || '|' || criterion_id) as aggregate",
        )->value('aggregate');
        $nonNullAdGroup = (int) (clone $query)->whereNotNull('ad_group_id')->where('ad_group_id', '!=', '')->count();
        $distinctCriterion = (int) (clone $query)->distinct()->count('criterion_id');

        $repeated = DB::table(self::TABLE)
            ->select('criterion_id', DB::raw('COUNT(DISTINCT ad_group_id) as ad_group_count'))
            ->when($digitalAssetId !== null, fn ($q) => $q->where('digital_asset_id', $digitalAssetId))
            ->groupBy('criterion_id')
            ->havingRaw('COUNT(DISTINCT ad_group_id) > 1')
            ->orderByDesc('ad_group_count')
            ->limit(max(1, $sampleLimit))
            ->get();

        $repeatedCount = (int) DB::table(self::TABLE)
            ->select('criterion_id')
            ->when($digitalAssetId !== null, fn ($q) => $q->where('digital_asset_id', $digitalAssetId))
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
        if ($rowCount === 0) {
            $notes[] = 'zero keyword snapshot rows in scope; schema path is present but this is not staging proof';
        }
        if ($missingAdGroup > 0) {
            $notes[] = 'rows with null/empty ad_group_id remain; those are not current-grain rows';
        }
        if ($rowCount !== $distinctComposite) {
            $notes[] = 'COUNT(*) does not equal COUNT(DISTINCT customer|ad_group|criterion); grain is collapsed or duplicated';
        }
        if ($repeatedCount === 0) {
            $notes[] = 'provider payload has no criterion_id in more than one ad group in this warehouse slice; duplicate-preservation is unobserved, not disproven';
        }
        if ($rowsNotTouched !== null && $rowsNotTouched > 0) {
            $notes[] = 'some warehouse rows were not last-written by this dataset run; treat them as historical leftovers, not current-grain proof';
        }

        return [
            'table_present' => true,
            'ad_group_id_column_present' => true,
            'digital_asset_id' => $digitalAssetId,
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
            'grain_matches_current_schema' => $rowCount === $distinctComposite && $missingAdGroup === 0,
            'notes' => $notes,
        ];
    }

    public static function hashIdentifier(string $value): string
    {
        return 'sha256:'.substr(hash('sha256', $value), 0, self::HASH_PREFIX_LENGTH);
    }

    /**
     * @param  list<string>  $notes
     * @return array{
     *     table_present: bool,
     *     ad_group_id_column_present: bool,
     *     digital_asset_id: int|null,
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
     *     notes: list<string>
     * }
     */
    private function emptyProof(
        bool $tablePresent,
        bool $columnPresent,
        ?int $digitalAssetId,
        ?int $lastDatasetRunId,
        array $notes,
    ): array {
        return [
            'table_present' => $tablePresent,
            'ad_group_id_column_present' => $columnPresent,
            'digital_asset_id' => $digitalAssetId,
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
            'notes' => $notes,
        ];
    }
}
