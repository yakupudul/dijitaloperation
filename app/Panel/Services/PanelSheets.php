<?php

namespace App\Panel\Services;

use App\Panel\Models\PanelEvent;
use App\Panel\Models\PanelImport;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelRequest;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelSources;
use App\Panel\Support\PanelStages;
use App\Panel\Support\PhoneKey;
use App\Panel\Support\SheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * A brand's own list (people it already had, or its call log) comes in from Excel: the columns are matched once, the
 * user sees what will happen before anything is written, the same phone updates the person instead of doubling them,
 * and one click takes the whole file back.
 */
final class PanelSheets
{
    public const int UNDO_DAYS = 7;

    /** field => [label, header words that mean it] */
    public const array FIELDS = [
        'name' => ['Ad soyad', ['ad soyad', 'adı soyadı', 'ad', 'isim', 'isim soyisim', 'müşteri', 'hasta', 'kişi', 'name', 'full name']],
        'phone' => ['Telefon', ['telefon', 'tel', 'gsm', 'cep', 'cep telefonu', 'numara', 'telefon numarası', 'arayan', 'phone', 'mobile']],
        'email' => ['E-posta', ['e-posta', 'eposta', 'mail', 'e-mail', 'email']],
        'interest' => ['İlgilendiği konu', ['konu', 'ilgilendiği', 'ilgilendiği konu', 'hizmet', 'işlem', 'ürün', 'talep']],
        'source' => ['Kaynak', ['kaynak', 'nereden', 'nereden geldi', 'kanal', 'source']],
        'city' => ['Şehir', ['şehir', 'il', 'city']],
        'district' => ['İlçe', ['ilçe', 'semt']],
        'note' => ['Not', ['not', 'notlar', 'açıklama', 'mesaj', 'yorum', 'note']],
        'date' => ['Tarih', ['tarih', 'kayıt tarihi', 'arama tarihi', 'tarih saat', 'date', 'zaman']],
    ];

    public function __construct(private readonly PanelContext $context) {}

    public function keep(int $brandId, int $userId, string $kind, string $tempPath, string $name): PanelImport
    {
        return $this->context->system(function () use ($brandId, $userId, $kind, $tempPath, $name): PanelImport {
            foreach (PanelImport::query()->where('brand_id', $brandId)->whereIn('kind', [PanelImport::LEADS, PanelImport::CALLS])->where('status', 'uploaded')->get() as $old) {
                File::delete($old->file());
                $old->delete();
            }
            $import = new PanelImport;
            $import->brand_id = $brandId;
            $import->fill(['kind' => $kind, 'status' => 'uploaded', 'file_name' => mb_substr($name, 0, 190), 'size' => (int) filesize($tempPath), 'received_bytes' => (int) filesize($tempPath), 'created_by' => $userId])->save();
            File::ensureDirectoryExists(dirname($import->file()), 0700);
            File::copy($tempPath, $import->file());

            return $import;
        });
    }

    /** @return list<list<string>> */
    public function rows(PanelImport $import): array
    {
        return SheetReader::read($import->file(), $import->file_name);
    }

    /**
     * @param  list<string>  $head
     * @return array<int, string> column index => field ('' = not taken)
     */
    public function guess(array $head): array
    {
        $taken = [];
        $map = [];
        foreach ($head as $index => $title) {
            $title = Str::of($title)->lower()->squish()->toString();
            $title = strtr($title, ['i̇' => 'i']);
            $map[$index] = '';
            foreach (self::FIELDS as $field => [, $words]) {
                if (! isset($taken[$field]) && in_array($title, $words, true)) {
                    $map[$index] = $field;
                    $taken[$field] = true;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * One row read through the mapping.
     *
     * @param  list<string>  $row
     * @param  array<int, string>  $map
     * @return array<string, string>
     */
    private function pick(array $row, array $map): array
    {
        $data = [];
        foreach ($map as $index => $field) {
            if ($field !== '' && isset(self::FIELDS[$field]) && ($row[(int) $index] ?? '') !== '') {
                $data[$field] = trim(($data[$field] ?? '').' '.$row[(int) $index]);
            }
        }
        if (isset($data['phone']) && preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $data['phone']) === 1) {
            // Excel wrote the number as 5.32E+9.
            $data['phone'] = number_format((float) $data['phone'], 0, '', '');
        }
        if (isset($data['phone']) && preg_match('/^5\d{9}$/', preg_replace('/\D+/', '', $data['phone']) ?? '') === 1) {
            $data['phone'] = '0'.preg_replace('/\D+/', '', $data['phone']);
        }

        return $data;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
                // Excel's day count since 1899-12-30, in the brand's local time.
                return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'Europe/Istanbul')->addSeconds((int) round((float) $value * 86400))->utc();
            }
            foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
                $date = CarbonImmutable::createFromFormat($format, $value, 'Europe/Istanbul');
                if ($date !== false && $date->format($format) === $value) {
                    return (str_contains($format, 'H') ? $date : $date->setTime(12, 0))->utc();
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * What the file would do, without writing anything.
     *
     * @param  list<list<string>>  $rows
     * @param  array<int, string>  $map
     * @return array{new: int, updated: int, bad: int, badRows: list<array{row: int, why: string}>}
     */
    public function preview(int $brandId, array $rows, array $map): array
    {
        $result = ['new' => 0, 'updated' => 0, 'bad' => 0, 'badRows' => []];
        $known = array_flip($this->context->system(fn (): array => PanelPerson::query()->where('brand_id', $brandId)->whereNotNull('phone_key')->pluck('phone_key')->all()));
        foreach (array_slice($rows, 1) as $n => $row) {
            $data = $this->pick($row, $map);
            $key = PhoneKey::of($data['phone'] ?? null);
            if ($key === null) {
                $result['bad']++;
                if (count($result['badRows']) < 8) {
                    $result['badRows'][] = ['row' => $n + 2, 'why' => ($data['phone'] ?? '') === '' ? 'Telefon boş' : 'Telefon geçersiz: '.mb_substr($data['phone'], 0, 30)];
                }

                continue;
            }
            $result[isset($known[$key]) ? 'updated' : 'new']++;
            $known[$key] = true;
        }

        return $result;
    }

    /**
     * Writes the file's rows. A new phone becomes a person; a known phone keeps its record and only gains what was
     * empty. A call list adds one call to the person's history per row.
     *
     * @param  array<int, string>  $map
     */
    public function apply(PanelImport $import, array $map, string $defaultSource, int $actorId): PanelImport
    {
        $rows = $this->rows($import);
        $calls = $import->kind === PanelImport::CALLS;
        $stats = ['new' => 0, 'updated' => 0, 'bad' => 0, 'requests' => 0];
        $sources = array_flip(array_map(fn (string $label): string => mb_strtolower($label), PanelSources::labels()));
        $this->context->system(fn () => DB::transaction(function () use ($import, $rows, $map, $calls, $defaultSource, $actorId, &$stats, $sources): void {
            foreach (array_slice($rows, 1) as $row) {
                $data = $this->pick($row, $map);
                $key = PhoneKey::of($data['phone'] ?? null);
                if ($key === null) {
                    $stats['bad']++;

                    continue;
                }
                $at = $this->date($data['date'] ?? null);
                $source = $calls ? 'call' : PanelSources::normalize($sources[mb_strtolower((string) ($data['source'] ?? ''))] ?? $defaultSource);
                $person = PanelPerson::query()->where('brand_id', $import->brand_id)->where('phone_key', $key)->first();
                $isNew = $person === null;
                if ($isNew) {
                    $person = new PanelPerson;
                    $person->brand_id = $import->brand_id;
                    $person->fill(['name' => ($data['name'] ?? '') ?: $data['phone'], 'phone' => $data['phone'], 'stage' => PanelStages::NEW, 'source' => $source, 'last_activity_at' => $at ?? now()]);
                    $person->import_id = $import->id;
                    if ($at !== null) {
                        $person->created_at = $at;
                    }
                    $stats['new']++;
                } else {
                    $stats['updated']++;
                    if (($data['name'] ?? '') !== '' && ($person->name === $person->phone || $person->name === 'İsimsiz')) {
                        $person->name = $data['name'];
                    }
                }
                foreach (['email', 'interest', 'city', 'district'] as $field) {
                    if (($data[$field] ?? '') !== '' && empty($person->{$field})) {
                        $person->{$field} = mb_substr($data[$field], 0, 190);
                    }
                }
                $person->save();

                if ($isNew || $calls) {
                    $request = new PanelRequest;
                    $request->brand_id = $import->brand_id;
                    $request->fill(['person_id' => $person->id, 'source' => $source, 'interest' => $data['interest'] ?? null, 'message' => $data['note'] ?? null, 'received_at' => $at ?? now()]);
                    $request->import_id = $import->id;
                    // A list brought in from the past is history, not a request waiting for an answer.
                    $request->handled_at = now();
                    $request->save();
                    $stats['requests']++;
                }
                if ($isNew || $calls || ($data['note'] ?? '') !== '') {
                    $event = new PanelEvent;
                    $event->brand_id = $import->brand_id;
                    $event->fill([
                        'person_id' => $person->id, 'type' => $calls ? PanelEvent::CALL : ($isNew ? PanelEvent::REQUEST : PanelEvent::NOTE), 'body' => ($data['note'] ?? '') ?: null,
                        'meta' => ['source' => $source, 'interest' => $data['interest'] ?? null, 'first' => $isNew, 'import' => $import->id], 'panel_user_id' => $actorId, 'occurred_at' => $at ?? now(),
                    ])->save();
                }
            }
            $import->forceFill(['status' => 'completed', 'stats' => $stats, 'finished_at' => now()])->save();
        }));
        File::delete($import->file());

        return $import;
    }

    public function canUndo(PanelImport $import): bool
    {
        return in_array($import->kind, [PanelImport::LEADS, PanelImport::CALLS], true) && $import->status === 'completed'
            && $import->finished_at !== null && $import->finished_at->gt(now()->subDays(self::UNDO_DAYS));
    }

    /** Takes one file back: the people it created (with everything on them) and the lines it added to known people. */
    public function undo(PanelImport $import): void
    {
        if (! $this->canUndo($import)) {
            return;
        }
        $this->context->system(fn () => DB::transaction(function () use ($import): void {
            PanelPerson::query()->where('brand_id', $import->brand_id)->where('import_id', $import->id)->delete();
            PanelRequest::query()->where('brand_id', $import->brand_id)->where('import_id', $import->id)->delete();
            PanelEvent::query()->where('brand_id', $import->brand_id)->where('meta->import', $import->id)->delete();
            $import->forceFill(['status' => 'undone'])->save();
        }));
    }
}
