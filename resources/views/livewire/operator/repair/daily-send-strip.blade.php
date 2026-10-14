@php
    $ready = $summary['content'] + $summary['posts'] + $summary['repair'];
    $n = fn (int $v): string => number_format($v, 0, ',', '.');
@endphp
<div class="rounded-xl bg-white p-4 sm:p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-daily-send @if ($running) wire:poll.10s @endif>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Günlük gönderim</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                AI kontrolünden geçen: <span class="font-medium text-gray-900 dark:text-white" data-daily-content>{{ $n($summary['content']) }} yazı</span> ·
                <span class="font-medium text-gray-900 dark:text-white" data-daily-posts>{{ $n($summary['posts']) }} profil gönderisi</span> ·
                <span class="font-medium text-gray-900 dark:text-white" data-daily-repair>{{ $n($summary['repair']) }} onarım</span>
                @if ($summary['held_total'] > 0)
                    · <button type="button" wire:click="$toggle('showHeld')" class="text-amber-700 underline dark:text-amber-300" data-daily-held>{{ $n($summary['held_total']) }} iş kontrolde kaldı</button>
                @endif
            </p>
            @if (is_array($last))
                <p class="mt-1 text-xs text-gray-500" data-daily-last>
                    @if (isset($last['error']))
                        Son gönderim yarıda kaldı: {{ $last['error'] }}
                    @else
                        Son gönderim {{ \Illuminate\Support\Carbon::parse($last['at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}: {{ $n((int) ($last['content']['sent'] ?? 0)) }} yazı, {{ $n((int) ($last['posts'] ?? 0)) }} gönderi, {{ $n((int) ($last['repair']['applied'] ?? 0)) }} onarım.
                        @if (($last['content']['failed'] ?? []) !== [] || ($last['repair']['failed'] ?? []) !== [])
                            Gönderilemeyen: {{ implode(' · ', array_slice([...($last['content']['failed'] ?? []), ...($last['repair']['failed'] ?? [])], 0, 3)) }}
                        @endif
                    @endif
                </p>
            @endif
        </div>
        @if ($isAdmin)
            <button type="button" wire:click="send" wire:loading.attr="disabled" @disabled($running || $ready === 0)
                class="shrink-0 rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50" data-daily-send-button>
                {{ $running ? 'Gönderiliyor…' : 'AI kontrolünden geçenlerin hepsini gönder' }}
            </button>
        @endif
    </div>
    @if ($message !== '')
        <p class="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $message }}</p>
    @endif
    @if ($showHeld && $summary['held'] !== [])
        <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-800" data-daily-held-list>
            @foreach ($summary['held'] as $item)
                <li class="py-2">
                    <span class="text-xs text-gray-500">{{ $item['area'] }}</span>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                    <span class="block text-amber-800 dark:text-amber-300">{{ $item['reason'] }}</span>
                </li>
            @endforeach
        </ul>
        @if ($summary['held_total'] > count($summary['held']))
            <p class="mt-1 text-xs text-gray-500">İlk {{ count($summary['held']) }} gösteriliyor.</p>
        @endif
    @endif
</div>
