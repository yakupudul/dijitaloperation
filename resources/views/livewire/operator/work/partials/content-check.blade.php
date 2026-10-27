{{-- Claude's check of a written article (yakup 2026-10-20: every article is checked; reading it is optional).
     yakup 2026-10-27: what the check holds back Claude corrects by itself; only what it could not fix shows its reason. --}}
@php $verdict = $item['check']['verdict'] ?? null; @endphp
@if ($item['fixing'] ?? false)
    <x-ta.pill tone="live" :title="($item['check']['reason'] ?? '') ?: null" data-check="fixing">Claude düzeltiyor</x-ta.pill>
@elseif ($verdict === 'ok')
    <x-ta.pill tone="ok" :title="$item['check']['reason'] ?: null" data-check="ok">Claude kontrol etti</x-ta.pill>
@elseif (in_array($verdict, ['wrong', 'unsure'], true))
    <x-ta.pill tone="look" :title="$item['check']['reason'] ?: null" data-check="held">Claude düzeltemedi</x-ta.pill>
    @if ($item['check']['reason'] !== '')<span class="basis-full text-xs text-warning-700 dark:text-warning-400" data-check-reason>{{ \App\Services\Work\ContentBoard::plainReason($item['check']['reason']) }}. Okuyup uygunsa «Yine de gönder».</span>@endif
@else
    <span class="text-xs text-gray-500 dark:text-gray-400" data-check="waiting">kontrol sırada</span>
@endif
