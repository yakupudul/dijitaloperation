<?php

namespace App\Panel\Services;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\FinanceDocument;
use App\Services\Access\StartLink;
use App\Services\Finance\FinanceDocuments;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Hesap: what a brand buys from the agency and what it owes, read from Finans. Finans rows carry no brand lock of
 * their own, so every read here names the brand; nothing of another payer can be reached through it.
 */
final class PanelAccount
{
    public const array SERVICES = [
        'seo_gbp' => ['SEO ve İşletme Profili', 'Sitenizin Google aramalarında ve haritada üst sıralara çıkması için aylık çalışma.'],
        'seo' => ['SEO', 'Sitenizin Google aramalarında üst sıralara çıkması için aylık çalışma.'],
        'gbp' => ['Google İşletme Profili', 'Haritada görünürlük, yorum yanıtları ve düzenli gönderiler.'],
        'google_ads' => ['Google Ads', 'Arama reklamlarının kurulumu ve aylık yönetimi.'],
        'meta_ads' => ['Instagram ve Facebook reklamları', 'Reklamların kurulumu ve aylık yönetimi.'],
        'web' => ['Web sitesi', 'Yeni site ya da var olan sitenin yenilenmesi.'],
    ];

    public function __construct(private readonly StartLink $start) {}

    /** @return Collection<int, Agreement> the brand's running jobs, newest first */
    public function agreements(int $brandId): Collection
    {
        return Agreement::query()->with('payments')->where('brand_id', $brandId)->whereIn('status', ['active', 'paused'])->orderByDesc('id')->get();
    }

    /**
     * The services the brand does not buy yet, to ask an offer for.
     *
     * @param  Collection<int, Agreement>  $agreements
     * @return array<string, array{0: string, 1: string}>
     */
    public function extras(Collection $agreements): array
    {
        $has = $agreements->where('status', 'active')->flatMap(fn (Agreement $a): array => (array) $a->services)->unique()->all();
        if (in_array('seo_gbp', $has, true)) {
            $has = [...$has, 'seo', 'gbp'];
        }
        if (in_array('seo', $has, true) && in_array('gbp', $has, true)) {
            $has[] = 'seo_gbp';
        }
        if (array_intersect(['seo', 'gbp'], $has) !== []) {
            $has[] = 'seo_gbp';
        }

        return array_diff_key(self::SERVICES, array_flip($has));
    }

    /**
     * Payment rows of the brand: what waits and what was paid.
     *
     * @return array{open: Collection<int, AgreementPayment>, paid: Collection<int, AgreementPayment>, debt: int, late: int}
     */
    public function payments(int $brandId): array
    {
        $ids = Agreement::query()->where('brand_id', $brandId)->pluck('id');
        $today = now('Europe/Istanbul')->toDateString();
        $open = AgreementPayment::query()->with('agreement')->whereIn('agreement_id', $ids)->whereNull('paid_on')
            ->where('due_on', '<=', now('Europe/Istanbul')->addDays(Agreement::YEARLY_AHEAD_DAYS)->toDateString())->orderBy('due_on')->limit(60)->get()
            ->filter(fn (AgreementPayment $p): bool => $p->open() > 0)->values();

        return [
            'open' => $open,
            'paid' => AgreementPayment::query()->with('agreement')->whereIn('agreement_id', $ids)->whereNotNull('paid_on')->orderByDesc('paid_on')->limit(36)->get(),
            'debt' => (int) $open->filter(fn (AgreementPayment $p): bool => $p->due_on !== null && $p->due_on->toDateString() <= $today)->sum(fn (AgreementPayment $p): int => $p->open()),
            'late' => $open->filter(fn (AgreementPayment $p): bool => $p->isOverdue())->count(),
        ];
    }

    /** @return Collection<int, FinanceDocument> the invoices the operator opened to the panel */
    public function invoices(int $brandId): Collection
    {
        return $this->invoiceQuery($brandId)->orderByDesc('doc_date')->orderByDesc('id')->limit(120)->get();
    }

    public function invoice(int $brandId, int $id): ?FinanceDocument
    {
        return $this->invoiceQuery($brandId)->whereKey($id)->first();
    }

    private function invoiceQuery(int $brandId)
    {
        $ids = Agreement::query()->where('brand_id', $brandId)->pluck('id');

        return FinanceDocument::query()->where('direction', FinanceDocument::OUT)->where('panel_visible', true)->whereNotNull('path')->where('status', '!=', 'ignored')
            ->where(fn ($q) => $q->where('brand_id', $brandId)->orWhereIn('agreement_id', $ids));
    }

    /** Whether the brand takes invoices at all (a payer without invoices sees its payment history instead). */
    public function takesInvoices(int $brandId): bool
    {
        $agreements = Agreement::query()->where('brand_id', $brandId)->get(['id', 'no_invoice']);

        return $agreements->isEmpty() || $agreements->contains(fn (Agreement $a): bool => ! $a->no_invoice);
    }

    /**
     * Yearly jobs (alan adı, sunucu) with the day they renew.
     *
     * @return Collection<int, array{agreement: Agreement, due: ?CarbonInterface, days: ?int, paid: bool}>
     */
    public function renewals(int $brandId): Collection
    {
        $today = now('Europe/Istanbul')->startOfDay();

        return Agreement::query()->with('payments')->where('brand_id', $brandId)->where('kind', 'yearly')->where('status', 'active')->get()
            ->map(function (Agreement $agreement) use ($today): array {
                $row = $agreement->payments->first(fn (AgreementPayment $p): bool => $p->paid_on === null) ?? null;
                $due = $row?->due_on;
                if ($due === null && $agreement->starts_on !== null) {
                    $due = $agreement->starts_on->copy();
                    while ($due->lt($today)) {
                        $due = $due->addYear();
                    }
                }

                return ['agreement' => $agreement, 'due' => $due, 'days' => $due !== null ? (int) $today->diffInDays($due->copy()->startOfDay(), false) : null, 'paid' => $row === null];
            })->sortBy(fn (array $r): int => $r['days'] ?? 99999)->values();
    }

    /** @return array{holder: string, iban: string, bank: string} */
    public function bank(): array
    {
        return $this->start->bank();
    }

    /** The manager's dekont for one payment row: kept in Finans as a new receipt, waiting for the operator's look at the bank. */
    public function receipt(int $brandId, int $paymentId, UploadedFile $file, int $panelUserId): FinanceDocument
    {
        $payment = AgreementPayment::query()->with('agreement')->whereKey($paymentId)->whereHas('agreement', fn ($q) => $q->where('brand_id', $brandId))->firstOrFail();
        $bytes = (string) $file->get();
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $path = 'finance/'.now('Europe/Istanbul')->format('Y/m').'/'.Str::uuid().'.'.$ext;
        Storage::disk(FinanceDocuments::DISK)->put($path, $bytes);

        return FinanceDocument::query()->create([
            'direction' => FinanceDocument::RECEIPT, 'source' => 'panel', 'status' => 'new', 'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 250), 'mime' => mb_substr((string) $file->getMimeType(), 0, 120), 'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes.'|'.$paymentId), 'doc_date' => now('Europe/Istanbul')->toDateString(), 'counterparty' => $payment->agreement?->payer(),
            'amount' => $payment->open(), 'agreement_id' => $payment->agreement_id, 'payment_id' => $payment->id, 'brand_id' => $brandId,
            'external_id' => 'panel:'.$panelUserId.':'.$payment->id.':'.now()->timestamp, 'meta' => ['panel_user_id' => $panelUserId],
        ]);
    }

    /** Receipts the brand already sent for rows that are still open: payment id => the day it was sent. */
    public function sentReceipts(int $brandId): Collection
    {
        return FinanceDocument::query()->where('direction', FinanceDocument::RECEIPT)->where('source', 'panel')->where('brand_id', $brandId)->where('status', 'new')
            ->orderBy('id')->get(['payment_id', 'created_at'])->pluck('created_at', 'payment_id');
    }

    public static function money(int|float|string|null $amount): string
    {
        return number_format((float) $amount, fmod((float) $amount, 1.0) === 0.0 ? 0 : 2, ',', '.').' ₺';
    }
}
