@php
    // Aşama 8: the week's plan, one list of a few minutes: approve all, drop the unwanted (its place fills itself).
    $kindTone = ['strengthen' => 'chip-up', 'new' => 'chip-flat', 'update' => 'chip-flat'];
    $day = fn (?string $d): string => $d !== null ? \Carbon\CarbonImmutable::parse($d)->locale('tr')->isoFormat('D MMM ddd') : '';
@endphp
<section class="space-y-3" data-week-plan>
    @if ($total > 0)
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="min-w-0 flex-1">
                <h2 class="section-title mb-0!">Bu haftanın içerik planı <span class="font-normal tabular-nums text-gray-500">{{ $total }}</span></h2>
                <p class="text-[13px] text-gray-500">Onaylananlar yazılır, kontrol edilir ve günü gelince gönderime hazır olur. İstemediğini at, yerine yenisi gelir.</p>
            </div>
            @if ($isAdmin)
                <button type="button" wire:click="approveAll(null)" wire:loading.attr="disabled" class="btn-dark" data-plan-approve-all>Tümünü onayla ({{ $total }})</button>
            @endif
        </div>
        @if ($results !== null)
            <p class="text-sm text-gray-700 dark:text-gray-300" data-plan-results><span class="chip chip-up">Sonuç</span> {{ $results['sentence'] }}</p>
        @endif
        @if ($message !== '')
            <p role="status" class="tone-info rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
        @endif
        <div class="card divide-y divide-gray-200 dark:divide-gray-800">
            @foreach ($brands as $brandId => $rows)
                <div class="px-4 py-3.5" wire:key="plan-brand-{{ $brandId }}" data-plan-brand="{{ $brandId }}">
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($rows[0]['logo'])
                            <img src="{{ $rows[0]['logo'] }}" alt="" class="size-6 rounded object-contain">
                        @endif
                        <span class="font-medium text-gray-900 dark:text-white">{{ $rows[0]['brand'] }}</span>
                        <span class="text-[13px] text-gray-500">{{ $rows->count() }} satır</span>
                        @if ($isAdmin && $brands->count() > 1)
                            <button type="button" wire:click="approveAll({{ $brandId }})" wire:loading.attr="disabled" class="btn ml-auto h-7! px-2.5! text-xs!">Bu markanınkileri onayla</button>
                        @endif
                    </div>
                    <ul class="mt-2.5 space-y-2">
                        @foreach ($rows as $row)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]" wire:key="plan-{{ $row['id'] }}" data-plan-row="{{ $row['id'] }}">
                                <span class="chip {{ $kindTone[$row['kind']] }}">{{ $row['kind_label'] }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $row['title'] }}</span>
                                    @if ($row['date'] !== null)<span class="text-xs text-gray-500"> · {{ $day($row['date']) }}</span>@endif
                                    <span class="block truncate text-xs text-gray-500" title="{{ $row['why'] }}">Neden: {{ $row['why'] }} · {{ $row['site'] }}</span>
                                </span>
                                @if ($isAdmin)
                                    <button type="button" wire:click="drop({{ $row['id'] }})" wire:loading.attr="disabled" class="btn-quiet" data-plan-drop>At</button>
                                    <button type="button" wire:click="approveOne({{ $row['id'] }})" wire:loading.attr="disabled" class="btn" data-plan-approve>Onayla</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @elseif ($message !== '')
        <p role="status" class="tone-info rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
    @endif
</section>
