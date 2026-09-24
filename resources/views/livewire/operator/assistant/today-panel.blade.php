@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $h = 'text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $at = fn ($value, string $format = 'd.m H:i'): string => $value ? \Illuminate\Support\Carbon::parse($value)->timezone($timezone)->format($format) : '—';
@endphp
<div class="space-y-4">
    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    @if ($today['sites_down'] !== [])
        <section class="rounded-xl bg-rose-50 p-4 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:ring-rose-500/30">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-300">Erişilemeyen siteler</h2>
            @foreach ($today['sites_down'] as $site)
                <p class="mt-1 text-sm text-rose-800 dark:text-rose-200">{{ $site['name'] }} <span class="text-xs">· {{ $site['brand'] }} · {{ $at($site['since']) }}'den beri · {{ $site['error'] }}</span></p>
            @endforeach
        </section>
    @endif

    <div class="grid gap-4 xl:grid-cols-3">
        <section class="{{ $card }}">
            <h2 class="{{ $h }}">Hatırlatıcılar</h2>
            @forelse ($today['reminders'] as $reminder)
                <div wire:key="rem-{{ $reminder->id }}" class="mt-2 flex items-start justify-between gap-2 text-sm">
                    <div class="min-w-0">
                        <p @class(['font-medium text-gray-800 dark:text-gray-200', 'text-rose-600' => $reminder->remind_at->isPast()])>{{ $reminder->title }}</p>
                        <p class="text-xs text-gray-500">{{ $at($reminder->remind_at) }}{{ $reminder->customer ? ' · '.$reminder->customer->name : '' }}{{ $reminder->repeat !== 'none' ? ' · '.\App\Models\Reminder::REPEATS[$reminder->repeat] : '' }}</p>
                    </div>
                    <button type="button" wire:click="done({{ $reminder->id }})" class="shrink-0 rounded px-2 py-0.5 text-xs ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Tamam</button>
                </div>
            @empty
                <p class="mt-2 text-sm text-gray-500">Bugün için hatırlatıcı yok.</p>
            @endforelse
            @if ($today['upcoming_reminders']->isNotEmpty())
                <p class="mt-3 text-xs text-gray-400">Bu hafta: {{ $today['upcoming_reminders']->map(fn ($r) => $r->title.' ('.$at($r->remind_at, 'd.m').')')->implode(' · ') }}</p>
            @endif
            <form wire:submit="addReminder" class="mt-3 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                <input type="text" wire:model="title" placeholder="Ne hatırlatılsın?" class="w-full rounded-lg border border-gray-200 bg-transparent px-2 py-1.5 text-sm dark:border-gray-700 dark:text-white" />
                <div class="flex flex-wrap gap-2">
                    <input type="datetime-local" wire:model="when" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-xs dark:border-gray-700 dark:text-white" />
                    <select wire:model="repeat" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-xs dark:border-gray-700 dark:text-white">@foreach (\App\Models\Reminder::REPEATS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    <select wire:model="customerId" class="max-w-40 rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-xs dark:border-gray-700 dark:text-white"><option value="">Müşteri (isteğe bağlı)</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    <button type="submit" class="rounded-lg bg-brand-500 px-3 py-1 text-xs font-semibold text-white">Ekle</button>
                </div>
                @error('title')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            </form>
        </section>

        <section class="{{ $card }}">
            <h2 class="{{ $h }}">Kime ne yazmalı</h2>
            @foreach ($today['whatsapp_waiting'] as $conversation)
                <p class="mt-2 text-sm"><span class="font-medium text-gray-800 dark:text-gray-200">{{ $conversation['name'] }}</span> <span class="text-xs text-gray-500">· WhatsApp cevap bekliyor · {{ $at($conversation['at']) }}{{ $conversation['who'] ? ' · '.$conversation['who'] : '' }}</span></p>
            @endforeach
            @foreach ($today['follow_ups'] as $prospect)
                <div wire:key="fu-{{ $prospect->id }}" class="mt-2 flex items-start justify-between gap-2 text-sm">
                    <p class="min-w-0"><a href="{{ route('operator.prospect', ['prospectId' => $prospect->id]) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-gray-200">{{ $prospect->company_name }}</a> <span class="text-xs text-gray-500">· aday takibi ({{ $prospect->next_follow_up_on?->format('d.m') }}){{ $prospect->next_step ? ' · '.$prospect->next_step : '' }}</span></p>
                    <button type="button" wire:click="followedUp({{ $prospect->id }})" class="shrink-0 rounded px-2 py-0.5 text-xs ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Yapıldı</button>
                </div>
            @endforeach
            @foreach ($today['contact'] as $row)
                <p class="mt-2 text-sm"><a href="{{ route('operator.customer', ['customerId' => $row['customer_id']]) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-gray-200">{{ $row['name'] }}</a> <span class="text-xs text-gray-500">· {{ $row['reason'] }}</span></p>
            @endforeach
            @if ($today['whatsapp_waiting'] === [] && $today['follow_ups']->isEmpty() && $today['contact'] === [])
                <p class="mt-2 text-sm text-gray-500">Bugün bekleyen iletişim yok.</p>
            @endif
        </section>

        <section class="{{ $card }}">
            <h2 class="{{ $h }}">Yaklaşan yenilemeler</h2>
            @forelse ($today['renewals'] as $renewal)
                @php
                    $days = $renewal->daysLeft();
                @endphp
                <p class="mt-2 text-sm"><span @class(['font-medium', 'text-rose-600' => $days <= 7, 'text-gray-800 dark:text-gray-200' => $days > 7])>{{ $renewal->label }}</span> <span class="text-xs text-gray-500">· {{ \App\Models\AssetRenewal::KINDS[$renewal->kind] ?? '' }} · {{ $days < 0 ? abs($days).' gün geçti' : $days.' gün' }} · {{ $renewal->brand?->name }}{{ $renewal->charge_amount !== null ? ' · '.(\App\Models\AssetRenewal::COLLECTION[$renewal->collection_status] ?? '') : '' }}</span></p>
            @empty
                <p class="mt-2 text-sm text-gray-500">30 gün içinde yenileme yok.</p>
            @endforelse
            <a href="{{ route('operator.renewals') }}" wire:navigate class="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline">Tüm yenilemeler →</a>
            <div class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-800">
                @if ($today['calendar_url'])
                    Google Takvim bağlantın (gizli tut): <input type="text" readonly value="{{ $today['calendar_url'] }}" class="mt-1 w-full rounded border border-gray-200 bg-gray-50 px-2 py-1 text-xs dark:border-gray-700 dark:bg-white/[0.03]" onclick="this.select()" />
                    <button type="button" wire:click="calendarLink" wire:confirm="Eski bağlantı çalışmaz hale gelir. Devam?" class="mt-1 text-brand-600 hover:underline">Yeni bağlantı üret</button>
                @else
                    <button type="button" wire:click="calendarLink" class="text-brand-600 hover:underline">Hatırlatıcı, yenileme ve görev tarihlerini Google Takvim'e bağla</button>
                @endif
            </div>
        </section>
    </div>
</div>
