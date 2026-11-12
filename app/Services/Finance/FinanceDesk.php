<?php

namespace App\Services\Finance;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\FinanceDocument;
use App\Models\User;
use App\Services\Sales\Agreements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Finans by word (yakup 2026-11-12: "sana burdan yazdığımda ya da sistemdeki chatbota yazdığımda revize etme, ekleyip
 * çıkarma yapılmalı"): the same reads and writes the Finans screen does, for Moxi's cards and for Claude over MCP.
 * Every write is first a preview (what will happen, in plain Turkish) and then the run; both go through the services
 * the screen's own buttons call. Nothing here deletes a file or writes outside the panel.
 */
final class FinanceDesk
{
    public const array ACTIONS = [
        'finance_job' => 'Yeni iş',
        'finance_payment' => 'Ödeme işle',
        'finance_job_update' => 'İşi değiştir',
        'finance_expense' => 'Gider ekle',
        'finance_unpay' => 'Ödemeyi geri al',
    ];

    public function __construct(private readonly Agreements $agreements, private readonly FinanceBoard $board, private readonly FinanceDocuments $documents) {}

    /**
     * The month in words: who owes what, what came in, what is late, what was spent, what is coming.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $month = null): array
    {
        $date = $month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? CarbonImmutable::parse($month.'-01', Agreements::TZ) : CarbonImmutable::now(Agreements::TZ)->startOfMonth();
        $data = $this->board->month($date);
        $row = fn (array $r): array => array_filter([
            'payment_id' => $r['id'], 'agreement_id' => $r['agreement']->id, 'who' => $r['name'], 'what' => $r['what'], 'row' => $r['title'], 'amount' => $r['amount'],
            'received' => $r['received'], 'open' => $r['open'], 'due' => $r['due']->toDateString(), 'state' => $r['state'], 'days_late' => $r['days_late'] ?: null,
        ], fn ($v) => $v !== null);

        return [
            'month' => $date->format('Y-m'), 'note' => 'Tutarlar TL, KDV hariç. state: paid ödendi, late gecikti, partial kısmi, due günü geldi, coming bekleniyor.',
            'totals' => $data['totals'],
            'rows' => $data['rows']->map($row)->all(),
            'late_from_earlier_months' => $data['late']->map($row)->all(),
            'spend' => collect($data['spend'])->except(['docs'])->all(),
            'upcoming' => collect($data['upcoming'])->map(fn (array $u): array => ['kind' => $u['kind'], 'title' => $u['title'], 'detail' => $u['detail']])->all(),
            'documents_waiting' => $data['fresh'], 'receipts_waiting' => $data['receipts']->count(),
            'url' => route('operator.finance'),
        ];
    }

    /**
     * Jobs by name (payer, brand or title); empty q = every running job.
     *
     * @return list<array<string, mixed>>
     */
    public function jobs(string $q = '', bool $ended = false): array
    {
        $needle = Str::lower(trim($q));

        return Agreement::query()->with('brand:id,name', 'payments')->when(! $ended, fn ($query) => $query->whereIn('status', ['active', 'paused']))->orderByDesc('id')->limit(400)->get()
            ->filter(fn (Agreement $a): bool => $needle === '' || str_contains(Str::lower($a->name().' '.$a->party.' '.$a->brand?->name), $needle))
            ->take(60)->map(fn (Agreement $a): array => array_filter([
                'agreement_id' => $a->id, 'who' => $a->payer(), 'brand_id' => $a->brand_id, 'title' => $a->title, 'kind' => $a->kind, 'plan' => $a->plan, 'fee' => $a->feeLabel(),
                'fee_since' => $a->feeSince()?->toDateString(), 'status' => $a->status, 'starts_on' => $a->starts_on?->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
                'due_day' => $a->isSchedule() ? null : $a->due_day, 'whole_price' => $a->wholePrice(), 'received' => $a->received(), 'remaining' => $a->remaining(),
                'open_rows' => $a->payments->filter(fn (AgreementPayment $p): bool => $p->open() > 0)->sortBy('due_on')->take(12)
                    ->map(fn (AgreementPayment $p): array => ['payment_id' => $p->id, 'row' => $p->title(), 'due' => $p->due_on->toDateString(), 'open' => $p->open()])->values()->all(),
                'note' => $a->note,
            ], fn ($v) => $v !== null && $v !== []))->values()->all();
    }

    /**
     * What a write will do, checked: the card's lines and the arguments as they will run.
     *
     * @param  array<string, mixed>  $args
     * @return array{title: string, lines: list<string>, args: array<string, mixed>}
     */
    public function preview(string $action, array $args): array
    {
        $title = self::ACTIONS[$action] ?? throw new InvalidArgumentException('Bilinmeyen işlem.');
        $tl = fn (int|float $v): string => number_format((float) $v, 0, ',', '.').' ₺';
        $day = fn (?string $d): string => $d !== null ? CarbonImmutable::parse($d)->locale('tr')->isoFormat('D MMMM YYYY') : '';
        $date = fn (string $key): ?string => isset($args[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args[$key]) === 1 ? (string) $args[$key] : null;
        $text = fn (string $key, int $max = 200): ?string => ($v = trim((string) ($args[$key] ?? ''))) !== '' ? mb_substr($v, 0, $max) : null;
        $job = fn (): Agreement => Agreement::query()->with('brand:id,name', 'payments')->find((int) ($args['agreement_id'] ?? 0)) ?? throw new InvalidArgumentException('İş bulunamadı (finans_isler ile id bul).');
        $rows = function () use ($args): array {
            $out = [];
            foreach ((array) ($args['installments'] ?? []) as $r) {
                $due = (string) data_get($r, 'due_on', '');
                $amount = (int) data_get($r, 'amount', 0);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) !== 1 || $amount <= 0) {
                    throw new InvalidArgumentException('Her taksitin tarihi (YYYY-AA-GG) ve tutarı (TL) gerekir.');
                }
                $out[] = array_filter(['due_on' => $due, 'amount' => $amount, 'label' => mb_substr(trim((string) data_get($r, 'label', '')), 0, 80) ?: null], fn ($v) => $v !== null);
            }

            return $out;
        };

        if ($action === 'finance_job') {
            $brand = isset($args['brand_id']) ? (Brand::query()->find((int) $args['brand_id']) ?? throw new InvalidArgumentException('Marka bulunamadı.')) : null;
            $party = $text('party', 160);
            if ($brand === null && $party === null) {
                throw new InvalidArgumentException('Kimden alınacağı gerekir: brand_id ya da party.');
            }
            $kind = (string) ($args['kind'] ?? 'one_time');
            if (! isset(Agreement::KINDS[$kind])) {
                throw new InvalidArgumentException('Tür şunlardan biri olmalı: '.implode(', ', array_keys(Agreement::KINDS)).'.');
            }
            $installments = $rows();
            $fee = (int) ($args['fee'] ?? 0);
            if ($installments === [] && $fee <= 0 && $kind !== 'partnership') {
                throw new InvalidArgumentException('Ücret (TL, KDV hariç) ya da taksitler gerekir; söylenmeyen ücret tahmin edilmez.');
            }
            if ($installments !== [] && ! in_array($kind, ['term', 'one_time'], true)) {
                throw new InvalidArgumentException('Taksit planı dönemlik ya da tek seferlik işler içindir.');
            }
            $term = isset($args['term_months']) ? (int) $args['term_months'] : null;
            if ($kind === 'term' && ($term === null || $term < 1 || $term > 36)) {
                throw new InvalidArgumentException('Dönemlik işte ay sayısı gerekir (1-36).');
            }
            $due = max(1, min(28, (int) ($args['due_day'] ?? 1)));
            $starts = $date('starts_on') ?? CarbonImmutable::now(Agreements::TZ)->toDateString();
            $clean = array_filter(['brand_id' => $brand?->id, 'party' => $brand === null ? $party : null, 'party_phone' => $text('party_phone', 30), 'title' => $text('title', 160), 'kind' => $kind,
                'fee' => $fee > 0 ? $fee : null, 'term_months' => $kind === 'term' ? $term : null, 'due_day' => $due, 'starts_on' => $starts, 'plan' => $installments !== [] ? 'schedule' : 'auto',
                'installments' => $installments ?: null, 'tax_no' => $text('tax_no', 20), 'note' => $text('note', 500)], fn ($v) => $v !== null);
            $lines = ['Kimden: '.($brand?->name ?? $party).($text('title') !== null ? ' · '.$text('title') : ''), Agreement::KINDS[$kind].($kind === 'term' ? ' · '.$term.' ay' : '')
                .($installments === [] && $fee > 0 ? ' · '.$tl($fee).' + KDV' : ''), 'Başlangıç '.$day($starts).(in_array($kind, ['monthly', 'term'], true) && $installments === [] ? ' · ödeme günü '.$due : '')];
            foreach ($installments as $i => $r) {
                $lines[] = ($i + 1).'. taksit: '.$day($r['due_on']).' · '.$tl($r['amount']).(isset($r['label']) ? ' ('.$r['label'].')' : '');
            }
            if ($installments !== []) {
                $lines[] = 'Toplam '.$tl(array_sum(array_column($installments, 'amount'))).' + KDV';
            }

            return ['title' => $title, 'lines' => $lines, 'args' => $clean];
        }

        if ($action === 'finance_payment') {
            $a = $job();
            $amount = (float) ($args['amount'] ?? 0);
            if ($amount <= 0) {
                throw new InvalidArgumentException('Gelen tutar gerekir (TL).');
            }
            $vat = in_array($args['vat'] ?? 'auto', ['auto', 'yes', 'no'], true) ? (string) ($args['vat'] ?? 'auto') : 'auto';
            $net = $this->board->net($a, $amount, ['yes' => true, 'no' => false][$vat] ?? null);
            $on = $date('date') ?? CarbonImmutable::now(Agreements::TZ)->toDateString();
            $left = $a->remaining();
            $lines = ['Kimden: '.$a->name(), 'Gelen: '.$tl($amount).($net['with_vat'] ? ' (KDV dahil; KDV hariç '.$tl($net['net']).' işlenir)' : ' (KDV hariç)'), 'Geldiği gün: '.$day($on)];
            if ($left !== null) {
                $lines[] = 'Sonra kalan: '.$tl(max(0, $left - $net['net']));
            }

            return ['title' => $title, 'lines' => $lines, 'args' => ['agreement_id' => $a->id, 'amount' => $amount, 'vat' => $net['with_vat'] ? 'yes' : 'no', 'date' => $on] + array_filter(['note' => $text('note')])];
        }

        if ($action === 'finance_unpay') {
            $p = AgreementPayment::query()->with('agreement.brand:id,name')->find((int) ($args['payment_id'] ?? 0)) ?? throw new InvalidArgumentException('Ödeme satırı bulunamadı.');
            if ($p->received() <= 0) {
                throw new InvalidArgumentException('Bu satıra işlenmiş ödeme yok.');
            }

            return ['title' => $title, 'lines' => ['Kimden: '.$p->agreement->name(), 'Satır: '.$p->title().' · '.$tl($p->received()).' geri alınır, satır yeniden açık olur.'], 'args' => ['payment_id' => $p->id]];
        }

        if ($action === 'finance_job_update') {
            $a = $job();
            $clean = ['agreement_id' => $a->id];
            $lines = ['İş: '.$a->name().' ('.$a->feeLabel().')'];
            if (isset($args['fee']) && (int) $args['fee'] > 0 && (int) $args['fee'] !== (int) $a->fee) {
                if ($a->isSchedule()) {
                    throw new InvalidArgumentException('Taksitli işte tutar taksitlerle değişir: installments ver.');
                }
                $clean['fee'] = (int) $args['fee'];
                $clean['fee_since'] = $date('fee_since') ?? CarbonImmutable::now(Agreements::TZ)->toDateString();
                $lines[] = 'Yeni ücret: '.$tl($clean['fee']).' + KDV · '.$day($clean['fee_since']).' gününden beri (önceki aylar eski ücrette kalır)';
            }
            if (isset($args['due_day']) && (int) $args['due_day'] >= 1 && (int) $args['due_day'] <= 28 && (int) $args['due_day'] !== (int) $a->due_day) {
                $clean['due_day'] = (int) $args['due_day'];
                $lines[] = 'Ödeme günü: ayın '.$clean['due_day'].'. günü';
            }
            foreach (['title' => 'İşin adı', 'note' => 'Not', 'party' => 'Kimden', 'party_phone' => 'Telefon', 'tax_no' => 'Vergi no'] as $key => $name) {
                if ($text($key, 500) !== null) {
                    $clean[$key] = $text($key, 500);
                    $lines[] = $name.': '.$clean[$key];
                }
            }
            $installments = $rows();
            if ($installments !== []) {
                if (! $a->isSchedule() && ! in_array($a->kind, ['term', 'one_time'], true)) {
                    throw new InvalidArgumentException('Taksit planı dönemlik ya da tek seferlik işler içindir.');
                }
                $clean['installments'] = $installments;
                $clean['plan'] = 'schedule';
                $lines[] = 'Bekleyen taksitler şunlarla değişir (ödenmişler durur):';
                foreach ($installments as $i => $r) {
                    $lines[] = ($i + 1).'. '.$day($r['due_on']).' · '.$tl($r['amount']);
                }
            }
            if (isset($args['status']) && isset(Agreement::STATUSES[(string) $args['status']]) && $args['status'] !== $a->status) {
                $clean['status'] = (string) $args['status'];
                $lines[] = 'Durum: '.Agreement::STATUSES[$clean['status']].($clean['status'] === 'ended' ? ' (ödenmemiş ileri aylar kalkar, ödenenler durur)' : '');
            }
            if (count($clean) === 1) {
                throw new InvalidArgumentException('Değişecek bir şey verilmedi.');
            }

            return ['title' => $title, 'lines' => $lines, 'args' => $clean];
        }

        // finance_expense
        $who = $text('counterparty', 200) ?? throw new InvalidArgumentException('Nereye ödendiği gerekir (counterparty).');
        $amount = (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Tutar gerekir (KDV dahil).');
        }
        $currency = in_array(strtoupper((string) ($args['currency'] ?? 'TRY')), ['TRY', 'USD', 'EUR', 'GBP'], true) ? strtoupper((string) ($args['currency'] ?? 'TRY')) : 'TRY';
        $category = isset(FinanceDocument::CATEGORIES[(string) ($args['category'] ?? '')]) ? (string) $args['category'] : 'diger';
        $brand = isset($args['brand_id']) ? (Brand::query()->find((int) $args['brand_id']) ?? throw new InvalidArgumentException('Marka bulunamadı.')) : null;
        $on = $date('doc_date') ?? CarbonImmutable::now(Agreements::TZ)->toDateString();
        $rebill = $brand !== null && (bool) ($args['rebill'] ?? false);
        $lines = ['Nereye: '.$who, 'Tutar: '.number_format($amount, 2, ',', '.').' '.$currency.' · '.$day($on), 'Tür: '.FinanceDocument::CATEGORIES[$category]];
        if ($brand !== null) {
            $lines[] = 'Müşteri için: '.$brand->name.($rebill ? ' (geri istenecek)' : '');
        }

        return ['title' => $title, 'lines' => $lines, 'args' => array_filter(['counterparty' => $who, 'amount' => $amount, 'currency' => $currency, 'category' => $category, 'doc_date' => $on,
            'brand_id' => $brand?->id, 'rebill' => $rebill ?: null, 'note' => $text('note', 500)], fn ($v) => $v !== null)];
    }

    /**
     * Runs a previewed write; the answer is one line in Turkish.
     *
     * @param  array<string, mixed>  $args
     * @return array{text: string, url: ?string}
     */
    public function run(string $action, array $args, ?User $user): array
    {
        $args = $this->preview($action, $args)['args'];
        $tl = fn (int|float $v): string => number_format((float) $v, 0, ',', '.').' ₺';
        $url = route('operator.finance');
        try {
            switch ($action) {
                case 'finance_job':
                    $brand = isset($args['brand_id']) ? Brand::query()->findOrFail((int) $args['brand_id']) : null;
                    $a = $this->agreements->start($brand, $args + ['beside' => in_array($args['kind'], Agreements::BESIDE_KINDS, true)], $user);

                    return ['text' => $a->name().' açıldı: '.$a->feeLabel().'.', 'url' => $url.'?sekme=anlasmalar'];
                case 'finance_payment':
                    $a = Agreement::query()->findOrFail((int) $args['agreement_id']);
                    $net = $this->board->net($a, (float) $args['amount'], $args['vat'] === 'yes');
                    $result = $this->agreements->receive($a, $net['net'], $args['date'], $args['note'] ?? null);
                    $left = $a->fresh('payments')->remaining();

                    return ['text' => $a->payer().': '.$tl($net['net']).' işlendi.'.($left !== null ? ' Kalan '.$tl($left).'.' : '').($result['left'] > 0 ? ' '.$tl($result['left']).' yerleşecek açık satır bulamadı.' : ''), 'url' => $url];
                case 'finance_unpay':
                    $p = AgreementPayment::query()->with('agreement.brand:id,name')->findOrFail((int) $args['payment_id']);
                    $this->agreements->unpay($p);

                    return ['text' => $p->agreement->payer().': '.$p->title().' ödemesi geri alındı.', 'url' => $url];
                case 'finance_job_update':
                    $a = Agreement::query()->findOrFail((int) $args['agreement_id']);
                    $status = $args['status'] ?? null;
                    $data = collect($args)->except(['agreement_id', 'status'])->all();
                    if ($data !== []) {
                        $a = $this->agreements->update($a, $data);
                    }
                    if ($status !== null) {
                        $this->agreements->setStatus($a, (string) $status);
                    }

                    return ['text' => $a->fresh()->name().' güncellendi: '.$a->fresh()->feeLabel().'.', 'url' => $url.'?sekme=anlasmalar'];
                default:
                    $doc = $this->documents->manual(['direction' => FinanceDocument::IN] + $args, $user?->id);

                    return ['text' => 'Gider yazıldı: '.$doc->counterparty.' '.$doc->amountLabel().'.', 'url' => $url.'?sekme=giderler'];
            }
        } catch (ValidationException $error) {
            throw new InvalidArgumentException(implode(' ', array_map('strval', array_merge(...array_values($error->errors())))));
        }
    }
}
