<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\SearchQueryLibraryItem;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SearchQueryExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:1000'],
            'sector' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:all,active,candidate,excluded,archived,deleted'],
            'unassigned' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:newest,az,za'],
        ]);
        $query = SearchQueryLibraryItem::query()->libraryFilters($filters)
            ->with(['services.primaryName', 'sectors'])->withCount('sourceRecords');
        $sort = $filters['sort'] ?? 'newest';
        if (in_array($sort, ['az', 'za'], true)) {
            $query->orderBy('canonical_text', $sort === 'az' ? 'asc' : 'desc')->orderBy('id');
        } else {
            $query->orderByDesc('last_seen_at')->orderByDesc('id');
        }

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                __('query-list.query'), 'ID', __('query-list.sector'), __('query-list.services'),
                __('query-list.status'), __('query-list.source_count'), __('query-list.updated'),
            ], ';', '"', '');
            foreach ($query->lazy(500) as $item) {
                $row = [
                    $item->canonical_text,
                    (string) $item->id,
                    $item->sectors->pluck('name')->implode(', ') ?: (string) $item->sector,
                    $item->services->map(fn ($service) => $service->primaryName?->raw_label)->filter()->implode(', '),
                    __('query-list.status_'.($item->trashed() ? 'deleted' : $item->status)),
                    (string) $item->source_records_count,
                    $item->updated_at?->toIso8601String() ?? '',
                ];
                $row = array_map(static function (string $value): string {
                    return preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $value) ? "'".$value : $value;
                }, $row);
                if (fputcsv($output, $row, ';', '"', '') === false) {
                    throw new \RuntimeException('Query export write failed.');
                }
            }
            fclose($output);
        }, 'sorgular-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
