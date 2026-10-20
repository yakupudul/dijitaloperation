@php
    $card = 'min-w-0';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $tone = ['ok' => 'ok', 'warn' => 'look', 'critical' => 'critical', 'unknown' => 'info'];
    $label = ['ok' => 'iyi', 'warn' => 'uyarı', 'critical' => 'kritik', 'unknown' => '—'];
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" data-health-tab>
    <header class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="section-title">Site Sağlığı</h2>
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
        <p role="status" class="rounded-lg bg-gray-50 p-2 text-gray-800 dark:bg-gray-950 dark:text-gray-200">{{ $message }}</p>
    @endif

    <section class="{{ $card }}">
        <div class="min-w-0 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-1">Kontrol</th><th class="font-normal w-20">Durum</th><th class="font-normal w-32">Tarih</th><th class="font-normal"></th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-gray-200 dark:border-gray-800" data-row="{{ $row['key'] }}" wire:key="h-{{ $row['key'] }}">
                        <td class="py-2.5 font-medium">{{ $row['label'] }}</td>
                        <td><x-ta.pill :tone="$tone[$row['state']]" data-state="{{ $row['state'] }}">{{ $label[$row['state']] }}</x-ta.pill></td>
                        <td>{{ $row['date'] ?? '—' }}</td>
                        <td class="text-xs text-gray-500">{{ $row['line'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <form wire:submit="saveHosting" class="mt-4 flex flex-wrap items-center gap-2">
            <label for="hosting-date" class="text-xs text-gray-500">Hosting bitiş</label>
            <input id="hosting-date" type="date" wire:model="hostingDate" class="{{ $input }} py-1">
            <button type="submit" class="{{ $ghost }}">Kaydet</button>
            @error('hostingDate')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
        </form>
    </section>
</div>
