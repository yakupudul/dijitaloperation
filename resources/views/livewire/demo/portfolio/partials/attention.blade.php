{{-- Attention cell (panel redesign 2026-10-19): a badge only for a real exception, otherwise the reason as a quiet line. --}}
@if ($needsAttention)
    @if ($isException)<x-ta.pill :tone="$critical ? 'critical' : 'look'">Dikkat</x-ta.pill>@endif
    @if (filled($reason))<p class="mt-0.5 max-w-xs break-words text-xs text-gray-500">{{ $reason }}</p>@endif
@endif
