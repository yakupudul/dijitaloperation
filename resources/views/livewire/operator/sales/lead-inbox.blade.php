@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $sources = ['web_form' => 'Web formu', 'phone' => 'Telefon', 'whatsapp' => 'WhatsApp', 'meta_lead_ad' => 'Meta form reklamı', 'manual' => 'Elle'];
    $endpoint = $newToken !== null ? route('api.leads.receive', ['token' => $newToken]) : null;
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Lead kutusu</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Ajansın kendi web sitesindeki formdan ve elle eklenen talepler. Aynı telefondan 24 saat içinde gelen ikinci talep ilkine eklenir; yeni talep telefonuna bildirim olarak gelir. "Adaya dönüştür" potansiyel müşteri kaydı açar (takip: yarın).</p>
    </div>

    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif

    <div class="flex flex-wrap gap-2">
        @foreach (['open' => 'Açık', 'new' => 'Yeni', 'contacted' => 'Arandı / yazıldı', 'converted' => 'Adaya dönüştü', 'lost' => 'Olmadı', 'spam' => 'Spam', 'all' => 'Hepsi'] as $key => $label)
            <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['rounded-full px-3 py-1 text-xs ring-1 ring-inset', 'bg-brand-50 text-brand-700 ring-brand-300' => $status === $key, 'text-gray-600 ring-gray-300' => $status !== $key])>{{ $label }}@if (isset($counts[$key])) ({{ $counts[$key] }})@endif</button>
        @endforeach
    </div>

    <section class="{{ $card }}">
        @forelse ($leads as $lead)
            <div wire:key="lead-{{ $lead->id }}" class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 py-3 last:border-0 dark:border-gray-800">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $lead->company ?: ($lead->name ?: 'İsimsiz') }}@if ($lead->company && $lead->name) <span class="text-gray-500">· {{ $lead->name }}</span>@endif</p>
                    <p class="text-xs text-gray-500">{{ $sources[$lead->source] ?? $lead->source }} · {{ \Illuminate\Support\Carbon::parse($lead->received_at)->format('d.m.Y H:i') }}@if ($lead->phone) · <a href="tel:{{ preg_replace('/[^\d+]/', '', $lead->phone) }}" class="text-brand-600">{{ $lead->phone }}</a>@endif @if ($lead->email) · {{ $lead->email }}@endif</p>
                    @if ($lead->message)<p class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($lead->message, 500) }}</p>@endif
                    @if ($lead->page_url || $lead->utm)<p class="mt-1 text-xs text-gray-400">{{ $lead->page_url }} {{ $lead->utm ? implode(' · ', array_map(fn ($k, $v) => $k.'='.$v, array_keys((array) json_decode($lead->utm, true)), (array) json_decode($lead->utm, true))) : '' }}</p>@endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($lead->status === 'converted' && $lead->prospect_id)
                        <a href="{{ route('operator.prospect', ['prospectId' => $lead->prospect_id]) }}" wire:navigate class="text-xs text-brand-600 hover:underline">Adayı aç →</a>
                    @else
                        <select wire:change="setStatus({{ $lead->id }}, $event.target.value)" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                            @foreach ($statuses as $key => $label)@if ($key !== 'converted')<option value="{{ $key }}" @selected($lead->status === $key)>{{ $label }}</option>@endif @endforeach
                        </select>
                        <x-ta.button type="button" wire:click="convert({{ $lead->id }})" size="sm" variant="outline">Adaya dönüştür</x-ta.button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Bu filtrede talep yok.</p>
        @endforelse
    </section>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Elle ekle</h2>
        <div class="mt-2 grid gap-2 sm:grid-cols-3">
            <input type="text" wire:model="manual.name" placeholder="Ad" class="{{ $input }}">
            <input type="text" wire:model="manual.company" placeholder="Firma" class="{{ $input }}">
            <input type="text" wire:model="manual.phone" placeholder="Telefon" class="{{ $input }}">
            <input type="email" wire:model="manual.email" placeholder="E-posta" class="{{ $input }}">
            <select wire:model="manual.source" class="{{ $input }}"><option value="phone">Telefon</option><option value="whatsapp">WhatsApp</option><option value="manual">Diğer</option><option value="referral">Tavsiye</option></select>
            <input type="text" wire:model="manual.message" placeholder="Not" class="{{ $input }}">
        </div>
        @error('phone')<p class="mt-1 text-xs text-rose-600">Telefon ya da e-posta gerekli.</p>@enderror
        <x-ta.button type="button" wire:click="addManual" size="sm" variant="outline" class="mt-2">Ekle</x-ta.button>
    </section>

    @if ($isAdmin)
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Web sitesi formu bağlantısı</h2>
            <p class="mt-1 text-xs text-gray-500">Ajans sitesindeki formun gönderim adresi. Alanlar: ad / name, firma / company, telefon / phone, email, mesaj / message, sayfa / page, utm_*; <code>website_hp</code> gizli spam tuzağıdır (dolu gelirse spam). <code>redirect</code> verilirse gönderimden sonra o sayfaya döner.</p>
            @if ($endpoint !== null)
                <p class="mt-2 break-all rounded bg-gray-50 p-2 font-mono text-xs dark:bg-gray-800">{{ $endpoint }}</p>
                <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-2 text-xs dark:bg-gray-800">{{ '<form method="post" action="'.$endpoint.'">
  <input name="ad" placeholder="Ad Soyad">
  <input name="telefon" placeholder="Telefon" required>
  <textarea name="mesaj"></textarea>
  <input name="website_hp" style="display:none" tabindex="-1" autocomplete="off">
  <input type="hidden" name="redirect" value="https://ajansiniz.com/tesekkurler">
  <button>Gönder</button>
</form>' }}</pre>
            @else
                <p class="mt-2 text-sm">{{ $hint ? 'Etkin adres …'.$hint.' ile bitiyor.' : 'Henüz adres yok.' }}</p>
            @endif
            <x-ta.button type="button" wire:click="rotateToken" wire:confirm="Yeni adres oluşturulsun mu? Eski adres çalışmayı bırakır." size="sm" variant="outline" class="mt-2">{{ $hint ? 'Adresi yenile' : 'Adres oluştur' }}</x-ta.button>
        </section>
    @endif
</div>
