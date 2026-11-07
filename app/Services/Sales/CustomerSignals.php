<?php

namespace App\Services\Sales;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\BrandReport;
use App\Models\BrandSignal;
use App\Models\BrandTarget;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Müşteri dönemi (Moximu büyüme sistemi, Aşama 3; yakup 2026-11-07): what a customer needs from us this month, read
 * from the tables that already exist. Only four risk signals, no score and no "temperature" (rejected before): the
 * month's payment did not come after the 15th; no contact for 30 days; last month's report was not sent; demand fell
 * two months in a row. Each lands in the Gelen kutusu as a line of work and leaves with Yapıldı or Sonra. The setup
 * list is read from the brand's connections, never typed; "rapor gönderildi" is one tick per month.
 */
final class CustomerSignals
{
    public const array SIGNALS = [
        'odeme_gecikti' => 'Ödeme gelmedi',
        'temas_yok' => '30 gündür temas yok',
        'rapor_yok' => 'Aylık rapor gönderilmedi',
        'talep_dustu' => 'Talep iki aydır düşüyor',
    ];

    /** What "Yapıldı" means on each signal. */
    public const array DONE = [
        'odeme_gecikti' => 'Hatırlattım',
        'temas_yok' => 'Temas kurdum',
        'rapor_yok' => 'Raporu gönderdim',
        'talep_dustu' => 'Baktım',
    ];

    public const int CONTACT_DAYS = 30;

    /** "Ödeme 15'inden sonra gelmedi": an unpaid month is a signal after the 15th (and after its own due day). */
    public const int PAYMENT_DAY = 15;

    /** Last month's report goes out in the first week; from this day on its absence is a signal. */
    public const int REPORT_DAY = 8;

    /** A handled signal stays quiet this many days (0 = for good, for that period). */
    private const array QUIET_DAYS = ['odeme_gecikti' => 7, 'temas_yok' => 7, 'rapor_yok' => 0, 'talep_dustu' => 30];

    /** Setup items per agreed service (the default package when the agreement names none). */
    private const array SETUP = [
        'seo_gbp' => ['site', 'gsc', 'ga4', 'gbp'], 'seo' => ['site', 'gsc', 'ga4'], 'gbp' => ['gbp'], 'google_ads' => ['site', 'ga4', 'google_ads'],
        'meta_ads' => ['site', 'ga4', 'meta_ads'], 'web' => ['site', 'gsc', 'ga4'], 'other' => ['site'],
    ];

    public const array SETUP_LABELS = ['site' => 'Web sitesi', 'gsc' => 'Search Console', 'ga4' => 'GA4', 'gbp' => 'İşletme Profili', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta reklam hesabı'];

    public function __construct(private Agreements $agreements, private SalesLeads $leads) {}

    /**
     * The brand's signals as the data says them, before what was done about them.
     *
     * @param  array<string, mixed>|null  $summary  Agreements::summary() when the caller has it
     * @return list<array{key: string, period: string, label: string, detail: string, done: string}>
     */
    public function raw(Brand $brand, ?array $summary = null): array
    {
        $summary ??= $this->agreements->summary($brand);
        $today = CarbonImmutable::now(Agreements::TZ)->startOfDay();
        $out = [];

        /** @var Agreement|null $agreement */
        $agreement = $summary['agreement'];
        $late = collect($summary['payments'])->concat(collect($summary['one_time'])->flatMap(fn (Agreement $a) => $a->payments()->get()))
            ->filter(fn (AgreementPayment $p): bool => $p->isOverdue() && $today->gt(CarbonImmutable::parse($p->period.'-01', Agreements::TZ)->setDay(self::PAYMENT_DAY)))
            ->sortBy('period')->values();
        if ($late->isNotEmpty()) {
            $first = $late->first();
            $sum = (int) $late->sum('amount');
            $out[] = ['key' => 'odeme_gecikti', 'period' => (string) $first->period,
                'label' => 'Ödeme gelmedi: '.($late->count() > 1 ? $late->count().' ay · ' : $first->periodLabel().' · ').number_format($sum, 0, ',', '.').' ₺',
                'detail' => $first->daysLate().' gün gecikti; vade her ayın '.(int) ($first->agreement?->due_day ?? 1).'. günü. Hatırlatınca "Yapıldı" de; ödenince Müşteriler\'de tik at.',
                'done' => self::DONE['odeme_gecikti']];
        }

        $last = $this->lastContactAt($brand);
        if ($last !== null && $last->lt($today->subDays(self::CONTACT_DAYS))) {
            $days = (int) $last->startOfDay()->diffInDays($today);
            $out[] = ['key' => 'temas_yok', 'period' => $today->format('Y-m'), 'label' => $days.' gündür temas yok',
                'detail' => 'Son temas '.$last->timezone(Agreements::TZ)->locale('tr')->isoFormat('D MMMM').'. Bir mesaj ya da arama yeter; "Yapıldı" temas olarak kaydedilir.',
                'done' => self::DONE['temas_yok']];
        }

        $previous = $today->startOfMonth()->subMonth();
        if ($agreement !== null && $agreement->isActive() && $agreement->kind !== 'one_time' && $today->day >= self::REPORT_DAY
            && $agreement->starts_on !== null && $agreement->starts_on->lte($previous->endOfMonth()) && $this->report($brand, $previous->format('Y-m')) === null) {
            $out[] = ['key' => 'rapor_yok', 'period' => $previous->format('Y-m'), 'label' => $previous->locale('tr')->isoFormat('MMMM').' raporu gönderilmedi',
                'detail' => 'Aylık rapor ayın ilk haftasında gider. Gönderince "Yapıldı" de; o ay için işaretlenir.', 'done' => self::DONE['rapor_yok']];
        }

        $demand = $this->demand($brand);
        if ($demand !== null && $demand['falling']) {
            $out[] = ['key' => 'talep_dustu', 'period' => $demand['months'][2]['month'], 'label' => 'Talep iki aydır düşüyor',
                'detail' => implode(' → ', array_map(fn (array $m): string => CarbonImmutable::parse($m['month'].'-01')->locale('tr')->isoFormat('MMM').' '.$this->n($m['total']), $demand['months'])).'. Sebebine bak (sezon, reklam bütçesi, sıralama); "Yapıldı" bir ay susturur.',
                'done' => self::DONE['talep_dustu']];
        }

        return $out;
    }

    /**
     * The signals still waiting for a hand: not put off, not handled within their quiet days.
     *
     * @param  array<string, mixed>|null  $summary
     * @return list<array{key: string, period: string, label: string, detail: string, done: string}>
     */
    public function open(Brand $brand, ?array $summary = null): array
    {
        $raw = $this->raw($brand, $summary);
        if ($raw === []) {
            return [];
        }
        $states = BrandSignal::query()->where('brand_id', $brand->id)->whereIn('signal', array_column($raw, 'key'))->get();
        $now = now();

        return array_values(array_filter($raw, function (array $signal) use ($states, $now): bool {
            $state = $states->first(fn (BrandSignal $s): bool => $s->signal === $signal['key'] && $s->period === $signal['period']);
            if ($state === null) {
                return true;
            }
            if ($state->snoozed_until !== null && $state->snoozed_until->gt($now)) {
                return false;
            }
            if ($state->handled_at !== null) {
                $quiet = self::QUIET_DAYS[$signal['key']] ?? 0;

                return $quiet > 0 && $state->handled_at->lt($now->copy()->subDays($quiet));
            }

            return true;
        }));
    }

    /**
     * Every served brand with an open signal, for the Gelen kutusu.
     *
     * @return Collection<int, array{brand: Brand, signals: list<array{key: string, period: string, label: string, detail: string, done: string}>}>
     */
    public function all(): Collection
    {
        $out = collect();
        foreach (Brand::query()->with('customer')->operational()->orderBy('name')->get() as $brand) {
            $signals = $this->open($brand);
            if ($signals !== []) {
                $out->push(['brand' => $brand, 'signals' => $signals]);
            }
        }

        return $out;
    }

    /** "Yapıldı" on a signal: the thing was done, written on the customer's record; returns what to tell the operator. */
    public function handle(Brand $brand, string $signal, string $period, ?User $user = null, ?string $note = null): string
    {
        $lead = $brand->customer !== null ? $this->leads->forCustomer($brand->customer, $brand) : null;
        $message = match ($signal) {
            'rapor_yok' => $this->markReportSent($brand, $period, $user, null, $note)->monthLabel().' raporu gönderildi olarak işlendi.',
            'temas_yok' => 'Temas kaydedildi; 30 günlük sayaç yeniden başladı.',
            'odeme_gecikti' => 'Hatırlatma kaydedildi; ödenmezse bir hafta sonra yeniden düşer.',
            'talep_dustu' => 'Not alındı; sinyal bir ay susar.',
            default => 'Yapıldı.',
        };
        if ($lead !== null && $signal !== 'rapor_yok') {
            $body = match ($signal) {
                'temas_yok' => 'Müşteriyle temas kuruldu (gelen kutusu).',
                'odeme_gecikti' => 'Ödeme hatırlatıldı ('.CarbonImmutable::parse($period.'-01')->locale('tr')->isoFormat('MMMM YYYY').').',
                default => 'Talep düşüşüne bakıldı.',
            }.($note !== null && trim($note) !== '' ? ' '.trim($note) : '');
            $this->leads->event($lead, $signal === 'temas_yok' ? 'call' : 'note', $body, meta: ['by' => $user?->id, 'signal' => $signal]);
            if ($signal === 'temas_yok' || $signal === 'odeme_gecikti') {
                $lead->forceFill(['last_contact_at' => now()])->save();
            }
        }
        BrandSignal::query()->updateOrCreate(['brand_id' => $brand->id, 'signal' => $signal, 'period' => $period],
            ['handled_at' => now(), 'snoozed_until' => null, 'note' => $note !== null ? mb_substr($note, 0, 500) : null, 'user_id' => $user?->id]);

        return $message;
    }

    /** "Sonra": the signal comes back on that day. */
    public function snooze(Brand $brand, string $signal, string $period, CarbonImmutable $until, ?User $user = null): void
    {
        BrandSignal::query()->updateOrCreate(['brand_id' => $brand->id, 'signal' => $signal, 'period' => $period],
            ['snoozed_until' => $until->utc(), 'handled_at' => null, 'user_id' => $user?->id]);
    }

    public function report(Brand $brand, string $month): ?BrandReport
    {
        return BrandReport::query()->where('brand_id', $brand->id)->where('month', $month)->first();
    }

    /** The month's report went to the customer (one tick; sending itself happens outside MoxDOP). */
    public function markReportSent(Brand $brand, string $month, ?User $user = null, ?string $channel = null, ?string $note = null): BrandReport
    {
        $report = BrandReport::query()->updateOrCreate(['brand_id' => $brand->id, 'month' => $month],
            ['sent_at' => now(), 'channel' => $channel, 'note' => $note !== null ? mb_substr($note, 0, 500) : null, 'sent_by' => $user?->id]);
        if ($brand->customer !== null) {
            $lead = $this->leads->forCustomer($brand->customer, $brand);
            $this->leads->event($lead, 'report', $report->monthLabel().' raporu gönderildi.', meta: ['by' => $user?->id, 'month' => $month], fingerprint: 'report:'.$brand->id.':'.$month);
            $lead->forceFill(['last_contact_at' => now()])->save();
        }

        return $report;
    }

    /** The tick taken back (marked by mistake). */
    public function unmarkReport(Brand $brand, string $month): void
    {
        BrandReport::query()->where('brand_id', $brand->id)->where('month', $month)->delete();
    }

    /** The last time we and the customer spoke (in Turkey time): a message either way, a logged call or meeting, a report sent. */
    public function lastContactAt(Brand $brand): ?CarbonImmutable
    {
        $customerId = $brand->customer_id;
        $times = [];
        $records = SalesLead::query()->where('brand_id', $brand->id)->when($customerId !== null, fn ($q) => $q->orWhere('customer_id', $customerId))->get(['last_contact_at', 'last_incoming_at']);
        foreach ($records as $record) {
            $times[] = $record->last_contact_at;
            $times[] = $record->last_incoming_at;
        }
        if ($customerId !== null) {
            $times[] = WhatsAppConversation::query()->where('customer_id', $customerId)->max('last_message_at');
        }
        $times[] = BrandReport::query()->where('brand_id', $brand->id)->max('sent_at');
        $latest = null;
        foreach ($times as $time) {
            if ($time === null) {
                continue;
            }
            $at = CarbonImmutable::parse($time);
            if ($latest === null || $at->gt($latest)) {
                $latest = $at;
            }
        }

        return $latest?->timezone(Agreements::TZ);
    }

    /**
     * The setup list, read from the brand's connections (an active asset of the type, with an active binding for the
     * data sources), for the services the agreement names.
     *
     * @return array{items: list<array{key: string, label: string, done: bool}>, done: int, total: int}
     */
    public function setup(Brand $brand, ?Agreement $agreement = null): array
    {
        $services = array_values(array_filter((array) ($agreement?->services ?? []), fn ($s): bool => is_string($s) && isset(self::SETUP[$s])));
        if ($services === []) {
            $services = ['seo_gbp'];
        }
        $keys = [];
        foreach ($services as $service) {
            foreach (self::SETUP[$service] as $key) {
                $keys[$key] = true;
            }
        }
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->where('status', 'active')->get(['id', 'type']);
        $bindings = CoreAssetBinding::query()->whereIn('digital_asset_id', $assets->pluck('id'))->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('capability')->unique();
        $has = fn (string $type): bool => $assets->contains('type', $type);
        $bound = fn (string $capability): bool => $bindings->contains($capability);
        $state = [
            'site' => $has('website'), 'gsc' => $bound('search_console'), 'ga4' => $bound('ga4'),
            'gbp' => $has('google_business_profile') && $bound('google_business_profile'), 'google_ads' => $has('google_ads') && $bound('google_ads'), 'meta_ads' => $has('meta_ads') && $bound('meta_ads'),
        ];
        $items = [];
        foreach (array_keys(self::SETUP_LABELS) as $key) {
            if (isset($keys[$key])) {
                $items[] = ['key' => $key, 'label' => self::SETUP_LABELS[$key], 'done' => (bool) ($state[$key] ?? false)];
            }
        }

        return ['items' => $items, 'done' => count(array_filter($items, fn (array $i): bool => $i['done'])), 'total' => count($items)];
    }

    /** Which month of the agreement this is (1 on the month it starts). */
    public static function monthNo(?Agreement $agreement): ?int
    {
        if ($agreement?->starts_on === null) {
            return null;
        }
        $start = CarbonImmutable::parse($agreement->starts_on->toDateString(), Agreements::TZ)->startOfMonth();
        $now = CarbonImmutable::now(Agreements::TZ)->startOfMonth();

        return $now->lt($start) ? 0 : (int) $start->diffInMonths($now) + 1;
    }

    /**
     * The last three full months of demand from the brand's goal basis (refreshed nightly), and whether it fell twice.
     *
     * @return array{months: list<array{month: string, total: float}>, falling: bool}|null
     */
    public function demand(Brand $brand): ?array
    {
        $months = (array) data_get(BrandTarget::query()->where('brand_id', $brand->id)->value('basis'), 'months', []);
        $months = array_values(array_filter($months, fn ($m): bool => is_array($m) && isset($m['month'])));
        if (count($months) < 3) {
            return null;
        }
        $months = array_slice($months, -3);
        $rows = array_map(fn (array $m): array => ['month' => (string) $m['month'], 'total' => (float) ($m['total'] ?? 0)], $months);
        $falling = $rows[0]['total'] > 0 && $rows[1]['total'] < $rows[0]['total'] && $rows[2]['total'] < $rows[1]['total'];

        return ['months' => $rows, 'falling' => $falling];
    }

    private function n(float $value): string
    {
        return number_format($value, $value === floor($value) ? 0 : 1, ',', '.');
    }
}
