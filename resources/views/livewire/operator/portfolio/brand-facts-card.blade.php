{{-- Marka bilgi kartı (yakup, 2026-10-09): what the AI knows about the brand, each field with its source; the operator's text locks a field. --}}
<div>
    @php
        $filled = collect($card['fields'])->filter(fn (array $f): bool => $f['locked'] || $f['items'] !== [])->count();
    @endphp
    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-brand-facts>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Marka bilgi kartı · {{ $filled }}/{{ count($card['fields']) }}</h2>
                <p class="text-xs text-gray-500">İçerik fikirleri ve yazılar bu bilgileri okur. Sistem her gece markanın kendi verisinden doldurur; senin yazdığın alana bir daha dokunmaz.</p>
            </div>
            <div class="flex items-center gap-3 text-xs">
                @if ($card['built_at'] !== '')
                    <span class="text-gray-400">{{ \Carbon\Carbon::parse($card['built_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>
                @endif
                <button type="button" wire:click="refreshFacts" wire:loading.attr="disabled" class="font-medium text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white">Yenile</button>
            </div>
        </div>
        <dl class="mt-3 divide-y divide-gray-100 text-xs dark:divide-gray-800">
            @foreach ($card['fields'] as $key => $field)
                @php $empty = ! $field['locked'] && $field['items'] === []; @endphp
                <div class="group flex flex-wrap gap-x-3 gap-y-1 py-2" data-fact="{{ $key }}" data-fact-state="{{ $field['locked'] ? 'locked' : ($empty ? 'empty' : 'auto') }}" wire:key="fact-{{ $key }}">
                    <dt class="w-48 shrink-0 font-semibold text-gray-900 dark:text-white">{{ $field['label'] }}</dt>
                    <dd class="min-w-0 flex-1">
                        @if ($editing === $key)
                            <textarea wire:model="text" rows="4" class="w-full rounded-md border-gray-200 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200" placeholder="Her satıra bir bilgi"></textarea>
                            <div class="mt-1 flex gap-2">
                                <button type="button" wire:click="save" class="rounded-md bg-brand-500 px-2.5 py-1 font-semibold text-white hover:bg-brand-600">Kaydet ve kilitle</button>
                                <button type="button" wire:click="cancel" class="rounded-md px-2.5 py-1 font-medium text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Vazgeç</button>
                            </div>
                        @else
                            @if ($field['locked'])
                                <p class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $field['manual'] }}</p>
                                <p class="mt-0.5 text-gray-400">Senin yazdığın · kilitli</p>
                            @elseif ($empty)
                                <p class="text-gray-400">{{ $field['source'] }}</p>
                            @elseif ($key === 'services')
                                {{-- Services as chips: ★ (main) first and filled, at most 10 before "+N hizmet". --}}
                                @php
                                    $serviceChips = collect($field['items'])->map(fn (string $item): array => ['main' => str_starts_with($item, '★'), 'name' => trim((string) preg_replace('/^★\s*/u', '', $item))])
                                        ->sortBy(fn (array $chip): int => $chip['main'] ? 0 : 1)->values();
                                @endphp
                                <ul class="flex flex-wrap gap-1.5" x-data="{ all: false }" aria-label="Hizmetler" data-fact-chips>
                                    @foreach ($serviceChips as $chip)
                                        <li @if ($loop->index >= 10) x-show="all" x-cloak @endif @class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 font-medium ring-1 ring-inset',
                                            'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900 dark:ring-white' => $chip['main'],
                                            'bg-white text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700' => ! $chip['main']])>@if ($chip['main'])<span aria-label="Ana hizmet">★</span>@endif{{ $chip['name'] }}</li>
                                    @endforeach
                                    @if ($serviceChips->count() > 10)
                                        <li>
                                            <button type="button" x-on:click="all = ! all" x-bind:aria-expanded="all.toString()" aria-expanded="false" class="inline-flex items-center rounded-full border border-dashed border-gray-300 px-2.5 py-0.5 font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" data-fact-chips-more>
                                                <span x-show="! all">+{{ $serviceChips->count() - 10 }} hizmet</span>
                                                <span x-show="all" x-cloak>Daha az</span>
                                            </button>
                                        </li>
                                    @endif
                                </ul>
                                <p class="mt-1 text-gray-400">Kaynak: {{ $field['source'] }}</p>
                            @else
                                <p class="text-gray-800 dark:text-gray-200">{{ implode(' · ', $field['items']) }}</p>
                                <p class="mt-0.5 text-gray-400">Kaynak: {{ $field['source'] }}</p>
                            @endif
                        @endif
                    </dd>
                    @if ($isAdmin && $editing !== $key)
                        {{-- Shown on row hover or keyboard focus (always on touch screens), neutral: editing is not the page's main action. --}}
                        <div class="flex shrink-0 gap-2 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100" data-fact-actions>
                            <button type="button" wire:click="edit('{{ $key }}')" class="font-medium text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white" data-fact-edit="{{ $key }}">Düzenle</button>
                            @if ($field['locked'])
                                <button type="button" wire:click="unlock('{{ $key }}')" class="font-medium text-gray-500 hover:underline" data-fact-unlock="{{ $key }}">Otomatiğe dön</button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </dl>
        @if ($planner)
            <p class="mt-2 text-xs text-gray-500" data-keyword-planner="{{ $planner['status'] }}">Anahtar Kelime Planlayıcı ({{ \Carbon\Carbon::parse($planner['at'])->timezone('Europe/Istanbul')->format('d.m') }}): {{ $planner['message'] }}</p>
        @endif
    </section>
</div>
