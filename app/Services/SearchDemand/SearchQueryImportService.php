<?php

namespace App\Services\SearchDemand;

use App\Models\SearchQueryLibraryImport;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

final class SearchQueryImportService
{
    public function __construct(
        private readonly TabularSearchQueryReader $reader,
        private readonly SearchQueryLibraryService $library,
        private readonly ServiceCatalogService $catalog,
    ) {}

    /** @param array<string, mixed> $defaults */
    public function import(string $path, string $filename, string $sourceType, array $defaults = [], ?User $actor = null): SearchQueryLibraryImport
    {
        $import = SearchQueryLibraryImport::query()->create([
            'uuid' => (string) Str::uuid(),
            'source_type' => $sourceType,
            'original_filename' => $filename,
            'status' => 'processing',
            'created_by' => $actor?->id,
        ]);

        $accepted = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        try {
            $rows = $this->reader->read($path, $filename);
            foreach ($rows as $index => $row) {
                $query = $this->first($row, ['query', 'search_term', 'keyword', 'sorgu', 'arama_terimi']);
                if ($query === null) {
                    $skipped++;
                    continue;
                }

                try {
                    $serviceId = $defaults['service_catalog_item_id'] ?? null;
                    $serviceLabel = $this->first($row, ['service', 'hizmet']);
                    if ($serviceLabel !== null) {
                        $serviceId = $this->catalog->resolveOrCreate(
                            $serviceLabel,
                            $this->first($row, ['sector', 'sektor']) ?? ($defaults['sector'] ?? null),
                            actor: $actor,
                            provenance: 'import',
                        )['service']->id;
                    }

                    $this->library->store($query, $sourceType, array_merge($defaults, [
                        'import' => $import,
                        'row_number' => $index + 2,
                        'service_catalog_item_id' => $serviceId,
                        'language_code' => $this->first($row, ['language', 'language_code', 'dil']) ?? ($defaults['language_code'] ?? null),
                        'market_code' => $this->first($row, ['market', 'market_code', 'country', 'ulke']) ?? ($defaults['market_code'] ?? null),
                        'sector' => $this->first($row, ['sector', 'sektor']) ?? ($defaults['sector'] ?? null),
                        'demand_family' => $this->first($row, ['demand_family', 'talep_ailesi']),
                        'country_code' => $this->first($row, ['country_code', 'ulke_kodu']),
                        'city_name' => $this->first($row, ['city', 'city_name', 'sehir']),
                        'district_name' => $this->first($row, ['district', 'district_name', 'ilce']),
                        'impressions' => $this->first($row, ['impressions', 'gosterim', 'gosterimler']),
                        'clicks' => $this->first($row, ['clicks', 'tiklama', 'tiklamalar']),
                        'conversions' => $this->first($row, ['conversions', 'donusum', 'donusumler']),
                        'cost' => $this->first($row, ['cost', 'maliyet']),
                        'search_volume' => $this->first($row, ['search_volume', 'volume', 'hacim', 'arama_hacmi']),
                        'cpc' => $this->first($row, ['cpc', 'ort_cpc']),
                        'competition' => $this->first($row, ['competition', 'rekabet']),
                        'period_start' => $this->first($row, ['period_start', 'baslangic']),
                        'period_end' => $this->first($row, ['period_end', 'bitis']),
                        'raw_payload' => $row,
                    ]), $actor);
                    $accepted++;
                } catch (Throwable $exception) {
                    $failed++;
                    if (count($errors) < 10) {
                        $errors[] = 'Satır '.($index + 2).': '.$exception->getMessage();
                    }
                }
            }

            $import->fill([
                'status' => $failed > 0 ? 'partial' : 'completed',
                'total_rows' => count($rows),
                'accepted_rows' => $accepted,
                'skipped_rows' => $skipped,
                'failed_rows' => $failed,
                'error_summary' => $errors === [] ? null : implode("\n", $errors),
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $import->fill([
                'status' => 'failed',
                'failed_rows' => 1,
                'error_summary' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();
        }

        return $import->refresh();
    }

    /** @param array<string, mixed> $row @param list<string> $keys */
    private function first(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
