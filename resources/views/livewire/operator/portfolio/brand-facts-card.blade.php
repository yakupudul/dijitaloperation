{{-- Marka bilgi kartı (yakup, 2026-10-09): what the AI knows about the brand, each field with its source; the operator's text locks a field. --}}
<div>
    @php
        $filled = collect($card['fields'])->filter(fn (array $f): bool => $f['locked'] || $f['items'] !== [])->count();
    @endphp
    <section data-brand-facts>
        <div class="section-head">
            <h2 class="section-title">Marka bilgi kartı <span class="section-count">{{ $filled }}/{{ count($card['fields']) }}</span></h2>
            <p class="sr-only">İçerik fikirleri ve yazılar bu bilgileri okur. Sistem her gece markanın kendi verisinden doldurur; senin yazdığın alana bir daha dokunmaz.</p>
            <span class="ml-auto flex items-center gap-2">
                @if ($card['built_at'] !== '')
                    <span class="text-[13px] text-gray-500">{{ \Carbon\Carbon::parse($card['built_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>
                @endif
                <button type="button" wire:click="refreshFacts" wire:loading.attr="disabled" class="btn">Yenile</button>
            </span>
        </div>
        <dl class="card text-xs">
            @foreach ($card['fields'] as $key => $field)
                @php $empty = ! $field['locked'] && $field['items'] === []; @endphp
                <div class="group flex flex-col gap-1 border-t border-gray-200 px-4 py-3 first:border-t-0 sm:flex-row sm:gap-4 dark:border-gray-800" data-fact="{{ $key }}" data-fact-state="{{ $field['locked'] ? 'locked' : ($empty ? 'empty' : 'auto') }}" wire:key="fact-{{ $key }}">
                    <dt class="text-sm font-medium text-gray-900 sm:w-44 sm:shrink-0 dark:text-white">{{ $field['label'] }}</dt>
                    <dd class="min-w-0 flex-1">
                        @if ($editing === $key)
                            <textarea wire:model="text" rows="4" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-[13px] focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-gray-200" placeholder="Her satıra bir bilgi"></textarea>
                            <div class="mt-2 flex gap-2">
                                <button type="button" wire:click="save" class="btn-primary h-7! px-2.5! text-xs!">Kaydet ve kilitle</button>
                                <button type="button" wire:click="cancel" class="btn h-7! px-2.5! text-xs!">Vazgeç</button>
                            </div>
                        @else
                            @if ($field['locked'])
                                <p class="whitespace-pre-line text-[13px] text-gray-800 dark:text-gray-200">{{ $field['manual'] }}</p>
                                <p class="mt-0.5 text-gray-500">Senin yazdığın, kilitli</p>
                            @elseif ($empty)
                                <p class="text-[13px] text-gray-500">{{ $field['source'] }}</p>
                            @elseif ($key === 'services')
                                {{-- Services as chips: ★ (main) first and filled, at most 10 before "+N hizmet". --}}
                                @php
                                    $serviceChips = collect($field['items'])->map(fn (string $item): array => ['main' => str_starts_with($item, '★'), 'name' => trim((string) preg_replace('/^★\s*/u', '', $item))])
                                        ->sortBy(fn (array $chip): int => $chip['main'] ? 0 : 1)->values();
                                @endphp
                                <ul class="flex flex-wrap gap-1.5" x-data="{ all: false }" aria-label="Hizmetler" data-fact-chips>
                                    @foreach ($serviceChips as $chip)
                                        <li @if ($loop->index >= 10) x-show="all" x-cloak @endif @class(['inline-flex items-center gap-1 rounded-md px-2 py-0.5 font-medium ring-1 ring-inset',
                                            'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900 dark:ring-white' => $chip['main'],
                                            'bg-white text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700' => ! $chip['main']])>@if ($chip['main'])<span aria-label="Ana hizmet">★</span>@endif{{ $chip['name'] }}</li>
                                    @endforeach
                                    @if ($serviceChips->count() > 10)
                                        <li>
                                            <button type="button" x-on:click="all = ! all" x-bind:aria-expanded="all.toString()" aria-expanded="false" class="inline-flex items-center rounded-md border border-dashed border-gray-300 px-2.5 py-0.5 font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" data-fact-chips-more>
                                                <span x-show="! all">+{{ $serviceChips->count() - 10 }} hizmet</span>
                                                <span x-show="all" x-cloak>Daha az</span>
                                            </button>
                                        </li>
                                    @endif
                                </ul>
                                <p class="mt-1 text-gray-500">Kaynak: {{ $field['source'] }}</p>
                            @else
                                <p class="text-[13px] text-gray-800 dark:text-gray-200">{{ implode(', ', $field['items']) }}</p>
                                <p class="mt-0.5 text-gray-500">Kaynak: {{ $field['source'] }}</p>
                            @endif
                        @endif
                    </dd>
                    @if ($isAdmin && $editing !== $key)
                        {{-- Shown on row hover or keyboard focus (always on touch screens), neutral: editing is not the page's main action. --}}
                        <div class="flex shrink-0 items-start gap-2 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100" data-fact-actions>
                            <button type="button" wire:click="edit('{{ $key }}')" class="btn-quiet h-7! px-2! text-xs!" data-fact-edit="{{ $key }}">Düzenle</button>
                            @if ($field['locked'])
                                <button type="button" wire:click="unlock('{{ $key }}')" class="btn-quiet h-7! px-2! text-xs!" data-fact-unlock="{{ $key }}">Otomatiğe dön</button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </dl>
        @if ($planner)
            <p class="mt-2 text-[13px] text-gray-500" data-keyword-planner="{{ $planner['status'] }}">Anahtar Kelime Planlayıcı ({{ \Carbon\Carbon::parse($planner['at'])->timezone('Europe/Istanbul')->format('d.m') }}): {{ $planner['message'] }}</p>
        @endif
    </section>
</div>
