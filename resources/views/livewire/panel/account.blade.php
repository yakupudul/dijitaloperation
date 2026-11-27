@php
    use App\Panel\Services\PanelAccount;
    use App\Services\Finance\CompanyProfile;
    $tz = 'Europe/Istanbul';
    $tl = fn ($amount) => PanelAccount::money($amount);
    $vat = fn ($agreement) => $agreement?->no_invoice ? '' : ' + KDV';
@endphp
<div class="space-y-8" data-panel-account="{{ $tab }}">
    @if ($tab === 'hizmetler')
        <section>
            <div class="section-head"><h2 class="section-title">Aldığınız hizmetler</h2></div>
            <div class="card" data-panel-services>
                @forelse ($agreements as $agreement)
                    <div class="card-row" wire:key="agreement-{{ $agreement->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-medium text-gray-900">{{ filled($agreement->title) ? $agreement->title : (collect((array) $agreement->services)->map(fn ($key) => PanelAccount::SERVICES[$key][0] ?? null)->filter()->implode(', ') ?: 'Hizmet') }}</p>
                            <p class="text-sm text-gray-500">{{ $agreement->kindLabel() }}@if ($agreement->starts_on), {{ $agreement->starts_on->locale('tr')->isoFormat('D MMMM YYYY') }} tarihinden beri @endif</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-[15px] font-medium tabular-nums text-gray-900">{{ $agreement->feeLabel() }}{{ $agreement->kind === 'partnership' && (int) $agreement->fee === 0 ? '' : $vat($agreement) }}</p>
                            <p class="text-sm text-gray-500">{{ $agreement->status === 'paused' ? 'Durduruldu' : 'Sürüyor' }}</p>
                        </div>
                    </div>
                @empty
                    <x-ta.empty-state size="sm" icon="none" title="Kayıtlı bir hizmet görünmüyor." message="Bir yanlışlık olduğunu düşünüyorsanız Destek bölümünden yazın." />
                @endforelse
            </div>
        </section>

        @if ($extras !== [])
            <section>
                <div class="section-head"><h2 class="section-title">Ekleyebileceğiniz hizmetler</h2></div>
                <div class="card" data-panel-extras>
                    @foreach ($extras as $key => [$name, $line])
                        <div class="card-row flex-wrap" wire:key="extra-{{ $key }}">
                            <div class="min-w-0 flex-1 basis-60">
                                <p class="text-[15px] font-medium text-gray-900">{{ $name }}</p>
                                <p class="text-sm text-gray-500">{{ $line }}</p>
                            </div>
                            @if ($canAsk)
                                <button type="button" wire:click="offer('{{ $key }}')" wire:loading.attr="disabled" class="btn">Teklif iste</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @elseif ($tab === 'faturalar')
        @if ($takesInvoices)
            <section>
                <div class="section-head"><h2 class="section-title">Faturalarınız</h2><span class="section-count">{{ $invoices->count() }}</span></div>
                <div class="card" data-panel-invoices>
                    @forelse ($invoices as $invoice)
                        <a href="{{ route('panel.invoice', $invoice->id) }}" class="card-row hover:bg-soft" wire:key="invoice-{{ $invoice->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[15px] font-medium text-gray-900">{{ $invoice->doc_date?->locale('tr')->isoFormat('D MMMM YYYY') }}{{ filled($invoice->doc_no) ? ', '.$invoice->doc_no : '' }}</span>
                                <span class="block truncate text-sm text-gray-500">{{ $invoice->amount !== null ? $tl($invoice->amount).' (KDV dahil)' : 'Fatura' }}</span>
                            </span>
                            <span class="shrink-0 text-sm font-medium text-primary">İndir</span>
                        </a>
                    @empty
                        <x-ta.empty-state size="sm" icon="none" title="Henüz fatura yüklenmedi." message="Kesilen her fatura burada durur; e-posta aramanıza gerek kalmaz." />
                    @endforelse
                </div>
            </section>
        @else
            <section>
                <div class="section-head"><h2 class="section-title">Ödeme geçmişi</h2><span class="section-count">{{ $payments['paid']->count() }}</span></div>
                <div class="card" data-panel-history>
                    @forelse ($payments['paid'] as $row)
                        <div class="card-row" wire:key="history-{{ $row->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[15px] font-medium text-gray-900">{{ $row->title() }}</span>
                                <span class="block truncate text-sm text-gray-500">{{ $row->paid_on->locale('tr')->isoFormat('D MMMM YYYY') }} günü ödendi</span>
                            </span>
                            <span class="shrink-0 text-[15px] font-medium tabular-nums text-gray-900">{{ $tl($row->received()) }}</span>
                        </div>
                    @empty
                        <x-ta.empty-state size="sm" icon="none" title="Henüz işlenmiş bir ödeme yok." />
                    @endforelse
                </div>
            </section>
        @endif
    @elseif ($tab === 'odemeler')
        <section class="metric-strip grid-cols-2" data-panel-debt>
            <div class="bg-white px-5 py-4">
                <span class="block text-sm text-gray-500">Bugün itibarıyla ödenecek</span>
                <span class="mt-1.5 block text-[28px] leading-none font-semibold tracking-[-0.03em] tabular-nums text-gray-900">{{ $tl($payments['debt']) }}</span>
            </div>
            <div class="bg-white px-5 py-4">
                <span class="block text-sm text-gray-500">Günü geçen ödeme</span>
                <span class="mt-1.5 block text-[28px] leading-none font-semibold tracking-[-0.03em] tabular-nums text-gray-900">{{ $payments['late'] }}</span>
            </div>
        </section>

        <section>
            <div class="section-head"><h2 class="section-title">Bekleyen ödemeler</h2><span class="section-count">{{ $payments['open']->count() }}</span></div>
            <div class="card" data-panel-open-payments>
                @forelse ($payments['open'] as $row)
                    <div class="card-row flex-wrap" wire:key="open-{{ $row->id }}">
                        <div class="min-w-0 flex-1 basis-56">
                            <p class="truncate text-[15px] font-medium text-gray-900">{{ $row->title() }}{{ filled($row->agreement?->title) ? ', '.$row->agreement->title : '' }}</p>
                            <p class="truncate text-sm {{ $row->isOverdue() ? 'text-error-700' : 'text-gray-500' }}">
                                {{ $row->isOverdue() ? $row->daysLate().' gün gecikti, son gün '.$row->due_on->locale('tr')->isoFormat('D MMMM').' idi' : 'Son gün '.$row->due_on?->locale('tr')->isoFormat('D MMMM YYYY') }}
                            </p>
                        </div>
                        <span class="shrink-0 text-[15px] font-medium tabular-nums text-gray-900">{{ $tl($row->open()) }}{{ $vat($row->agreement) }}</span>
                        @if (isset($sent[$row->id]))
                            <span class="shrink-0 text-sm text-gray-500">Dekont {{ $sent[$row->id]->timezone($tz)->locale('tr')->isoFormat('D MMMM') }} günü ulaştı, bankada bakılıyor</span>
                        @else
                            <button type="button" wire:click="$set('paying', {{ $row->id }})" class="btn">Dekont yükle</button>
                        @endif
                    </div>
                    @if ($paying === $row->id)
                        <form wire:submit="sendReceipt" class="flex flex-wrap items-center gap-2 border-t border-line bg-soft px-4 py-3" wire:key="pay-{{ $row->id }}" data-panel-receipt-form>
                            <label class="btn cursor-pointer bg-white"><input type="file" wire:model="receipt" class="sr-only" accept=".pdf,image/*">{{ $receipt ? 'Dosya seçildi' : 'Dekontu seç' }}</label>
                            <span wire:loading wire:target="receipt" class="text-sm text-gray-500">Yükleniyor</span>
                            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="receipt,sendReceipt">Gönder</button>
                            <button type="button" wire:click="$set('paying', null)" class="btn-quiet">Vazgeç</button>
                            @error('receipt')<span class="basis-full text-sm text-error-600">{{ $message }}</span>@enderror
                        </form>
                    @endif
                @empty
                    <x-ta.empty-state size="sm" title="Bekleyen ödemeniz yok." />
                @endforelse
            </div>
        </section>

        @if ($bank && $bank['iban'] !== '')
            <section x-data="{ copied: false }">
                <div class="section-head"><h2 class="section-title">Havale bilgileri</h2></div>
                <div class="card" data-panel-bank>
                    <div class="card-row"><span class="w-32 shrink-0 text-sm text-gray-500">Hesap sahibi</span><span class="min-w-0 flex-1 text-[15px] text-gray-900">{{ $bank['holder'] }}</span></div>
                    @if ($bank['bank'] !== '')
                        <div class="card-row"><span class="w-32 shrink-0 text-sm text-gray-500">Banka</span><span class="min-w-0 flex-1 text-[15px] text-gray-900">{{ $bank['bank'] }}</span></div>
                    @endif
                    <div class="card-row flex-wrap">
                        <span class="w-32 shrink-0 text-sm text-gray-500">IBAN</span>
                        <span class="min-w-0 flex-1 basis-56 text-[15px] tabular-nums text-gray-900">{{ CompanyProfile::formatIban($bank['iban']) }}</span>
                        <button type="button" class="btn" x-on:click="navigator.clipboard.writeText(@js($bank['iban'])); copied = true; setTimeout(() => copied = false, 2000)" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button>
                    </div>
                </div>
            </section>
        @endif

        @if ($payments['paid']->isNotEmpty())
            <section>
                <div class="section-head"><h2 class="section-title">Ödenenler</h2></div>
                <div class="card" data-panel-paid>
                    @foreach ($payments['paid'] as $row)
                        <div class="card-row" wire:key="paid-{{ $row->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[15px] font-medium text-gray-900">{{ $row->title() }}{{ filled($row->agreement?->title) ? ', '.$row->agreement->title : '' }}</span>
                                <span class="block truncate text-sm text-gray-500">{{ $row->paid_on->locale('tr')->isoFormat('D MMMM YYYY') }} günü ödendi</span>
                            </span>
                            <span class="shrink-0 text-[15px] tabular-nums text-gray-900">{{ $tl($row->received()) }}{{ $vat($row->agreement) }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <section>
            <div class="section-head"><h2 class="section-title">Yıllık yenilemeler</h2><span class="section-count">{{ $renewals->count() }}</span></div>
            <div class="card" data-panel-renewals>
                @forelse ($renewals as $row)
                    @php($soon = $row['days'] !== null && $row['days'] <= 30)
                    <div class="card-row flex-wrap" wire:key="renewal-{{ $row['agreement']->id }}">
                        <div class="min-w-0 flex-1 basis-56">
                            <p class="truncate text-[15px] font-medium text-gray-900">{{ filled($row['agreement']->title) ? $row['agreement']->title : 'Yıllık hizmet' }}</p>
                            <p class="truncate text-sm {{ $soon && $row['days'] < 0 ? 'text-error-700' : 'text-gray-500' }}">
                                @if ($row['due'] === null) Yenileme günü yazılmadı
                                @elseif ($row['days'] < 0) Yenileme günü {{ abs($row['days']) }} gün önce geçti
                                @elseif ($row['days'] === 0) Bugün yenileniyor
                                @else {{ $row['due']->locale('tr')->isoFormat('D MMMM YYYY') }} günü yenilenir, {{ $row['days'] }} gün kaldı
                                @endif
                            </p>
                        </div>
                        <span class="shrink-0 text-[15px] font-medium tabular-nums text-gray-900">{{ $tl($row['agreement']->fee) }}/yıl{{ $vat($row['agreement']) }}</span>
                        @if ($soon)
                            <a href="{{ route('panel.payments') }}" wire:navigate class="btn">Ödemeye git</a>
                        @endif
                    </div>
                @empty
                    <x-ta.empty-state size="sm" icon="none" title="Yıllık yenilenen bir hizmetiniz yok." message="Alan adı ya da sunucu bizdeyse yenileme günü ve bedeli burada görünür." />
                @endforelse
            </div>
            <p class="mt-3 text-sm text-gray-500">Yenileme günü yaklaşınca buradan ve telefonla haber veririz.</p>
        </section>
    @endif
</div>
