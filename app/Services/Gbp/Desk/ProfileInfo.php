<?php

namespace App\Services\Gbp\Desk;

use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\GbpWriter;
use App\Services\Gbp\GbpCategoryCatalog;
use App\Services\Gbp\GbpSuggestions;
use App\Services\Integrations\Google\GoogleApiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Profil bilgileri (Onarım Faz 4, ADR-080): regular hours, phone, primary category, yes / no attributes, website and
 * appointment links of every profile. The nightly pass prepares what can be filled from the brand's own data as
 * İşletme Profili suggestions (`gbp_profile_fields`), which wait on the Onarım masası for the Admin's approval:
 *  - no hours → the hours most of the brand's other profiles use;
 *  - no website link → the brand's own site;
 *  - no appointment link → the brand's own appointment page (URL or title with "randevu" / "appointment").
 * Phone, primary category and attributes are never guessed: the operator picks them on "Bilgiler".
 */
final class ProfileInfo
{
    public const string TYPE = 'gbp_profile_fields';

    public const array FIELD_LABELS = [
        'regular_hours' => 'Çalışma saatleri', 'website_uri' => 'Web sitesi bağlantısı', 'appointment_url' => 'Randevu bağlantısı',
        'phone' => 'Telefon', 'primary_category' => 'Birincil kategori', 'attributes' => 'Özellikler',
    ];

    public function __construct(
        private readonly GbpDesk $desk,
        private readonly GbpSuggestions $suggestions,
    ) {}

    /**
     * Prepares the fillable gaps of every profile (or one brand's) as suggestions.
     *
     * @return array{profiles: int, prepared: int}
     */
    public function prepareAll(?int $brandId = null): array
    {
        $locations = $this->desk->locations($brandId);
        $snapshots = $this->desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $prepared = 0;
        foreach ($locations as $location) {
            if (! isset($snapshots[$location->id])) {
                continue;
            }
            $siblings = $locations->where('brand_id', $location->brand_id)->where('id', '!=', $location->id)
                ->map(fn (DigitalAsset $s): array => $snapshots[$s->id] ?? [])->filter()->values();
            try {
                $items = $this->items($location, $snapshots[$location->id], $siblings);
                $prepared += $this->suggestions->replaceGroup($location, 'fields', $items);
            } catch (Throwable $error) {
                report($error);
            }
        }

        return ['profiles' => $locations->count(), 'prepared' => $prepared];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  Collection<int, array<string, mixed>>  $siblings
     * @return list<array{key: string, title: string, reason: string, priority: int, evidence: array<mixed>, action_type: string, action: array<string, mixed>}>
     */
    public function items(DigitalAsset $location, array $snapshot, Collection $siblings): array
    {
        $items = [];
        $item = fn (string $field, mixed $value, string $current, string $proposed, string $reason, int $priority): array => [
            'key' => 'fields:'.$field, 'title' => self::FIELD_LABELS[$field].' eksik', 'reason' => $reason, 'priority' => $priority,
            'evidence' => [['alan' => self::FIELD_LABELS[$field], 'şimdi' => $current ?: '—', 'önerilen' => $proposed]],
            'action_type' => self::TYPE, 'action' => ['field' => $field, 'fields' => [$field => $value], 'current' => $current, 'proposed' => $proposed, 'location' => GbpDesk::shortName((string) $location->name)],
        ];

        if ((array) ($snapshot['regular_hours'] ?? []) === []) {
            $common = $siblings->map(fn (array $s): array => array_values((array) ($s['regular_hours'] ?? [])))->filter()
                ->groupBy(fn (array $periods): string => GbpWriter::hoursKey($periods))->sortByDesc(fn (Collection $g): int => $g->count())->first()?->first();
            if (is_array($common)) {
                $rows = self::rows($common);
                $items[] = $item('regular_hours', $rows, '', self::hoursText($rows),
                    'Profilde çalışma saati yok; markanın diğer şubelerinin çoğunun saatleri önerildi.', 1);
            }
        }
        $site = DigitalAsset::query()->operational()->where('brand_id', $location->brand_id)->where('type', 'website')->orderBy('id')->first();
        if ($site !== null && blank($snapshot['website'] ?? null) && filled($site->primary_url) && str_starts_with((string) $site->primary_url, 'https://')) {
            $items[] = $item('website_uri', (string) $site->primary_url, '', (string) $site->primary_url, 'Profilde web sitesi bağlantısı yok; markanın sitesi önerildi.', 1);
        }
        if ($site !== null && ! $this->hasAppointmentLink($location)) {
            $page = Page::query()->where('website_asset_id', $site->id)->where('url', 'like', 'https://%')
                ->where(fn ($q) => $q->where('url', 'like', '%randevu%')->orWhere('url', 'like', '%appointment%')->orWhere('title', 'like', '%Randevu%'))
                ->where(fn ($q) => $q->whereNull('is_indexable')->orWhere('is_indexable', true))
                ->orderByRaw('length(url)')->first();
            if ($page !== null) {
                $items[] = $item('appointment_url', (string) $page->url, '', (string) $page->url, 'Profilde "Randevu al" bağlantısı yok; sitedeki randevu sayfası önerildi.', 2);
            }
        }

        return $items;
    }

    /**
     * Open prepared rows (for the desk).
     *
     * @param  list<int>  $assetIds
     * @return Collection<int, Suggestion>
     */
    public function open(array $assetIds): Collection
    {
        return Suggestion::query()->where('channel', GbpSuggestions::CHANNEL)->where('target_type', GbpSuggestions::TARGET)
            ->whereIn('target_id', $assetIds)->where('action_type', self::TYPE)->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])->get();
    }

    /** Sends one prepared row to Google (Admin). */
    public function send(User $user, Suggestion $suggestion): void
    {
        $location = DigitalAsset::query()->findOrFail((int) $suggestion->target_id);
        $field = (string) data_get($suggestion->action, 'field');
        app(ExternalWriteService::class)->requestProfileFields($user, $location, (array) data_get($suggestion->action, 'fields', []),
            self::FIELD_LABELS[$field] ?? 'Profil bilgisi', $suggestion);
    }

    /**
     * The profile's yes / no attributes Google offers, with what the profile says now (latest collection).
     *
     * @return list<array{name: string, label: string, group: string, value: ?bool}>
     */
    public static function attributes(int $assetId): array
    {
        $row = DB::table('gbp_attribute_snapshots')->where('digital_asset_id', $assetId)->latest('id')->first(['attributes', 'available_attributes']);
        if ($row === null) {
            return [];
        }
        $current = [];
        foreach ((array) (GoogleAdsAdvisorInputCollector::decode($row->attributes)['attributes'] ?? []) as $attribute) {
            if (is_array($attribute) && isset($attribute['name'])) {
                $value = ((array) ($attribute['values'] ?? []))[0] ?? null;
                $current[(string) $attribute['name']] = is_bool($value) ? $value : null;
            }
        }
        $out = [];
        foreach (GoogleAdsAdvisorInputCollector::decode($row->available_attributes) as $meta) {
            if (! is_array($meta) || ($meta['valueType'] ?? '') !== 'BOOL' || (bool) ($meta['deprecated'] ?? false) || blank($meta['parent'] ?? null)) {
                continue;
            }
            $name = (string) $meta['parent'];
            $out[] = ['name' => $name, 'label' => (string) ($meta['displayName'] ?? $name), 'group' => (string) ($meta['groupDisplayName'] ?? ''), 'value' => $current[$name] ?? null];
        }
        usort($out, fn (array $a, array $b): int => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);

        return $out;
    }

    /**
     * Google periods as day rows for the form ("MONDAY" => open / close).
     *
     * @param  array<int, mixed>  $periods
     * @return list<array{day: string, open: string, close: string}>
     */
    public static function rows(array $periods): array
    {
        $time = static fn (mixed $t): string => sprintf('%02d:%02d', (int) data_get($t, 'hours', 0), (int) data_get($t, 'minutes', 0));

        return array_values(array_map(fn (mixed $p): array => ['day' => (string) data_get($p, 'openDay'), 'open' => $time(data_get($p, 'openTime')),
            'close' => $time(data_get($p, 'closeTime'))], array_filter($periods, 'is_array')));
    }

    /** @param  list<array{day: string, open: string, close: string}>  $rows */
    public static function hoursText(array $rows): string
    {
        $days = ['MONDAY' => 'Pzt', 'TUESDAY' => 'Sal', 'WEDNESDAY' => 'Çar', 'THURSDAY' => 'Per', 'FRIDAY' => 'Cum', 'SATURDAY' => 'Cmt', 'SUNDAY' => 'Paz'];
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['day']][] = $row['open'].'–'.$row['close'];
        }

        return implode(', ', array_map(fn (string $day, string $label): string => $label.' '.(isset($byDay[$day]) ? implode(' / ', $byDay[$day]) : 'kapalı'), array_keys($days), $days));
    }

    /** Whether the profile already has an appointment link (live read; when Google does not answer, assume yes). */
    private function hasAppointmentLink(DigitalAsset $location): bool
    {
        try {
            [$integration, $name] = app(GbpCategoryCatalog::class)->location((int) $location->id);
            $response = app(GoogleApiClient::class)->get($integration, 'https://mybusinessplaceactions.googleapis.com/v1/'.$name.'/placeActionLinks', [], 'google_business_profile');
            if (! $response->successful()) {
                return true;
            }

            return collect((array) $response->json('placeActionLinks'))->contains(fn (mixed $l): bool => is_array($l) && ($l['placeActionType'] ?? '') === 'APPOINTMENT');
        } catch (Throwable) {
            return true;
        }
    }
}
