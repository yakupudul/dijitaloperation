@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $tone = ['ok' => 'bg-emerald-50 text-emerald-700', 'warn' => 'bg-amber-50 text-amber-700', 'critical' => 'bg-rose-50 text-rose-700', 'unknown' => 'bg-gray-100 text-gray-600'];
    $label = ['ok' => 'iyi', 'warn' => 'uyarı', 'critical' => 'kritik', 'unknown' => '—'];
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-health-tab>
    <header class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Site Sağlığı</h2>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="checkNow" class="{{ $ghost }}">SSL / alan adı kontrol et</button>
            @if ($wordpress && $isAdmin)
                <form method="POST" action="{{ route('operator.integrations.wordpress-login', ['site' => $site->id]) }}" target="_blank" data-wp-login>
                    @csrf
                    <button type="submit" class="{{ $btn }}">WordPress'e giriş</button>
                </form>
            @endif
        </div>
    </header>

    @if ($message !== '')
        <p role="status" class="rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>
    @endif

    <section class="{{ $card }}">
        <table class="w-full text-left text-xs">
            <thead class="text-gray-500"><tr><th class="py-1">Kontrol</th><th class="w-20">Durum</th><th class="w-32">Tarih</th><th></th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800" data-row="{{ $row['key'] }}" wire:key="h-{{ $row['key'] }}">
                        <td class="py-1.5 font-medium">{{ $row['label'] }}</td>
                        <td><span class="rounded-full px-2 py-0.5 {{ $tone[$row['state']] }}" data-state="{{ $row['state'] }}">{{ $label[$row['state']] }}</span></td>
                        <td>{{ $row['date'] ?? '—' }}</td>
                        <td class="text-gray-600 dark:text-gray-400">{{ $row['line'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <form wire:submit="saveHosting" class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
            <label for="hosting-date" class="text-xs text-gray-500">Hosting bitiş</label>
            <input id="hosting-date" type="date" wire:model="hostingDate" class="{{ $input }} py-1">
            <button type="submit" class="{{ $ghost }}">Kaydet</button>
            @error('hostingDate')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
        </form>
    </section>
</div>
