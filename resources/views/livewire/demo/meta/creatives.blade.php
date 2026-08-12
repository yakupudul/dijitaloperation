@php
    $previewGradient = static function (string $format): string {
        return match (strtolower($format)) {
            'video' => 'from-orange-500/30 via-amber-400/20 to-stone-300/40 dark:to-stone-800/60',
            'carousel' => 'from-sky-500/25 via-cyan-400/15 to-slate-300/40 dark:to-slate-800/60',
            default => 'from-emerald-500/25 via-teal-400/15 to-zinc-300/40 dark:to-zinc-800/60',
        };
    };
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads',
        'title' => 'Creatives',
        'subtitle' => 'Media-first gallery · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'creatives'])
    @include('livewire.demo.partials.period-bar')

    @include('livewire.demo.partials.section-question', [
        'question' => 'Which creatives are carrying delivery?',
        'hint' => 'Preview tiles are format placeholders — no remote media required.',
    ])

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($creatives as $creative)
            <x-ta.card padding="p-0" class="overflow-hidden">
                <div @class([
                    'relative flex aspect-[4/3] items-end bg-gradient-to-br p-4',
                    $previewGradient($creative['format'] ?? 'image'),
                ])>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="rounded-md bg-black/35 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-white">
                            {{ $creative['format'] }} preview
                        </span>
                    </div>
                    @if ($creative['attention'] ?? null)
                        <x-ta.badge color="error" size="sm" class="relative z-10">Fatigue</x-ta.badge>
                    @endif
                </div>
                <div class="space-y-3 p-4">
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $creative['name'] }}</h3>
                        <p class="text-sm text-gray-500">{{ $creative['campaign'] }}</p>
                    </div>
                    <p class="line-clamp-2 text-sm text-gray-600 dark:text-gray-300">{{ $creative['headline'] }}</p>
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-gray-400">Spend</dt><dd class="font-medium">₺{{ number_format($creative['spend']) }}</dd></div>
                        <div><dt class="text-gray-400">CTR</dt><dd class="font-medium">{{ $creative['ctr'] }}%</dd></div>
                        <div><dt class="text-gray-400">{{ $creative['result_label'] }}</dt><dd class="font-medium">{{ number_format($creative['result']) }}</dd></div>
                        <div><dt class="text-gray-400">Cost</dt><dd class="font-medium">₺{{ number_format($creative['cost_result']) }}</dd></div>
                    </dl>
                    <x-ta.button :href="route('demo.meta.ad', ['assetId' => $assetId, 'adId' => $creative['id']])" size="sm">Open creative</x-ta.button>
                </div>
            </x-ta.card>
        @endforeach
    </div>
</div>
