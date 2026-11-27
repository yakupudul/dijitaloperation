@php
    use App\Services\Finance\CompanyProfile;

    $cert = $companyAll['certificate'] ?? [];
@endphp
<div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]" data-finance-billing>
    <div class="min-w-0 space-y-4">
        {{-- Şirketimiz --}}
        <section class="card p-4" data-finance-company>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="section-title">Şirketimiz</h2>
                    <p class="page-sub">Kestiğin faturalarda, müşteriye giden sözleşmede ve ödeme bilgisinde bu bilgiler kullanılır.</p>
                </div>
                @if ($companyMissing === [])<span class="chip chip-up">Tamam</span>@else<span class="chip chip-down">Eksik: {{ implode(', ', $companyMissing) }}</span>@endif
            </div>
            <form wire:submit="saveCompany" class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="{{ $label }}" for="co-title">Unvan</label><input id="co-title" type="text" wire:model="company.title" class="{{ $input }}" placeholder="Vergi levhasında yazdığı gibi">@if ($err('company.title'))<p class="mt-1 text-xs text-error-600">{{ $err('company.title') }}</p>@endif</div>
                <div><label class="{{ $label }}" for="co-kind">Şirket türü</label><select id="co-kind" wire:model="company.kind" class="{{ $input }}"><option value="">Seç</option>@foreach (CompanyProfile::KINDS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div><label class="{{ $label }}" for="co-invoice">Fatura türü</label><select id="co-invoice" wire:model="company.invoice_kind" class="{{ $input }}"><option value="">Seç</option>@foreach (CompanyProfile::INVOICE_KINDS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div><label class="{{ $label }}" for="co-tax">Vergi no / TC kimlik no</label><input id="co-tax" type="text" inputmode="numeric" wire:model="company.tax_no" class="{{ $input }}">@if ($err('company.tax_no'))<p class="mt-1 text-xs text-error-600">{{ $err('company.tax_no') }}</p>@endif</div>
                <div><label class="{{ $label }}" for="co-office">Vergi dairesi</label><input id="co-office" type="text" wire:model="company.tax_office" class="{{ $input }}"></div>
                <div><label class="{{ $label }}" for="co-mersis">MERSİS no</label><input id="co-mersis" type="text" inputmode="numeric" wire:model="company.mersis" class="{{ $input }}" placeholder="Şahıs şirketinde boş kalabilir">@if ($err('company.mersis'))<p class="mt-1 text-xs text-error-600">{{ $err('company.mersis') }}</p>@endif</div>
                <div><label class="{{ $label }}" for="co-registry">Ticaret sicil no</label><input id="co-registry" type="text" wire:model="company.trade_registry" class="{{ $input }}"></div>
                <div class="sm:col-span-2"><label class="{{ $label }}" for="co-address">Adres</label><input id="co-address" type="text" wire:model="company.address" class="{{ $input }}"></div>
                <div><label class="{{ $label }}" for="co-city">İlçe / il</label><input id="co-city" type="text" wire:model="company.city" class="{{ $input }}"></div>
                <div><label class="{{ $label }}" for="co-phone">Telefon</label><input id="co-phone" type="text" wire:model="company.phone" class="{{ $input }}"></div>
                <div><label class="{{ $label }}" for="co-email">E-posta</label><input id="co-email" type="email" wire:model="company.email" class="{{ $input }}">@if ($err('company.email'))<p class="mt-1 text-xs text-error-600">{{ $err('company.email') }}</p>@endif</div>
                <div><label class="{{ $label }}" for="co-kep">KEP adresi</label><input id="co-kep" type="text" wire:model="company.kep" class="{{ $input }}"></div>
                <div class="sm:col-span-2 mt-2 border-t border-gray-100 pt-3 dark:border-gray-800"><p class="text-xs font-medium text-gray-600 dark:text-gray-400">Ödeme alınan hesap</p></div>
                <div><label class="{{ $label }}" for="co-holder">Hesap sahibi</label><input id="co-holder" type="text" wire:model="company.holder" class="{{ $input }}"></div>
                <div><label class="{{ $label }}" for="co-bank">Banka</label><input id="co-bank" type="text" wire:model="company.bank" class="{{ $input }}"></div>
                <div class="sm:col-span-2"><label class="{{ $label }}" for="co-iban">IBAN</label><input id="co-iban" type="text" wire:model="company.iban" class="{{ $input }} font-mono" placeholder="TR00 0000 0000 0000 0000 0000 00">@if ($err('company.iban'))<p class="mt-1 text-xs text-error-600">{{ $err('company.iban') }}</p>@endif</div>
                <div class="sm:col-span-2 flex flex-wrap items-center gap-3"><button type="submit" class="btn-primary" data-finance-company-save>Kaydet</button><span class="text-xs text-gray-500">IBAN ve hesap sahibi, Satış › Ayarlar'daki sözleşme ve başlangıç bağlantısıyla aynı kayıttır.</span></div>
            </form>

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-800" data-finance-company-certificate>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Vergi levhası</p>
                    <p class="text-[13px] text-gray-500">
                        @if (filled($cert['path'] ?? null))
                            {{ $cert['name'] ?? 'Dosya' }} · {{ \Carbon\CarbonImmutable::parse($cert['at'] ?? now())->locale('tr')->isoFormat('D MMM YYYY') }}
                        @else
                            Henüz yüklenmedi. Bir müşteri ya da muhasebeci istediğinde buradan açarsın.
                        @endif
                    </p>
                </div>
                @if (filled($cert['path'] ?? null))<a href="{{ route('operator.finance.certificate', ['owner' => 'sirket']) }}" target="_blank" rel="noopener" class="btn-quiet">Aç</a>@endif
                <label class="btn cursor-pointer">{{ filled($cert['path'] ?? null) ? 'Yenisini yükle' : 'Levhayı yükle' }}<input type="file" wire:model="companyCertificate" accept=".pdf,.jpg,.jpeg,.png,.webp" class="sr-only"></label>
                <span class="text-xs text-gray-500" wire:loading wire:target="companyCertificate">Yükleniyor…</span>
            </div>
        </section>

        {{-- Müşteri fatura bilgileri --}}
        <section class="card" data-finance-customers-billing>
            <div class="px-4 pt-4">
                <h2 class="section-title">Müşteri fatura bilgileri <span class="section-count">{{ $billingRows->where('invoiced', true)->count() }}</span></h2>
                <p class="page-sub">Sürmekte olan işi olan markalar. Fatura istemeyenlerden bilgi beklenmez.</p>
            </div>
            @if ($billingEditing !== null)
                <form wire:submit="saveBilling" class="mx-4 mt-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800" data-finance-billing-form="{{ $billingEditing->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $billingEditing->name }}</p>
                        <a href="{{ route('operator.customer', ['customerId' => $billingEditing->customer_id]) }}" wire:navigate class="text-xs text-gray-500 underline">Müşteri kaydı</a>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2"><label class="{{ $label }}" for="bl-title">Unvan ya da ad soyad</label><input id="bl-title" type="text" wire:model="billing.legal_name" class="{{ $input }}"></div>
                        <div><label class="{{ $label }}" for="bl-tax">Vergi no / TC kimlik no</label><input id="bl-tax" type="text" inputmode="numeric" wire:model="billing.tax_no" class="{{ $input }}">@if ($err('billing.tax_no'))<p class="mt-1 text-xs text-error-600">{{ $err('billing.tax_no') }}</p>@endif</div>
                        <div><label class="{{ $label }}" for="bl-office">Vergi dairesi</label><input id="bl-office" type="text" wire:model="billing.tax_office" class="{{ $input }}"></div>
                        <div class="sm:col-span-2"><label class="{{ $label }}" for="bl-address">Fatura adresi</label><input id="bl-address" type="text" wire:model="billing.billing_address" class="{{ $input }}"></div>
                        <div><label class="{{ $label }}" for="bl-city">İlçe / il</label><input id="bl-city" type="text" wire:model="billing.billing_city" class="{{ $input }}"></div>
                        <div><label class="{{ $label }}" for="bl-email">Fatura e-postası</label><input id="bl-email" type="email" wire:model="billing.billing_email" class="{{ $input }}">@if ($err('billing.billing_email'))<p class="mt-1 text-xs text-error-600">{{ $err('billing.billing_email') }}</p>@endif</div>
                        <div><label class="{{ $label }}" for="bl-kind">Fatura türü</label><select id="bl-kind" wire:model="billing.invoice_kind" class="{{ $input }}"><option value="">Bilinmiyor</option>@foreach (CompanyProfile::INVOICE_KINDS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="flex items-end gap-2">
                            @if ($billingEditing->customer?->tax_certificate_path)<a href="{{ route('operator.finance.certificate', ['owner' => $billingEditing->customer_id]) }}" target="_blank" rel="noopener" class="btn-quiet">Levhayı aç</a>@endif
                            <label class="btn cursor-pointer">{{ $billingEditing->customer?->tax_certificate_path ? 'Levhayı yenile' : 'Vergi levhası yükle' }}<input type="file" wire:model="billingCertificate" accept=".pdf,.jpg,.jpeg,.png,.webp" class="sr-only"></label>
                            <span class="text-xs text-gray-500" wire:loading wire:target="billingCertificate">Yükleniyor…</span>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2"><button type="submit" class="btn-primary" data-finance-billing-save>Kaydet</button><button type="button" wire:click="closeBilling" class="btn-quiet">Vazgeç</button></div>
                </form>
            @endif
            @if ($billingRows->isEmpty())
                <p class="px-4 pb-5 pt-2 text-sm text-gray-500">Sürmekte olan iş yok.</p>
            @else
                <div class="mt-2 overflow-x-auto">
                    <table class="data-table table-stack">
                        <thead><tr><th>Marka</th><th>Unvan</th><th>Vergi no</th><th>Durum</th><th class="text-right">İşlem</th></tr></thead>
                        <tbody>
                            @foreach ($billingRows as $r)
                                @php $c = $r['customer']; @endphp
                                <tr wire:key="billing-{{ $r['brand']->id }}" data-finance-billing-row="{{ $r['brand']->id }}" @class(['opacity-60' => ! $r['invoiced']])>
                                    <td class="font-medium text-gray-900 dark:text-white">{{ $r['brand']->name }}</td>
                                    <td data-label="Unvan" class="min-w-[12rem] text-gray-700 dark:text-gray-300">{{ $c?->legal_name ?: '—' }}@if ($c?->tax_office)<p class="text-xs text-gray-500">{{ $c->tax_office }} V.D.</p>@endif</td>
                                    <td data-label="Vergi no" class="whitespace-nowrap tabular-nums text-gray-700 dark:text-gray-300">{{ $c?->tax_no ?: '—' }}</td>
                                    <td data-label="Durum" class="whitespace-nowrap">
                                        @if (! $r['invoiced'])<span class="chip chip-flat">Fatura istemiyor</span>
                                        @elseif ($r['missing'] === [])<span class="chip chip-up">Tamam</span>@if ($c?->invoice_kind === 'e_fatura') <span class="chip chip-flat">e-Fatura</span>@endif
                                        @else<span class="chip chip-down">Eksik: {{ implode(', ', $r['missing']) }}</span>@endif
                                    </td>
                                    <td class="text-right"><button type="button" wire:click="editBilling({{ $r['brand']->id }})" class="btn-quiet">{{ $r['missing'] !== [] ? 'Bilgileri gir' : 'Düzenle' }}</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <aside class="space-y-4">
        <section class="card p-4" x-data="{ copied: false }" data-finance-company-sheet>
            <h2 class="section-title">Kopyalanacak şirket bilgisi</h2>
            <p class="page-sub">Bir müşteri fatura ya da hesap bilgisi istediğinde gönder.</p>
            @if ($companySheet !== '')
                <p class="mt-3 whitespace-pre-line rounded-lg border border-gray-200 p-3 text-sm text-gray-800 dark:border-gray-800 dark:text-gray-200" x-ref="sheet">{{ $companySheet }}</p>
                <button type="button" class="btn mt-2" @click="navigator.clipboard.writeText($refs.sheet.innerText); copied = true; setTimeout(() => copied = false, 1500)"><span x-show="!copied">Kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
            @else
                <p class="mt-3 text-sm text-gray-500">Şirket bilgilerini girince burada hazır olur.</p>
            @endif
        </section>

        <section class="card p-4" data-finance-customer-mail>
            <h2 class="section-title">Müşterilere e-posta</h2>
            <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                @if ($customerMail) Açık. Sistem müşteri adreslerine e-posta gönderebilir.
                @else Kapalı. Sistem hiçbir müşterinin adresine e-posta göndermez; adresler yalnız fatura için saklanır. @endif
            </p>
            <div class="mt-3">
                @if ($customerMail)
                    <button type="button" wire:click="setCustomerMail(false)" class="btn" data-finance-customer-mail-off>Kapat</button>
                @else
                    <button type="button" wire:click="setCustomerMail(true)" wire:confirm="Müşterilere e-posta gönderimi açılsın mı? Bundan sonra sistemin gönderdiği e-postalar müşteri adreslerine de gidebilir." class="btn" data-finance-customer-mail-on>Aç</button>
                @endif
            </div>
        </section>
    </aside>
</div>
