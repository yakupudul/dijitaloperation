<?php

namespace App\Services\Finance;

use App\Mcp\Tools\Todos;
use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Reminder;
use App\Support\Demo\DemoMenu;
use App\Support\Finance\TaxNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Müşteri fatura bilgileri (yakup 2026-11-27): who the invoice is written to. Kept on the customer (the paying side
 * of one or more brands): unvan (legal_name), vergi no, vergi dairesi, fatura adresi, fatura e-postası, e-Fatura
 * mükellefiyeti and the vergi levhası file. A brand that takes an invoice (a running agreement without "fatura
 * istemiyor") with any of the four must-haves empty is shown on Finans, on Bugün and as one Yapılacaklar line, the
 * way MissingAgreements does for money nobody follows. Brands that take no invoice need nothing here.
 */
final class BillingInfo
{
    public const string TODO_SUFFIX = ' için fatura bilgilerini Finans\'a gir';

    public const string OWN_TODO = 'Şirket bilgilerini ve vergi levhasını Finans\'a gir';

    public const array REQUIRED = ['legal_name' => 'unvan', 'tax_no' => 'vergi no', 'tax_office' => 'vergi dairesi', 'billing_address' => 'fatura adresi'];

    public const array FIELDS = ['legal_name', 'tax_no', 'tax_office', 'billing_address', 'billing_city', 'billing_email', 'invoice_kind'];

    /**
     * Every brand with a running job, the invoiced ones first, each with its customer and what is missing.
     *
     * @return Collection<int, array{brand: Brand, customer: ?Customer, invoiced: bool, missing: list<string>}>
     */
    public function rows(): Collection
    {
        $own = (int) (DemoMenu::agencyBrand()['id'] ?? 0);
        $agreements = Agreement::query()->whereIn('status', ['active', 'paused'])->whereNotNull('brand_id')->get(['id', 'brand_id', 'no_invoice']);
        $invoiced = $agreements->where('no_invoice', false)->pluck('brand_id')->map(fn ($id): int => (int) $id)->flip();
        $ids = $agreements->pluck('brand_id')->map(fn ($id): int => (int) $id)->unique()->reject(fn (int $id): bool => $id === $own)->values();

        return Brand::query()->with('customer')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'customer_id'])
            ->map(function (Brand $brand) use ($invoiced): array {
                $isInvoiced = isset($invoiced[(int) $brand->id]);

                return ['brand' => $brand, 'customer' => $brand->customer, 'invoiced' => $isInvoiced, 'missing' => $isInvoiced ? self::missingOf($brand->customer) : []];
            })
            ->sortBy(fn (array $r): string => ($r['invoiced'] ? '0' : '1').($r['missing'] !== [] ? '0' : '1').mb_strtolower((string) $r['brand']->name))
            ->values();
    }

    /** @return Collection<int, array{brand: Brand, customer: ?Customer, invoiced: bool, missing: list<string>}> invoiced brands with a gap */
    public function gaps(): Collection
    {
        return $this->rows()->filter(fn (array $r): bool => $r['invoiced'] && $r['missing'] !== [])->values();
    }

    /** @return array<int, list<string>> brand id => missing labels, for screens that mark rows */
    public function gapIds(): array
    {
        return $this->gaps()->mapWithKeys(fn (array $r): array => [(int) $r['brand']->id => $r['missing']])->all();
    }

    /** @return list<string> */
    public static function missingOf(?Customer $customer): array
    {
        $out = [];
        foreach (self::REQUIRED as $field => $label) {
            if ($customer === null || blank($customer->{$field})) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /** @return array<string, string> the form's values for a brand */
    public function form(Brand $brand): array
    {
        $customer = $brand->customer;
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = (string) ($customer?->{$field} ?? '');
        }
        if ($out['tax_no'] === '') {
            $out['tax_no'] = (string) (Agreement::query()->where('brand_id', $brand->id)->whereNotNull('tax_no')->orderByDesc('id')->value('tax_no') ?? '');
        }

        return $out;
    }

    /**
     * Writes a brand's billing details to its customer (one is opened when the brand has none) and gives its jobs the
     * vergi no, so an invoice read from Gmail finds its job.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Brand $brand, array $input, string $source = 'operator'): Customer
    {
        $clean = $this->clean($input);
        $customer = $this->customerOf($brand);
        $customer->forceFill($clean + ['billing_source' => $source, 'billing_updated_at' => now()])->save();
        if ($clean['tax_no'] !== null) {
            Agreement::query()->where('brand_id', $brand->id)->where(fn ($q) => $q->whereNull('tax_no')->orWhere('tax_no', '!=', $clean['tax_no']))->update(['tax_no' => $clean['tax_no']]);
        }
        self::settled($brand);

        return $customer;
    }

    /**
     * What a customer wrote on the start link fills only what is still empty: the operator's own entry wins.
     *
     * @param  array<string, string>  $party  StartLink::PARTY keys
     */
    public function fromStartLink(Brand $brand, array $party): void
    {
        $customer = $brand->customer;
        if ($customer === null) {
            return;
        }
        $map = ['title' => 'legal_name', 'tax_no' => 'tax_no', 'tax_office' => 'tax_office', 'address' => 'billing_address', 'email' => 'billing_email'];
        $input = $this->form($brand);
        $changed = false;
        foreach ($map as $from => $to) {
            if (blank($input[$to]) && filled($party[$from] ?? null)) {
                $input[$to] = (string) $party[$from];
                $changed = true;
            }
        }
        if ($changed) {
            try {
                $this->save($brand, $input, 'start_link');
            } catch (ValidationException) {
                // A malformed number typed by the customer stays on the link; the operator sees the gap on Finans.
            }
        }
    }

    /**
     * The message asking a customer for his fatura bilgileri, with the WhatsApp number of the brand when known. The
     * operator sends it himself; nothing here sends anything.
     *
     * @return array{text: string, phone: ?string}
     */
    public function request(Brand $brand): array
    {
        $missing = self::missingOf($brand->customer);
        $agreement = Agreement::query()->where('brand_id', $brand->id)->whereIn('status', ['active', 'paused'])->orderByDesc('id')->first();
        $phone = app(FinanceBoard::class)->phoneOf($agreement);
        $what = $missing === [] ? 'fatura bilgilerinizi' : implode(', ', $missing).' bilgilerinizi';

        return [
            'text' => 'Merhaba, faturanızı doğru düzenleyebilmemiz için '.$what.' iletebilir misiniz? Vergi levhanızın fotoğrafı ya da PDF\'i de yeterli olur. Fatura için kullanmamızı istediğiniz e-posta adresi varsa onu da yazabilirsiniz. Teşekkürler.',
            'phone' => $phone,
        ];
    }

    public function keepCertificate(Brand $brand, UploadedFile $file): void
    {
        $customer = $this->customerOf($brand);
        $path = CompanyProfile::storeCertificate($file, 'musteri-'.$customer->id);
        if (filled($customer->tax_certificate_path)) {
            Storage::disk(FinanceDocuments::DISK)->delete((string) $customer->tax_certificate_path);
        }
        $customer->forceFill(['tax_certificate_path' => $path])->save();
    }

    /**
     * The lines to type into the e-Arşiv / e-Fatura portal for one payment row: buyer, what, net, KDV, total.
     *
     * @return array{text: string, missing: list<string>, e_fatura: bool}
     */
    public function invoiceSheet(AgreementPayment $payment): array
    {
        $agreement = $payment->agreement;
        $customer = $agreement?->brand?->customer ?? ($agreement?->customer_id ? Customer::query()->find($agreement->customer_id) : null);
        $vat = (int) app(FinanceSettings::class)->get('vat');
        $net = (int) $payment->open() > 0 ? (int) $payment->open() : (int) $payment->amount;
        $tax = (int) round($net * $vat / 100);
        $taxNo = (string) ($customer?->tax_no ?: $agreement?->tax_no);
        $lines = [
            'Alıcı' => (string) ($customer?->legal_name ?: $agreement?->payer()),
            strlen($taxNo) === 11 ? 'TC kimlik no' : 'Vergi no' => $taxNo,
            'Vergi dairesi' => (string) $customer?->tax_office,
            'Adres' => trim((string) $customer?->billing_address.($customer?->billing_city ? ' '.$customer->billing_city : '')),
            'E-posta' => (string) $customer?->billing_email,
            'Hizmet' => trim(($agreement !== null && filled($agreement->title) ? $agreement->title : 'Dijital pazarlama hizmeti').' · '.$payment->title()),
            'Tutar (KDV hariç)' => number_format($net, 2, ',', '.').' TL',
            'KDV %'.$vat => number_format($tax, 2, ',', '.').' TL',
            'Toplam' => number_format($net + $tax, 2, ',', '.').' TL',
        ];

        return [
            'text' => collect($lines)->filter(fn (string $v): bool => $v !== '')->map(fn (string $v, string $k): string => $k.': '.$v)->implode("\n"),
            'missing' => self::missingOf($customer),
            'e_fatura' => $customer?->invoice_kind === 'e_fatura',
        ];
    }

    /**
     * One to-do per invoiced brand with a gap, one for our own company; each closes itself once filled.
     *
     * @return array{added: int, closed: int}
     */
    public function sync(): array
    {
        $user = Todos::operator();
        if ($user === null) {
            return ['added' => 0, 'closed' => 0];
        }
        $gaps = $this->gaps();
        $gapIds = $gaps->mapWithKeys(fn (array $r): array => [(int) $r['brand']->id => true]);
        $open = Reminder::query()->where('user_id', $user->id)->where('kind', 'task')->whereNull('done_at')->where('title', 'like', '%'.self::TODO_SUFFIX)->get();
        $closed = 0;
        foreach ($open as $reminder) {
            if (! isset($gapIds[(int) $reminder->brand_id])) {
                $reminder->forceFill(['done_at' => now()])->save();
                $closed++;
            }
        }
        $have = $open->pluck('brand_id')->map(fn ($id): int => (int) $id)->flip();
        $added = 0;
        foreach ($gaps as $row) {
            $brand = $row['brand'];
            if (isset($have[(int) $brand->id])) {
                continue;
            }
            Reminder::query()->create([
                'user_id' => $user->id, 'kind' => 'task', 'repeat' => 'none', 'all_day' => true,
                'title' => $brand->name.self::TODO_SUFFIX,
                'notes' => 'Bu markaya fatura kesiliyor ama şunlar eksik: '.implode(', ', $row['missing']).'. Vergi levhasını yüklemen yeter, ya da bilgileri Claude\'a yaz, o girer.',
                'url' => route('operator.finance', ['sekme' => 'bilgiler', 'marka' => $brand->id]),
                'brand_id' => $brand->id, 'customer_id' => $brand->customer_id,
            ]);
            $added++;
        }
        $ownMissing = app(CompanyProfile::class)->missing();
        $ownOpen = Reminder::query()->where('user_id', $user->id)->where('kind', 'task')->whereNull('done_at')->where('title', self::OWN_TODO)->first();
        if ($ownMissing === [] && $ownOpen !== null) {
            $ownOpen->forceFill(['done_at' => now()])->save();
            $closed++;
        } elseif ($ownMissing !== [] && $ownOpen === null) {
            Reminder::query()->create([
                'user_id' => $user->id, 'kind' => 'task', 'repeat' => 'none', 'all_day' => true, 'title' => self::OWN_TODO,
                'notes' => 'Eksik: '.implode(', ', $ownMissing).'. Fatura keserken, sözleşmede ve ödeme hatırlatmasında bu bilgiler kullanılır.',
                'url' => route('operator.finance', ['sekme' => 'bilgiler']),
            ]);
            $added++;
        }

        return ['added' => $added, 'closed' => $closed];
    }

    /** The brand's to-do is ticked as soon as its details are complete, not at the next hourly round. */
    public static function settled(Brand $brand): void
    {
        $brand->unsetRelation('customer');
        if (self::missingOf($brand->customer) !== []) {
            return;
        }
        Reminder::query()->where('kind', 'task')->whereNull('done_at')->where('brand_id', $brand->id)
            ->where('title', 'like', '%'.self::TODO_SUFFIX)->update(['done_at' => now()]);
    }

    public function customerOf(Brand $brand): Customer
    {
        if ($brand->customer !== null) {
            return $brand->customer;
        }
        $customer = Customer::query()->create(['name' => $brand->name, 'type' => 'company', 'status' => 'active']);
        $brand->forceFill(['customer_id' => $customer->id])->save();
        $brand->setRelation('customer', $customer);

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, ?string>
     */
    private function clean(array $input): array
    {
        $tax = (string) preg_replace('/\D+/', '', (string) ($input['tax_no'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['billing_email'] ?? '')));
        $errors = [];
        if (($taxError = TaxNumber::error($tax)) !== null) {
            $errors['tax_no'] = $taxError;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['billing_email'] = 'E-posta adresi geçerli değil.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $text = fn (string $key, int $max): ?string => ($v = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($input[$key] ?? ''))), 0, $max)) !== '' ? $v : null;
        $kind = (string) ($input['invoice_kind'] ?? '');

        return [
            'legal_name' => $text('legal_name', 180), 'tax_no' => $tax !== '' ? $tax : null, 'tax_office' => $text('tax_office', 120),
            'billing_address' => $text('billing_address', 400), 'billing_city' => $text('billing_city', 120), 'billing_email' => $email !== '' ? $email : null,
            'invoice_kind' => isset(CompanyProfile::INVOICE_KINDS[$kind]) ? $kind : null,
        ];
    }
}
