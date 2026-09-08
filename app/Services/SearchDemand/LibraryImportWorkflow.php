<?php

namespace App\Services\SearchDemand;

use App\Jobs\Async\LibraryImportJob;
use App\Models\CoreExternalResource;
use App\Models\SearchQueryLibraryImport;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LibraryImportWorkflow
{
    public function providerTable(string $source): array
    {
        return match ($source) {
            'google_ads' => ['google_ads_search_term_daily', 'search_term'],
            'search_console' => ['gsc_query_daily', 'query'],
            default => throw ValidationException::withMessages(['importSource' => 'Geçersiz hesap türü.']),
        };
    }

    public function resources(string $source): \Illuminate\Database\Eloquent\Builder
    {
        [$table] = $this->providerTable($source);

        return CoreExternalResource::query()->where('provider', 'google')->where('resource_type', $source)
            ->where('status', 'available')->whereHas('integration', fn ($q) => $q->where('status', 'active'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from($table)->whereColumn($table.'.external_resource_id', 'core_external_resources.id'));
    }

    public function validateScope(array $payload): array
    {
        $category = ServiceCategory::query()->where('code', $payload['sector'] ?? '')->first();
        if (! $category) {
            throw ValidationException::withMessages(['importSector' => 'Sektör seçimi zorunludur.']);
        }
        $ids = array_values(array_unique(array_map('intval', $payload['service_ids'] ?? [])));
        if (count($ids) > 200 || ServiceCatalogItem::query()->where('sector', $category->code)->where('status', 'active')->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['importServiceIds' => 'Seçilen hizmetler bu sektörde ve aktif olmalıdır.']);
        }

        return $ids;
    }

    public function queue(string $source, array $payload, ?User $actor): SearchQueryLibraryImport
    {
        abort_unless($actor?->is_active && $actor->can(Permissions::ACCESS_APP), 403);
        abort_unless(in_array($source, ['services', 'assignment', 'paste', 'csv', 'xlsx', 'google_ads', 'search_console'], true), 422);
        $payload['service_ids'] = $this->validateScope($payload);
        if (in_array($source, ['google_ads', 'search_console'], true)) {
            validator($payload, [
                'resource_ids' => ['required','array','min:1','max:20'], 'resource_ids.*' => ['integer'],
                'date_from' => ['required','date_format:Y-m-d'], 'date_to' => ['required','date_format:Y-m-d','after_or_equal:date_from'],
            ])->validate();
            $payload['resource_ids'] = array_values(array_unique(array_map('intval', $payload['resource_ids'])));
            if ($this->resources($source)->whereIn('id', $payload['resource_ids'])->count() !== count($payload['resource_ids'])) {
                throw ValidationException::withMessages(['resourceIds' => 'Kaydı bulunan geçerli hesapları seçin.']);
            }
        }
        if ($source === 'assignment') {
            validator($payload, ['query_ids' => ['required', 'array', 'min:1', 'max:500'], 'query_ids.*' => ['integer', 'exists:search_query_library_items,id']])->validate();
        }
        if (isset($payload['text']) && count(preg_split('/\R/u', trim($payload['text'])) ?: []) > ($source === 'services' ? 2000 : 10000)) {
            throw ValidationException::withMessages(['paste_text' => 'Hizmetlerde 2.000, sorgularda 10.000 satır sınırı aşıldı.']);
        }

        return DB::transaction(function () use ($source, $payload, $actor): SearchQueryLibraryImport {
            $import = SearchQueryLibraryImport::query()->create([
                'uuid' => (string) Str::uuid(), 'source_type' => $source,
                'original_filename' => $payload['filename'] ?? null,
                'status' => 'queued', 'created_by' => $actor->id, 'input_payload' => $payload,
            ]);
            LibraryImportJob::dispatch($import->id)->afterCommit();

            return $import;
        });
    }

    private function prepare(SearchQueryLibraryImport $import): array
    {
        $input = $import->input_payload;
        if (isset($input['prepared_path'])) {
            return json_decode(Storage::disk('local')->get($input['prepared_path']), true, 512, JSON_THROW_ON_ERROR);
        }
        if ($import->source_type === 'assignment') {
            $rows = array_map(fn ($id): array => ['item_id' => (int) $id], array_values(array_unique($input['query_ids'])));
        } elseif (isset($input['path'])) {
            $rows = app(TabularSearchQueryReader::class)->read(Storage::disk('local')->path($input['path']), $input['filename']);
        } elseif (in_array($import->source_type, ['google_ads', 'search_console'], true)) {
            [$table, $column] = $this->providerTable($import->source_type);
            $ids = $this->resources($import->source_type)->whereIn('id', $input['resource_ids'])->pluck('id')->all();
            if (count($ids) !== count($input['resource_ids'])) {
                throw ValidationException::withMessages(['resourceIds' => 'Seçilen hesaplardan biri artık kullanılabilir değil.']);
            }
            $rows = DB::table($table)->whereIn('external_resource_id', $ids)
                ->whereBetween('reporting_date', [$input['date_from'], $input['date_to']])
                ->select('external_resource_id', $column)->distinct()->orderBy('external_resource_id')->orderBy($column)->limit(10001)->get()
                ->map(fn ($r): array => [
                    'query' => $r->{$column},
                    'source_reference' => $table.':'.$r->external_resource_id.':'.hash('sha256', $r->{$column}),
                ])->all();
        } else {
            $rows = array_map(fn (string $line): array => ['query' => trim($line)], preg_split('/\R/u', $input['text'] ?? '') ?: []);
        }
        if (count($rows) > ($import->source_type === 'services' ? 2000 : 10000)) {
            throw ValidationException::withMessages(['rows' => 'Satır sınırı aşıldı. Dosyayı bölün veya hesapların tarih aralığını daraltın. Hiçbir satır bu işlemden kaydedilmedi.']);
        }
        $input['prepared_path'] = 'library-imports/'.$import->uuid.'.json';
        if (! Storage::disk('local')->put($input['prepared_path'], json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('Import storage write failed.');
        }
        $input['offset'] = 0;
        unset($input['text']);
        $import->update(['input_payload' => $input, 'total_rows' => count($rows)]);

        return $rows;
    }

    public function execute(int $id): void
    {
        $import = SearchQueryLibraryImport::query()->findOrFail($id);
        if (in_array($import->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }
        $actor = User::query()->find($import->created_by);
        abort_unless($actor?->is_active && $actor->can(Permissions::ACCESS_APP), 403);
        $serviceIds = $this->validateScope($import->input_payload);
        $import->update(['status' => 'running']);
        $rows = $this->prepare($import);
        $input = $import->input_payload;
        $offset = (int) ($input['offset'] ?? 0);
        $errors = $import->error_summary ? explode("\n", $import->error_summary) : [];
        foreach (array_slice($rows, $offset, 100) as $row) {
            try {
                if ($import->source_type === 'assignment') {
                    $this->assignRow((int) $row['item_id'], $input['sector'], $serviceIds, $actor);
                    $import->accepted_rows++;
                } else {
                    $text = trim((string) ($row['query'] ?? $row['search_term'] ?? $row['keyword'] ?? $row['sorgu'] ?? $row['arama_terimi'] ?? ''));
                    if ($text === '') {
                        $import->skipped_rows++;
                    } else {
                        if (mb_strlen($text) > ($import->source_type === 'services' ? 50000 : 1000)) {
                            throw ValidationException::withMessages(['text' => 'Satır uzunluk sınırını aşıyor. Sorgularda 1.000, hizmet ve kelimelerinde 50.000 karakter sınırı var.']);
                        }
                        $created = $import->source_type === 'services'
                            ? $this->serviceRow($text, $input['sector'], $actor)
                            : $this->queryRow($text, $row, $import, $serviceIds, $actor);
                        $created ? $import->accepted_rows++ : $import->skipped_rows++;
                    }
                }
            } catch (Throwable $exception) {
                $import->failed_rows++;
                if (count($errors) < 20) {
                    $errors[] = 'Satır '.($offset + 1).': '.($exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : 'Kayıt işlenemedi; tekrar deneyin.');
                }
                report($exception);
            }
            $offset++;
            $input['offset'] = $offset;
            $import->fill(['input_payload' => $input, 'error_summary' => $errors ? implode("\n", $errors) : null])->save();
        }
        if ($offset < count($rows)) {
            LibraryImportJob::dispatch($id)->delay(now()->addSeconds(1));

            return;
        }
        $import->update([
            'status' => $import->failed_rows ? ($import->accepted_rows + $import->skipped_rows > 0 ? 'partial' : 'failed') : 'completed',
            'completed_at' => now(),
        ]);
        $this->cleanup($import);
    }

    private function serviceRow(string $text, string $sector, User $actor): bool
    {
        [$label, $words] = array_pad(explode('|', $text, 2), 2, '');
        if (mb_strlen(trim($label)) > 255) {
            throw ValidationException::withMessages(['label' => 'Hizmet adı en fazla 255 karakter olabilir.']);
        }

        return DB::transaction(function () use ($label, $words, $sector, $actor): bool {
            $result = app(ServiceCatalogService::class)->resolveOrCreate(trim($label), $sector, actor: $actor);
            if ($result['service']->sector !== $sector) {
                throw ValidationException::withMessages(['sector' => 'Hizmet başka sektörde mevcut; Hizmetler ekranından düzenleyin.']);
            }
            if (trim($words) !== '') {
                $existing = $result['service']->matchingKeywords()->pluck('label')->implode("\n");
                app(ServiceKeywordService::class)->replace($result['service'], $existing."\n".$words);
            }

            return $result['created'];
        });
    }

    private function queryRow(string $text, array $row, SearchQueryLibraryImport $import, array $ids, User $actor): bool
    {
        $result = app(SearchQueryLibraryService::class)->store($text, $import->source_type, [
            'sector' => $import->input_payload['sector'], 'language_code' => 'tr', 'import' => $import,
            'source_reference' => $row['source_reference'] ?? null,
            'period_start' => $import->input_payload['date_from'] ?? null,
            'period_end' => $import->input_payload['date_to'] ?? null,
        ], $actor);
        $matched = app(ServiceKeywordService::class)->matches($result['item']->canonical_text, $ids);
        DB::transaction(function () use ($result, $matched): void {
            $item = $result['item']->newQuery()->whereKey($result['item']->id)->lockForUpdate()->firstOrFail();
            foreach ($matched as $id) {
                if (! $item->services()->whereKey($id)->exists()) {
                    $item->services()->attach($id, ['is_primary' => ! $item->services()->wherePivot('is_primary', true)->exists(), 'provenance' => 'keyword_match']);
                }
            }
        });

        return $result['created'];
    }

    private function assignRow(int $id, string $sector, array $services, User $actor): void
    {
        DB::transaction(function () use ($id, $sector, $services, $actor): void {
            $item = \App\Models\SearchQueryLibraryItem::query()->lockForUpdate()->findOrFail($id);
            $category = ServiceCategory::query()->where('code', $sector)->firstOrFail();
            $item->sectors()->syncWithoutDetaching([$category->id]);
            if (blank($item->sector)) {
                $item->update(['sector' => $sector, 'updated_by' => $actor->id]);
            }
            foreach ($services as $serviceId) {
                if (! $item->services()->whereKey($serviceId)->exists()) {
                    $item->services()->attach($serviceId, [
                        'is_primary' => ! $item->services()->wherePivot('is_primary', true)->exists(), 'provenance' => 'operator',
                    ]);
                }
            }
        });
    }

    private function cleanup(SearchQueryLibraryImport $import): void
    {
        $input = $import->input_payload;
        foreach (['path', 'prepared_path'] as $key) {
            if (isset($input[$key])) {
                Storage::disk('local')->delete($input[$key]);
                unset($input[$key]);
            }
        }
        unset($input['text']);
        $import->update(['input_payload' => $input]);
    }

    public function fail(int $id, ?Throwable $exception = null): void
    {
        $import = SearchQueryLibraryImport::query()->find($id);
        if (! $import || in_array($import->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }
        $message = $exception instanceof ValidationException
            ? collect($exception->errors())->flatten()->implode(' ')
            : 'İşlem kesildi. Kaydedilmiş satırlar korunuyor; aynı kaynağı yeniden içe aktarabilirsiniz.';
        $import->update(['status' => 'failed', 'error_summary' => $message, 'completed_at' => now()]);
        $this->cleanup($import);
    }
}
