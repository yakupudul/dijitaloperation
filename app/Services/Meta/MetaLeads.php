<?php

namespace App\Services\Meta;

use App\Models\DigitalAsset;
use App\Models\MetaLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Meta lead quality (not a CRM): leads come from the Ads Manager lead export (CSV, UTF-16 / UTF-8, tab / ; / ,) —
 * only the lead id, date, campaign / ad / form are kept, never names, phones or e-mails. The operator marks each lead
 * uygun · randevu · satış · uygunsuz; counts per campaign feed Ölçümleme (CRM column) and the structure AI.
 */
final class MetaLeads
{
    public const int MAX_ROWS = 20000;

    /** @return array{created: int, updated: int} */
    public function import(DigitalAsset $asset, string $content): array
    {
        $rows = self::parse($content);
        if ($rows === []) {
            throw ValidationException::withMessages(['leads' => 'Dosyada lead bulunamadı (id sütunu gerekli).']);
        }
        $created = 0;
        $updated = 0;
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $ref = self::ref((string) ($row['id'] ?? $row['lead_id'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $fields = [
                'brand_id' => $asset->brand_id,
                'received_at' => self::date((string) ($row['created_time'] ?? $row['created_at'] ?? '')),
                'campaign_id' => self::ref((string) ($row['campaign_id'] ?? '')) ?: null,
                'campaign_name' => self::text((string) ($row['campaign_name'] ?? '')),
                'ad_name' => self::text((string) ($row['ad_name'] ?? '')),
                'form_id' => self::ref((string) ($row['form_id'] ?? '')) ?: null,
                'form_name' => self::text((string) ($row['form_name'] ?? '')),
            ];
            $lead = MetaLead::query()->firstOrNew(['digital_asset_id' => $asset->id, 'lead_ref' => $ref]);
            $lead->exists ? $updated++ : $created++;
            $lead->fill($fields)->save();
        }

        return ['created' => $created, 'updated' => $updated];
    }

    public function mark(DigitalAsset $asset, int $leadId, ?string $mark, ?User $user): MetaLead
    {
        if ($mark !== null && ! isset(MetaLead::MARKS[$mark])) {
            throw ValidationException::withMessages(['leads' => 'Geçersiz işaret.']);
        }
        $lead = MetaLead::query()->where('digital_asset_id', $asset->id)->findOrFail($leadId);
        $lead->forceFill(['mark' => $mark, 'marked_by' => $mark !== null ? $user?->id : null, 'marked_at' => $mark !== null ? now() : null])->save();

        return $lead;
    }

    /** @return Collection<int, MetaLead> newest first */
    public function list(DigitalAsset $asset, bool $unmarkedOnly = false, int $limit = 100): Collection
    {
        return MetaLead::query()->where('digital_asset_id', $asset->id)->when($unmarkedOnly, fn ($q) => $q->whereNull('mark'))
            ->orderByDesc('received_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Lead counts per campaign with the marks (all time).
     *
     * @return list<array{campaign: string, total: int, uygun: int, randevu: int, satis: int, uygunsuz: int, unmarked: int}>
     */
    public function byCampaign(DigitalAsset $asset): array
    {
        $out = [];
        foreach (MetaLead::query()->where('digital_asset_id', $asset->id)->selectRaw('campaign_name, mark, count(*) as n')->groupBy('campaign_name', 'mark')->get() as $row) {
            $name = (string) ($row->campaign_name ?: 'Kampanya bilinmiyor');
            $out[$name] ??= ['campaign' => $name, 'total' => 0, 'uygun' => 0, 'randevu' => 0, 'satis' => 0, 'uygunsuz' => 0, 'unmarked' => 0];
            $out[$name]['total'] += (int) $row->n;
            $out[$name][$row->mark !== null && isset(MetaLead::MARKS[$row->mark]) ? $row->mark : 'unmarked'] += (int) $row->n;
        }
        usort($out, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return array_values($out);
    }

    /**
     * Rows of a lead export keyed by lower-case header (contact columns are dropped by the caller never reading them).
     *
     * @return list<array<string, string>>
     */
    public static function parse(string $content): array
    {
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $content = (string) mb_convert_encoding(substr($content, 2), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '';
        $first = strtok(trim($content), "\r\n") ?: '';
        $delimiter = collect(["\t", ';', ','])->sortByDesc(fn (string $d): int => substr_count($first, $d))->first();
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, trim($content));
        rewind($stream);
        $header = array_map(fn ($h): string => strtolower(trim((string) $h, " \t\"")), fgetcsv($stream, null, $delimiter, '"', '') ?: []);
        $rows = [];
        if (in_array('id', $header, true) || in_array('lead_id', $header, true)) {
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                $row = [];
                foreach ($header as $i => $name) {
                    $row[$name] = trim((string) ($values[$i] ?? ''));
                }
                $rows[] = $row;
            }
        }
        fclose($stream);

        return $rows;
    }

    /** Meta ids come as "l:123" / "c:123" in exports. */
    private static function ref(string $value): string
    {
        return mb_substr(preg_replace('/^[a-z]+:/i', '', trim($value)) ?? '', 0, 64);
    }

    private static function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 300);
    }

    private static function date(string $value): ?CarbonImmutable
    {
        try {
            return trim($value) === '' ? null : CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            return null;
        }
    }
}
