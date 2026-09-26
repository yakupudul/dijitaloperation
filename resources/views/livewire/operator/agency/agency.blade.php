@php
    use App\Models\ContentCalendarItem;
    use App\Models\CustomerInteraction;
    use App\Models\Invoice;
    use App\Models\TimeEntry;
    use App\Livewire\Operator\Agency\AgencyPage;
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $money = fn (?float $n): string => $n === null ? '—' : number_format($n, 0, ',', '.').' TL';
    $stateLabel = ['loss' => ['Zarar', 'text-error-600'], 'thin' => ['Düşük kâr', 'text-warning-600'], 'ok' => ['Kârlı', 'text-success-600'], 'unknown' => ['Maliyet girilmemiş', 'text-gray-500']];
    $commitState = ['done' => ['Tamam', 'text-success-600'], 'on_track' => ['Yolunda', 'text-blue-600'], 'behind' => ['Geride', 'text-error-600']];
@endphp
<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Ajans işletmesi</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">Müşteri başına kârlılık (ücret − harcanan süre), faturalar ve tahsilat, aylık teslimat taahhütleri, zaman kaydı ve müşteri iletişimi. Vadesi geçen fatura, geride kalan taahhüt ve günü gelen takipler Komuta merkezinde de görünür.</p>
        </div>
        <label class="text-sm"><span class="block text-xs text-gray-500">Ay</span>
            <select wire:model.live="month" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($months as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
    </div>

    <nav class="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-800">
        @foreach (AgencyPage::TABS as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['-mb-px border-b-2 px-3 py-2 text-sm', 'border-brand-500 font-semibold text-brand-600' => $tab === $key, 'border-transparent text-gray-500' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'profit')
        <section class="{{ $card }} flex flex-wrap items-end gap-3 p-4">
            <label class="text-sm"><span class="text-xs text-gray-500">Saatlik maliyetiniz (TL)</span><input wire:model="hourlyCost" type="text" inputmode="decimal" class="{{ $input }} w-40"></label>
            @if ($isAdmin)<button type="button" wire:click="saveHourlyCost" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Kaydet</button>@endif
            <p class="text-xs text-gray-500">Kâr = aylık ücret − (kayıtlı saat × saatlik maliyet). "Saat başı gelir" müşterinin bir saatinizi kaça aldığıdır.</p>
        </section>
        <section class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs text-gray-500"><tr><th class="px-4 py-3">Müşteri</th><th class="py-3 text-right">Aylık ücret</th><th class="py-3 text-right">Saat</th><th class="py-3 text-right">Maliyet</th><th class="py-3 text-right">Kâr</th><th class="py-3 text-right">Saat başı gelir</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($profit as $row)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('operator.customer', ['customerId' => $row['customer_id']]) }}" wire:navigate class="hover:text-brand-600">{{ $row['customer'] }}</a></td>
                            <td class="py-2 text-right tabular-nums">{{ $money($row['fee']) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $row['hours'] }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $money($row['cost']) }}</td>
                            <td class="py-2 text-right font-semibold tabular-nums">{{ $money($row['margin']) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $row['rate'] !== null ? $money((float) $row['rate']) : '—' }}</td>
                            <td class="px-4 py-2 text-xs {{ $stateLabel[$row['state']][1] }}">{{ $stateLabel[$row['state']][0] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-5 text-sm text-gray-500">Aylık ücreti girilmiş ya da süre kaydı olan müşteri yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif

    @if ($tab === 'invoices')
        <form wire:submit="addInvoice" class="{{ $card }} grid gap-3 p-4 sm:grid-cols-6">
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Müşteri</span><select wire:model="invoice.customer_id" class="{{ $input }}"><option value="">Seçin</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Dönem</span><input wire:model="invoice.period" type="month" class="{{ $input }}"></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Tutar</span><input wire:model="invoice.amount" type="number" step="0.01" class="{{ $input }}"></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Vade</span><input wire:model="invoice.due_on" type="date" class="{{ $input }}"></label>
            <div class="flex items-end gap-2"><button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Ekle</button></div>
            @error('invoice.customer_id')<p class="text-xs text-error-600 sm:col-span-6">{{ $message }}</p>@enderror
        </form>
        <div class="flex justify-end"><button type="button" wire:click="draftInvoices" class="text-xs text-brand-600 hover:underline">Bu ay için aylık ücretlerden taslak faturaları oluştur</button></div>
        <section class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs text-gray-500"><tr><th class="px-4 py-3">Müşteri</th><th class="py-3">Dönem</th><th class="py-3 text-right">Tutar</th><th class="py-3">Vade</th><th class="py-3">Durum</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($invoices as $inv)
                        <tr wire:key="inv-{{ $inv->id }}">
                            <td class="px-4 py-2">{{ $inv->customer?->name }}</td>
                            <td class="py-2">{{ $inv->period }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $money((float) $inv->amount) }}</td>
                            <td class="py-2 {{ $inv->isOverdue() ? 'font-semibold text-error-600' : '' }}">{{ $inv->due_on?->format('d.m.Y') ?? '—' }}</td>
                            <td class="py-2 text-xs">{{ Invoice::STATUSES[$inv->status] ?? $inv->status }}@if ($inv->paid_on) · {{ $inv->paid_on->format('d.m') }}@endif</td>
                            <td class="px-4 py-2 text-right text-xs">
                                @if ($inv->status === 'draft')<button type="button" wire:click="setInvoiceStatus({{ $inv->id }}, 'issued')" class="text-brand-600 hover:underline">Kesildi</button>@endif
                                @if (in_array($inv->status, ['draft', 'issued'], true))
                                    <button type="button" wire:click="setInvoiceStatus({{ $inv->id }}, 'paid')" class="ml-2 text-success-600 hover:underline">Ödendi</button>
                                    <button type="button" wire:click="setInvoiceStatus({{ $inv->id }}, 'cancelled')" wire:confirm="İptal edilsin mi?" class="ml-2 text-gray-400 hover:underline">İptal</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-5 text-sm text-gray-500">Bu dönem fatura kaydı yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif

    @if ($tab === 'commitments')
        <form wire:submit="addCommitment" class="{{ $card }} grid gap-3 p-4 sm:grid-cols-6">
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Müşteri</span><select wire:model="commitment.customer_id" class="{{ $input }}"><option value="">Seçin</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Söz verilen iş</span><input wire:model="commitment.title" type="text" placeholder="Aylık blog yazısı" class="{{ $input }}"></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Ayda kaç</span><input wire:model="commitment.monthly_quantity" type="number" min="1" class="{{ $input }}"></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Kendiliğinden say</span><select wire:model="commitment.counts_from" class="{{ $input }}"><option value="">Elle işaretlerim</option>@foreach (ContentCalendarItem::CHANNELS as $key => $label)<option value="{{ $key }}">Takvim: {{ $label }}</option>@endforeach</select></label>
            <div class="sm:col-span-6"><button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Taahhüt ekle</button></div>
        </form>
        <section class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs text-gray-500"><tr><th class="px-4 py-3">Müşteri</th><th class="py-3">İş</th><th class="py-3 text-right">Bu ay</th><th class="py-3">Durum</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($commitments as $row)
                        <tr wire:key="cm-{{ $row['id'] }}">
                            <td class="px-4 py-2">{{ $row['customer'] }}@if ($row['brand'])<span class="text-xs text-gray-500"> · {{ $row['brand'] }}</span>@endif</td>
                            <td class="py-2">{{ $row['title'] }}@if ($row['counts_from'])<span class="text-xs text-gray-500"> (takvimden {{ $row['auto'] }})</span>@endif</td>
                            <td class="py-2 text-right tabular-nums">{{ $row['done'] }} / {{ $row['quantity'] }}</td>
                            <td class="py-2 text-xs {{ $commitState[$row['state']][1] }}">{{ $commitState[$row['state']][0] }}</td>
                            <td class="px-4 py-2 text-right text-xs">
                                <button type="button" wire:click="markCommitment({{ $row['id'] }}, 1)" class="rounded bg-success-50 px-2 py-0.5 text-success-700">+1 yapıldı</button>
                                @if ($row['manual'] > 0)<button type="button" wire:click="markCommitment({{ $row['id'] }}, -1)" class="ml-1 text-gray-500">−1</button>@endif
                                <button type="button" wire:click="stopCommitment({{ $row['id'] }})" wire:confirm="Bu taahhüt artık takip edilmesin mi?" class="ml-2 text-gray-400 hover:underline">Bitir</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-5 text-sm text-gray-500">Takip edilen taahhüt yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif

    @if ($tab === 'time')
        <form wire:submit="addTime" class="{{ $card }} grid gap-3 p-4 sm:grid-cols-6">
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Müşteri</span><select wire:model="time.customer_id" class="{{ $input }}"><option value="">Seçin</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Gün</span><input wire:model="time.worked_on" type="date" class="{{ $input }}"></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Dakika</span><input wire:model="time.minutes" type="number" min="5" step="5" class="{{ $input }}"></label>
            <label class="text-sm"><span class="text-xs text-gray-500">İş</span><select wire:model="time.category" class="{{ $input }}">@foreach (TimeEntry::CATEGORIES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Not</span><input wire:model="time.note" type="text" class="{{ $input }}"></label>
            <div class="sm:col-span-6"><button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Süre ekle</button>@error('time.customer_id')<span class="ml-3 text-xs text-error-600">{{ $message }}</span>@enderror</div>
        </form>
        <section class="{{ $card }}">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($entries as $entry)
                    <li class="flex justify-between gap-3 px-4 py-2 text-sm"><span>{{ $entry->worked_on->format('d.m') }} · {{ $entry->customer?->name }} · {{ TimeEntry::CATEGORIES[$entry->category] ?? $entry->category }}@if ($entry->note)<span class="text-gray-500"> — {{ $entry->note }}</span>@endif</span><span class="tabular-nums">{{ round($entry->minutes / 60, 2) }} sa</span></li>
                @empty
                    <li class="p-5 text-sm text-gray-500">Bu ay süre kaydı yok.</li>
                @endforelse
            </ul>
        </section>
    @endif

    @if ($tab === 'contacts')
        <form wire:submit="addContact" class="{{ $card }} grid gap-3 p-4 sm:grid-cols-6">
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Müşteri</span><select wire:model="contact.customer_id" class="{{ $input }}"><option value="">Seçin</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Kanal</span><select wire:model="contact.channel" class="{{ $input }}">@foreach (CustomerInteraction::CHANNELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Yön</span><select wire:model="contact.direction" class="{{ $input }}"><option value="out">Ben ulaştım</option><option value="in">Müşteri ulaştı</option></select></label>
            <label class="text-sm sm:col-span-6"><span class="text-xs text-gray-500">Ne konuşuldu</span><textarea wire:model="contact.summary" rows="2" class="{{ $input }}"></textarea>@error('contact.summary')<span class="text-xs text-error-600">{{ $message }}</span>@enderror</label>
            <label class="text-sm sm:col-span-4"><span class="text-xs text-gray-500">Sonraki adım (isteğe bağlı)</span><input wire:model="contact.next_action" type="text" class="{{ $input }}"></label>
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Ne zaman</span><input wire:model="contact.next_action_at" type="datetime-local" class="{{ $input }}"></label>
            <div class="sm:col-span-6"><button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Kaydet</button></div>
        </form>
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="{{ $card }} p-4">
                <h2 class="font-semibold text-gray-800 dark:text-white">Yaklaşan takipler</h2>
                @forelse ($upcoming as $row)
                    <p class="mt-2 text-sm"><span class="{{ $row->next_action_at->isPast() ? 'font-semibold text-error-600' : 'text-gray-500' }}">{{ $row->next_action_at->format('d.m H:i') }}</span> · {{ $row->customer?->name }} — {{ $row->next_action }}</p>
                @empty
                    <p class="mt-2 text-sm text-gray-500">Bekleyen takip yok.</p>
                @endforelse
            </section>
            <section class="{{ $card }} p-4">
                <h2 class="font-semibold text-gray-800 dark:text-white">Son görüşmeler</h2>
                @forelse ($contacts as $row)
                    <div class="mt-2 border-t border-gray-100 pt-2 text-sm dark:border-gray-800">
                        <div class="text-xs text-gray-500">{{ $row->occurred_at->format('d.m H:i') }} · {{ $row->customer?->name }} · {{ CustomerInteraction::CHANNELS[$row->channel] ?? $row->channel }} · {{ $row->direction === 'in' ? 'gelen' : 'giden' }}</div>
                        <p>{{ $row->summary }}</p>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-gray-500">Kayıtlı görüşme yok.</p>
                @endforelse
            </section>
        </div>
    @endif
</div>
