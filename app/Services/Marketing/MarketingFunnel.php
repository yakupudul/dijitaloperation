<?php

namespace App\Services\Marketing;

use App\Models\AgencySetting;
use App\Models\Agreement;
use App\Models\Customer;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesMonthReport;
use App\Support\Sales\LeadKinds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pazarlama hunisi (Moximu büyüme sistemi, Aşama 4; yakup 2026-11-07): which source brought how many applications,
 * conversations, offers, customers and monthly ₺. One funnel read from the sales records (kaynak = where they heard
 * of us, else the channel they wrote on), filtered by Tür (default: health, the site's positioning), against the
 * recorded target of 25 health applications a month channel by channel. Non-health records are a separate line and
 * do not count toward the target. Nothing is typed twice: the record carries its source, the agreement its fee.
 */
final class MarketingFunnel
{
    public const string TZ = 'Europe/Istanbul';

    /** The channels in the order of the target table; unknown counts as an application but carries no target, outbound never counts. */
    public const array CHANNELS = [
        'meta' => 'Meta reklamı', 'web' => 'Web (arama)', 'google_ads' => 'Google Ads', 'gbp' => 'İşletme Profili',
        'social' => 'Sosyal (organik)', 'referral' => 'Tavsiye / AFC', 'unknown' => 'Bilinmiyor', 'outbound' => 'Biz ulaştık',
    ];

    /** The recorded goal (2026-11): 25 health applications a month. */
    public const array DEFAULT_TARGETS = ['meta' => 12, 'web' => 8, 'google_ads' => 3, 'gbp' => 2];

    /** The two built-in Tür filters; every kind of the list is one as well. */
    public const array FILTERS = ['saglik' => 'Sağlık', 'hepsi' => 'Hepsi'];

    public const array SPANS = [1 => 'Ay', 3 => 'Son 3 ay'];

    /** The steps of the funnel, each counted as "got at least this far". */
    public const array STEPS = ['applications' => 'Başvuru', 'talked' => 'Konuşma', 'offered' => 'Teklif', 'won' => 'Müşteri'];

    public function __construct(private SalesMonthReport $report) {}

    /** The record's marketing channel: where they heard of us wins over the channel they wrote on. */
    public static function channel(SalesLead $lead): string
    {
        $heard = (string) $lead->heard_from;
        $source = (string) $lead->source;

        return match (true) {
            $heard === 'meta_ads', $source === 'meta_form' => 'meta',
            $heard === 'google_ads' => 'google_ads',
            $heard === 'maps' => 'gbp',
            $heard === 'google' => 'web',
            in_array($heard, ['instagram', 'youtube'], true), in_array($source, ['instagram', 'facebook'], true) => 'social',
            in_array($heard, ['referral', 'afc'], true), in_array($source, ['referral', 'afc'], true) => 'referral',
            $source === 'form' => 'web',
            $source === 'link' => 'outbound',
            default => 'unknown',
        };
    }

    /** @return array<string, int> the monthly target per channel (channels without one: 0) */
    public function targets(): array
    {
        $stored = AgencySetting::query()->value('marketing_targets');
        $stored = is_array($stored) ? $stored : [];
        $out = [];
        foreach (array_keys(self::CHANNELS) as $key) {
            $out[$key] = max(0, (int) ($stored[$key] ?? self::DEFAULT_TARGETS[$key] ?? 0));
        }

        return $out;
    }

    /** @param  array<string, int|string>  $targets */
    public function saveTargets(array $targets): void
    {
        $clean = [];
        foreach (array_keys(self::CHANNELS) as $key) {
            // The channels that never count toward the goal (unknown source, our own outreach) carry no target.
            $clean[$key] = in_array($key, ['unknown', 'outbound'], true) ? 0 : max(0, min(999, (int) ($targets[$key] ?? 0)));
        }
        $settings = AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        $settings->forceFill(['marketing_targets' => $clean])->save();
    }

    /**
     * The funnel of the records opened in the span ending with $month (a cohort: what became of what came in).
     *
     * @return array{
     *     month: string, from: CarbonImmutable, to: CarbonImmutable, span: int, filter: string, filter_label: string,
     *     rows: list<array{key: string, label: string, applications: int, talked: int, offered: int, won: int, revenue: int, target: int, counts: bool}>,
     *     totals: array{applications: int, talked: int, offered: int, won: int, revenue: int, target: int, counted: int},
     *     excluded: array{applications: int, won: int, revenue: int, label: string}, won_names: list<array{id: int, title: string, fee: int, channel: string}>
     * }
     */
    public function funnel(CarbonImmutable $month, string $filter = 'saglik', int $span = 1): array
    {
        $span = isset(self::SPANS[$span]) ? $span : 1;
        $to = $month->setTimezone(self::TZ)->endOfMonth();
        $from = $to->startOfMonth()->subMonths($span - 1)->startOfMonth();
        $all = $this->cohort($from, $to);
        $filter = $this->filterKey($filter);
        $in = $all->filter(fn (SalesLead $l): bool => $this->matches($l, $filter));
        $out = $all->reject(fn (SalesLead $l): bool => $this->matches($l, $filter));
        $fees = $this->fees($all);
        $targets = $this->targets();

        $rows = [];
        foreach (self::CHANNELS as $key => $label) {
            $leads = $in->filter(fn (SalesLead $l): bool => self::channel($l) === $key);
            $won = $leads->filter(fn (SalesLead $l): bool => self::isWon($l));
            $rows[] = [
                'key' => $key, 'label' => $label,
                'applications' => $leads->count(),
                'talked' => $leads->filter(fn (SalesLead $l): bool => SalesMonthReport::reached($l, 'talking'))->count(),
                'offered' => $leads->filter(fn (SalesLead $l): bool => SalesMonthReport::reached($l, 'priced'))->count(),
                'won' => $won->count(),
                'revenue' => (int) $won->sum(fn (SalesLead $l): int => $fees[$l->id] ?? 0),
                'target' => ($targets[$key] ?? 0) * $span,
                // An application whose source is not marked is still an application (2026-11-08); only our own outreach is not.
                'counts' => $key !== 'outbound',
            ];
        }
        $totals = ['applications' => 0, 'talked' => 0, 'offered' => 0, 'won' => 0, 'revenue' => 0, 'target' => 0, 'counted' => 0];
        foreach ($rows as $row) {
            foreach (['applications', 'talked', 'offered', 'won', 'revenue', 'target'] as $k) {
                $totals[$k] += $row[$k];
            }
            if ($row['counts']) {
                $totals['counted'] += $row['applications'];
            }
        }
        $outWon = $out->filter(fn (SalesLead $l): bool => self::isWon($l));
        $wonNames = $in->filter(fn (SalesLead $l): bool => self::isWon($l))->sortByDesc('won_at')
            ->map(fn (SalesLead $l): array => ['id' => (int) $l->id, 'title' => $l->title(), 'fee' => $fees[$l->id] ?? 0, 'channel' => self::CHANNELS[self::channel($l)]])->values()->all();

        return [
            'month' => $to->format('Y-m'), 'from' => $from, 'to' => $to, 'span' => $span, 'filter' => $filter, 'filter_label' => $this->filterLabel($filter),
            'rows' => $rows, 'totals' => $totals,
            'excluded' => ['applications' => $out->count(), 'won' => $outWon->count(), 'revenue' => (int) $outWon->sum(fn (SalesLead $l): int => $fees[$l->id] ?? 0),
                'label' => $filter === 'saglik' ? 'Sağlık dışı ve türü boş' : ($filter === 'hepsi' ? '' : 'Diğer türler')],
            'won_names' => $wonNames,
        ];
    }

    /**
     * "Bu ay ne çalıştı": the month in plain sentences for the 1st-of-month read (and the Pazarlama screen).
     *
     * @return list<string>
     */
    public function summary(CarbonImmutable $month, string $filter = 'saglik'): array
    {
        $f = $this->funnel($month, $filter, 1);
        $t = $f['totals'];
        $n = fn (int $v): string => number_format($v, 0, ',', '.');
        $label = $month->setTimezone(self::TZ)->locale('tr')->isoFormat('MMMM');
        $lines = [];

        $byChannel = [];
        foreach ($f['rows'] as $row) {
            if ($row['counts'] && ($row['target'] > 0 || $row['applications'] > 0)) {
                $byChannel[] = $row['label'].' '.$row['applications'].($row['target'] > 0 ? '/'.$row['target'] : '');
            }
        }
        $lines[] = $label.' ayında '.$f['filter_label'].' başvurusu: '.$t['counted'].($t['target'] > 0 ? ' / '.$t['target'].' hedef' : '').($byChannel !== [] ? ' ('.implode(', ', $byChannel).')' : '').'.';

        $counting = array_values(array_filter($f['rows'], fn (array $r): bool => $r['counts'] && $r['key'] !== 'unknown'));
        usort($counting, fn (array $a, array $b): int => $b['applications'] <=> $a['applications']);
        if (($counting[0]['applications'] ?? 0) > 0) {
            $lines[] = 'En çok başvuru '.$counting[0]['label'].' kanalından geldi ('.$counting[0]['applications'].').';
        }
        $winners = array_values(array_filter($f['rows'], fn (array $r): bool => $r['won'] > 0));
        usort($winners, fn (array $a, array $b): int => [$b['revenue'], $b['won']] <=> [$a['revenue'], $a['won']]);
        if ($winners !== []) {
            $lines[] = 'Müşteri getiren: '.implode(', ', array_map(fn (array $r): string => $r['label'].' ('.$r['won'].' müşteri'.($r['revenue'] > 0 ? ', '.$n($r['revenue']).' ₺/ay' : '').')', $winners)).'.';
        } else {
            $lines[] = 'Bu ay açılan kayıtlardan müşteri çıkmadı.';
        }
        $lines[] = $t['offered'] > 0
            ? 'Teklif → müşteri: '.$t['offered'].' tekliften '.$t['won'].' müşteri (%'.(int) round($t['won'] * 100 / $t['offered']).').'
            : 'Teklif verilmedi.';
        $silent = array_values(array_filter($f['rows'], fn (array $r): bool => $r['counts'] && $r['target'] > 0 && $r['applications'] === 0));
        if ($silent !== []) {
            $lines[] = 'Hiç başvuru getirmeyen: '.implode(', ', array_map(fn (array $r): string => $r['label'].' (hedef '.$r['target'].')', $silent)).'.';
        }
        if ($f['excluded']['applications'] > 0 && $f['excluded']['label'] !== '') {
            $lines[] = $f['excluded']['label'].': '.$f['excluded']['applications'].' başvuru, hedefe sayılmadı'.($f['excluded']['won'] > 0 ? '; '.$f['excluded']['won'].' müşteri oldu'.($f['excluded']['revenue'] > 0 ? ' ('.$n($f['excluded']['revenue']).' ₺/ay)' : '') : '').'.';
        }

        $report = $this->report->month($month);
        if ($report['lost'] > 0) {
            $reasons = [];
            foreach (array_slice($report['lost_reasons'], 0, 3, true) as $key => $count) {
                $reasons[] = mb_strtolower(SalesLead::LOST_REASONS[$key] ?? $key).' '.$count;
            }
            $lines[] = 'Kaybedilen: '.$report['lost'].($reasons !== [] ? ' ('.implode(', ', $reasons).')' : '').'.';
        }
        if ($report['reply_hours'] !== null) {
            $minutes = (int) round($report['reply_hours'] * 60);
            $lines[] = 'İlk cevap: '.($minutes < 60 ? $minutes.' dakika' : rtrim(rtrim(number_format($report['reply_hours'], 1, ',', '.'), '0'), ',').' saat').' (hedef 15 dakika, mesai içi).';
        }

        return $lines;
    }

    /**
     * The month so far against the target: how many applications the days gone by should have brought (null for a
     * month that is over, where the whole target applies).
     *
     * @return array{expected: ?int, day: int, days: int}
     */
    public function pace(CarbonImmutable $month, int $target): array
    {
        $now = CarbonImmutable::now(self::TZ);
        $month = $month->setTimezone(self::TZ)->startOfMonth();
        $days = $month->daysInMonth;
        if ($month->format('Y-m') !== $now->format('Y-m')) {
            return ['expected' => null, 'day' => $days, 'days' => $days];
        }

        return ['expected' => (int) round($target * $now->day / $days), 'day' => $now->day, 'days' => $days];
    }

    /**
     * The last $months months, oldest first: applications per channel, customers and ₺ (the bars on the overview).
     *
     * @return list<array{month: string, label: string, counted: int, target: int, won: int, revenue: int, by: array<string, int>}>
     */
    public function trend(CarbonImmutable $month, string $filter = 'saglik', int $months = 6): array
    {
        $out = [];
        $month = $month->setTimezone(self::TZ)->startOfMonth();
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = $month->subMonths($i);
            $f = $this->funnel($m, $filter, 1);
            $by = [];
            foreach ($f['rows'] as $row) {
                $by[$row['key']] = $row['applications'];
            }
            $out[] = ['month' => $m->format('Y-m'), 'label' => $m->locale('tr')->isoFormat('MMM'), 'counted' => $f['totals']['counted'], 'target' => $f['totals']['target'],
                'won' => $f['totals']['won'], 'revenue' => $f['totals']['revenue'], 'by' => $by];
        }

        return $out;
    }

    /**
     * The records opened in the span (the same cohort and Tür filter as the funnel), newest first, optionally of one
     * channel; records whose source is unknown carry a suggestion read from their first WhatsApp message.
     *
     * @return list<array{id: int, title: string, kind: ?string, channel: string, channel_label: string, stage: string, stage_label: string, won: bool, created_at: CarbonImmutable,
     *     suggestion: ?array{key: string, label: string, why: string}}>
     */
    public function records(CarbonImmutable $month, string $filter = 'saglik', int $span = 1, ?string $channel = null): array
    {
        $span = isset(self::SPANS[$span]) ? $span : 1;
        $to = $month->setTimezone(self::TZ)->endOfMonth();
        $from = $to->startOfMonth()->subMonths($span - 1)->startOfMonth();
        $filter = $this->filterKey($filter);
        $leads = $this->cohort($from, $to)->filter(fn (SalesLead $l): bool => $this->matches($l, $filter))
            ->when($channel !== null, fn (Collection $c) => $c->filter(fn (SalesLead $l): bool => self::channel($l) === $channel))
            ->sortByDesc('created_at')->values();
        $suggestions = $this->suggestions($leads->filter(fn (SalesLead $l): bool => self::channel($l) === 'unknown'));

        return $leads->map(fn (SalesLead $l): array => [
            'id' => (int) $l->id, 'title' => $l->title(), 'kind' => LeadKinds::label($l->kind), 'channel' => self::channel($l), 'channel_label' => self::CHANNELS[self::channel($l)],
            'stage' => (string) $l->stage, 'stage_label' => SalesLead::STAGES[$l->stage] ?? (string) $l->stage, 'won' => self::isWon($l),
            'created_at' => CarbonImmutable::instance($l->created_at)->setTimezone(self::TZ), 'suggestion' => $suggestions[$l->id] ?? null,
        ])->all();
    }

    /** Words in a first message that tell where the person heard of us (Meta's own Click-to-WhatsApp greeting first). */
    public const array HINTS = [
        'meta_ads' => '/daha fazla bilgi alabilir miyim|more info(rmation)? (on|about) this|reklam[ıi]n[ıi]z[ıi]? gördüm|reklamdan|sponsorlu/u',
        'instagram' => '/instagram|insta\b/u',
        'maps' => '/haritalar|haritada|google maps/u',
        'google' => '/google[\'’]?(da|dan|de|den)\b|internette|internetten|arama(da|dan) (çıktı|gördüm)/u',
        'referral' => '/tavsiye|önerdi|referans|sizi (bana )?söyledi/u',
    ];

    /**
     * @param  Collection<int, SalesLead>  $leads
     * @return array<int, array{key: string, label: string, why: string}>
     */
    private function suggestions(Collection $leads): array
    {
        $conversations = $leads->pluck('conversation_id', 'id')->filter();
        if ($conversations->isEmpty()) {
            return [];
        }
        $first = [];
        foreach (WhatsAppMessage::query()->whereIn('conversation_id', $conversations->values())->where('direction', 'incoming')->orderBy('sent_at')->orderBy('id')
            ->limit(2000)->get(['conversation_id', 'body']) as $message) {
            $first[(int) $message->conversation_id] ??= (string) $message->body;
        }
        $out = [];
        foreach ($conversations as $leadId => $conversationId) {
            $text = mb_strtolower(trim($first[(int) $conversationId] ?? ''));
            if ($text === '') {
                continue;
            }
            foreach (self::HINTS as $key => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $out[(int) $leadId] = ['key' => $key, 'label' => SalesLead::HEARD_FROM[$key], 'why' => 'İlk mesajı: “'.mb_strimwidth(trim((string) $first[(int) $conversationId]), 0, 90, '…').'”'];
                    break;
                }
            }
        }

        return $out;
    }

    /** Records where they heard of us (the Pazarlama screen's one-click source); the record's history notes it. */
    public function setHeardFrom(SalesLead $lead, string $heardFrom, ?User $user = null): void
    {
        abort_unless(isset(SalesLead::HEARD_FROM[$heardFrom]), 422);
        $lead->heard_from = $heardFrom;
        $lead->save();
        app(SalesLeads::class)->event($lead, 'system', 'Bizi nereden duydu: '.SalesLead::HEARD_FROM[$heardFrom].' (Pazarlama).', meta: ['by' => $user?->id]);
    }

    /** @return list<array{key: string, label: string}> the Tür filters: Sağlık, Hepsi, then every kind of the list */
    public function filters(): array
    {
        $out = [];
        foreach (self::FILTERS as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }
        foreach (LeadKinds::all() as $key => $kind) {
            $out[] = ['key' => $key, 'label' => (string) ($kind['label'] ?? $key)];
        }

        return $out;
    }

    public function filterKey(string $filter): string
    {
        return isset(self::FILTERS[$filter]) || LeadKinds::has($filter) ? $filter : 'saglik';
    }

    public function filterLabel(string $filter): string
    {
        return self::FILTERS[$filter] ?? LeadKinds::label($filter) ?? 'Sağlık';
    }

    /** @return Collection<int, SalesLead> the records opened in the span (existing customers' records and İlgisiz left out) */
    private function cohort(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return SalesLead::query()->relevant()->whereBetween('created_at', [$from->utc(), $to->utc()])
            // A customer's record made for an existing customer (source "other", won before it was opened) is not an application.
            ->where(fn ($q) => $q->whereNull('won_at')->orWhereColumn('won_at', '>=', 'created_at'))
            ->get(['id', 'name', 'company', 'website_host', 'phone', 'source', 'heard_from', 'stage', 'peak_stage', 'lifecycle', 'kind', 'customer_id', 'conversation_id', 'created_at', 'won_at']);
    }

    private function matches(SalesLead $lead, string $filter): bool
    {
        return match ($filter) {
            'hepsi' => true,
            'saglik' => LeadKinds::isHealth($lead->kind),
            default => $lead->kind === $filter,
        };
    }

    private static function isWon(SalesLead $lead): bool
    {
        return $lead->stage === 'won' || $lead->lifecycle === 'customer';
    }

    /**
     * What each won record brings per month: its agreements' monthly value, else the customer's fee.
     *
     * @param  Collection<int, SalesLead>  $leads
     * @return array<int, int>
     */
    private function fees(Collection $leads): array
    {
        $won = $leads->filter(fn (SalesLead $l): bool => self::isWon($l));
        if ($won->isEmpty()) {
            return [];
        }
        $fees = [];
        foreach (Agreement::query()->whereIn('lead_id', $won->pluck('id'))->get() as $agreement) {
            $fees[(int) $agreement->lead_id] = ($fees[(int) $agreement->lead_id] ?? 0) + $agreement->monthlyValue();
        }
        $customerFees = Customer::query()->whereIn('id', $won->pluck('customer_id')->filter())->pluck('monthly_fee', 'id');
        foreach ($won as $lead) {
            if (! isset($fees[$lead->id]) && $lead->customer_id !== null && isset($customerFees[$lead->customer_id])) {
                $fees[(int) $lead->id] = (int) $customerFees[$lead->customer_id];
            }
        }

        return $fees;
    }
}
