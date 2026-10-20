{{-- Claude's check of a written article (yakup 2026-10-20: every article is checked; reading it is optional). --}}
@php $verdict = $item['check']['verdict'] ?? null; @endphp
@if ($verdict === 'ok')
    <x-ta.pill tone="ok" :title="$item['check']['reason'] ?: null" data-check="ok">Claude kontrol etti</x-ta.pill>
@elseif (in_array($verdict, ['wrong', 'unsure'], true))
    <x-ta.pill tone="look" :title="$item['check']['reason'] ?: null" data-check="held">Kontrolde takıldı</x-ta.pill>
    @if ($item['check']['reason'] !== '')<span class="basis-full text-xs text-warning-700 dark:text-warning-400" data-check-reason>{{ $item['check']['reason'] }}</span>@endif
@else
    <span class="text-xs text-gray-500 dark:text-gray-400" data-check="waiting">kontrol sırada</span>
@endif
