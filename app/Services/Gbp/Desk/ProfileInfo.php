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
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Profil bilgileri (Onarım Faz 4, ADR-080): regular hours, phone, primary category, yes / no attributes, website and
 * appointment links of every profile. The nightly pass prepares what can be filled from the brand's own data as
 * İşletme Profili suggestions (`gbp_profile_fields`), which wait on the Onarım masası for the Admin's approval:
 *  - no hours → the hours all of the brand's other profiles share (at least two, all the same);
 *  - no website link → this branch's own page on the brand's site (several profiles), else the brand's site;
 *  - no appointment link → the brand's own Turkish appointment page (URL or title with "randevu" / "appointment").
 * A row is sent only while the field is still empty on the profile (a stale row is refused).
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
            $common = self::commonHours($siblings);
            if ($common !== null) {
                $rows = self::rows($common);
                $items[] = $item('regular_hours', $rows, '', self::hoursText($rows),
                    'Profilde çalışma saati yok; markanın diğer şubelerinin hepsi aynı saatleri kullanıyor, o saatler önerildi.', 1);
            }
        }
        $site = DigitalAsset::query()->operational()->where('brand_id', $location->brand_id)->where('type', 'website')->orderBy('id')->first();
        if ($site !== null && blank($snapshot['website'] ?? null) && filled($site->primary_url) && str_starts_with((string) $site->primary_url, 'https://')) {
            $branch = $this->branchPageUrl($location, $snapshot);
            $items[] = $branch !== null
                ? $item('website_uri', $branch, '', $branch, 'Profilde web sitesi bağlantısı yok; bu şubenin sitedeki kendi sayfası önerildi.', 1)
                : $item('website_uri', (string) $site->primary_url, '', (string) $site->primary_url, 'Profilde web sitesi bağlantısı yok; markanın sitesi önerildi.', 1);
        }
        if ($site !== null && ! $this->hasAppointmentLink($location)) {
            $page = Page::query()->where('website_asset_id', $site->id)->where('url', 'like', 'https://%')
                ->where(fn ($q) => $q->whereNull('language')->orWhere('language', 'tr'))
                ->where(fn ($q) => $q->where('url', 'like', '%randevu%')->orWhere('url', 'like', '%appointment%')->orWhere('title', 'like', '%Randevu%'))
                ->where(fn ($q) => $q->whereNull('is_indexable')->orWhere('is_indexable', true))
                ->orderByRaw('length(url)')->limit(50)->get()
                ->first(fn (Page $p): bool => self::isAppointmentPage((string) $p->url));
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
            ->whereIn('target_id', $assetIds)->where('action_type', self::TYPE)->where('status', Suggestion::OPEN)->get();
    }

    /**
     * Sends one prepared row to Google (Admin). The row only fills a gap, so the profile is read again first (latest
     * collection; the appointment link live) and a field that is no longer empty is refused.
     *
     * @throws ValidationException
     */
    public function send(User $user, Suggestion $suggestion): void
    {
        $location = DigitalAsset::query()->findOrFail((int) $suggestion->target_id);
        $field = (string) data_get($suggestion->action, 'field');
        if (! $this->stillEmpty($location, $field)) {
            throw ValidationException::withMessages(['fields' => 'Profilde bu alan artık dolu; öneri eskidi.']);
        }
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

    /**
     * A booking page sits at the top of the site (optionally under the Turkish language folder) and its own address names
     * it: "/randevu-olustur/", "/tr/randevu/". A page under another language ("/en/appointment/") is not the Turkish
     * profile's booking page. A blog or Q&A page that only mentions appointments
     * ("/soru-cevap/kontrol-randevulari-ne-siklikla-yapilir/") is not one.
     */
    public static function isAppointmentPage(string $url): bool
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));
        if ($segments !== [] && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/i', $segments[0]) === 1) {
            if (strtolower($segments[0]) !== 'tr') {
                return false;
            }
            array_shift($segments);
        }

        return count($segments) === 1 && preg_match('/(randevu|appointment|booking)/i', $segments[0]) === 1 && substr_count($segments[0], '-') <= 2;
    }

    /**
     * Hours every sibling with hours shares, when at least two siblings have hours and they all agree; null otherwise
     * (no plurality vote: one branch's different hours make the guess unsafe).
     *
     * @param  Collection<int, array<string, mixed>>  $siblings
     * @return list<mixed>|null
     */
    public static function commonHours(Collection $siblings): ?array
    {
        $withHours = $siblings->map(fn (array $s): array => array_values((array) ($s['regular_hours'] ?? [])))->filter()->values();
        if ($withHours->count() < 2 || $withHours->unique(fn (array $periods): string => GbpWriter::hoursKey($periods))->count() !== 1) {
            return null;
        }

        return $withHours->first();
    }

    /**
     * This branch's own page on the brand's site (with the profile UTM tags) when the brand has several profiles and
     * the branch page match found one; null = the home page is right (single profile or no branch page yet).
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function branchPageUrl(DigitalAsset $location, array $snapshot): ?string
    {
        if ($this->desk->locations((int) $location->brand_id)->count() <= 1) {
            return null;
        }
        $state = app(BranchPages::class)->states(collect([$location]), [(int) $location->id => $snapshot])[$location->id] ?? null;
        $page = ($state['state'] ?? null) === 'unlinked' ? $state['page'] : null;
        if ($page === null || ! str_starts_with((string) $page->url, 'https://')) {
            return null;
        }
        $url = (string) $page->url;

        return $url.(str_contains($url, '?') ? '&' : '?').BranchPages::UTM;
    }

    /** Whether the field the row fills is still empty on the profile now. */
    private function stillEmpty(DigitalAsset $location, string $field): bool
    {
        $snapshot = $this->desk->snapshots([(int) $location->id])[$location->id] ?? null;

        return match ($field) {
            'regular_hours' => $snapshot !== null && (array) ($snapshot['regular_hours'] ?? []) === [],
            'website_uri' => $snapshot !== null && blank($snapshot['website'] ?? null),
            'appointment_url' => ! $this->hasAppointmentLink($location),
            default => true,
        };
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
