@php
    use App\Models\FinanceDocument;

    $direction = $doc['direction'] ?? FinanceDocument::IN;
    $isExpense = $direction === FinanceDocument::IN;
    $who = ['in' => 'Nereye ödendi', 'out' => 'Kime kesildi', 'receipt' => 'Kim gönderdi'][$direction] ?? 'Karşı taraf';
@endphp
<form wire:submit="saveDoc" class="card mt-4 p-4 sm:p-5" data-finance-doc-form>
    <div class="flex items-center justify-between gap-3">
        <h2 class="section-title">{{ $addingExpense ? 'Gider ekle' : 'Belgeyi düzelt' }}</h2>
        <div class="flex items-center gap-2">
            @if (($editing ?? null)?->hasFile())
                <a href="{{ route('operator.finance.document', $editing) }}" target="_blank" rel="noopener" class="btn-quiet">Dosyayı aç</a>
            @endif
            <button type="button" wire:click="closeDoc" class="btn-quiet">Vazgeç</button>
        </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @unless ($addingExpense)
            <div>
                <label class="{{ $label }}" for="doc-direction">Türü</label>
                <select id="doc-direction" wire:model.live="doc.direction" class="{{ $input }}">
                    @foreach (FinanceDocument::DIRECTIONS as $key => $name)
                        <option value="{{ $key }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <div class="lg:col-span-2">
            <label class="{{ $label }}" for="doc-who">{{ $who }}</label>
            <input id="doc-who" type="text" wire:model="doc.counterparty" class="{{ $input }}" placeholder="{{ $isExpense ? 'Google, Hostinger, Turkcell…' : '' }}">
            @if ($err('doc.counterparty'))<p class="mt-1 text-xs text-red-600">{{ $err('doc.counterparty') }}</p>@endif
        </div>
        <div>
            <label class="{{ $label }}" for="doc-date">Tarih</label>
            <input id="doc-date" type="date" wire:model="doc.doc_date" class="{{ $input }}">
        </div>
        <div>
            <label class="{{ $label }}" for="doc-amount">Tutar (KDV dahil)</label>
            <input id="doc-amount" type="text" inputmode="decimal" wire:model="doc.amount" class="{{ $input }}" placeholder="1.250,00">
            @if ($err('doc.amount'))<p class="mt-1 text-xs text-red-600">{{ $err('doc.amount') }}</p>@endif
        </div>
        <div>
            <label class="{{ $label }}" for="doc-currency">Para birimi</label>
            <select id="doc-currency" wire:model="doc.currency" class="{{ $input }}">
                <option value="TRY">₺ Türk lirası</option><option value="USD">$ Dolar</option><option value="EUR">€ Euro</option><option value="GBP">£ Sterlin</option>
            </select>
        </div>
        <div>
            <label class="{{ $label }}" for="doc-no">Belge no</label>
            <input id="doc-no" type="text" wire:model="doc.doc_no" class="{{ $input }}">
        </div>
        <div>
            <label class="{{ $label }}" for="doc-tax">Vergi / TC kimlik no</label>
            <input id="doc-tax" type="text" inputmode="numeric" wire:model="doc.counterparty_tax_no" class="{{ $input }}">
        </div>

        @if ($isExpense)
            <div>
                <label class="{{ $label }}" for="doc-category">Gider türü</label>
                <select id="doc-category" wire:model="doc.category" class="{{ $input }}">
                    @foreach (FinanceDocument::CATEGORIES as $key => $name)
                        <option value="{{ $key }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-2">
                <label class="{{ $label }}" for="doc-brand">Bir müşteri için mi ödendi</label>
                <select id="doc-brand" wire:model.live="doc.brand_id" class="{{ $input }}">
                    <option value="">Hayır, kendi giderim</option>
                    @foreach ($brandOptions as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            @if (filled($doc['brand_id'] ?? ''))
                <label class="flex items-end gap-2 pb-2 text-sm text-gray-800 dark:text-gray-200">
                    <input type="checkbox" wire:model="doc.rebill" class="rounded border-gray-300">Müşteriden geri istenecek
                </label>
            @endif
        @else
            <div class="lg:col-span-2">
                <label class="{{ $label }}" for="doc-agreement">Hangi işe ait</label>
                <select id="doc-agreement" wire:model="doc.agreement_id" class="{{ $input }}">
                    <option value="">Seç</option>
                    @foreach ($agreementOptions as $a)
                        <option value="{{ $a->id }}">{{ $a->name() }} · {{ $a->feeLabel() }}{{ $a->status !== 'active' ? ' (bitti)' : '' }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="sm:col-span-2 lg:col-span-4">
            <label class="{{ $label }}" for="doc-note">Not</label>
            <input id="doc-note" type="text" wire:model="doc.note" class="{{ $input }}">
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="saveDoc">Kaydet</button>
        <button type="button" wire:click="closeDoc" class="btn">Vazgeç</button>
        @if (! $addingExpense && in_array(($editing ?? null)?->source, ['manual', 'upload'], true))
            <button type="button" wire:click="deleteDoc({{ $docId }})" wire:confirm="Bu belge ve dosyası silinsin mi?" class="btn-quiet ml-auto text-red-600">Sil</button>
        @endif
    </div>
</form>
