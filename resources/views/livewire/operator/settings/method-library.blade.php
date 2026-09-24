@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $channelLabels = ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads', 'google_business_profile' => 'İşletme Profili', 'cross' => 'Kanallar arası', 'seo' => 'SEO Görevleri'];
    $effect = function (?array $s): string {
        if ($s === null || $s['done'] === 0) {
            return '—';
        }
        $text = $s['done'].' yapıldı';
        if ($s['measured'] > 0) {
            $text .= ' · '.$s['measured'].' ölçüldü · %'.(int) round($s['improved'] / $s['measured'] * 100).' iyileşti';
        }
        if (($s['reopened'] ?? 0) > 0) {
            $text .= ' · '.$s['reopened'].' kez geri geldi';
        }

        return $text;
    };
@endphp
<div class="space-y-5">
    <div>
        <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Ayarlar</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">Yöntem Kütüphanesi</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Danışman, SEO Görevleri, uyarılar ve talep hattının kuralları ve eşikleri. Değişiklik bir sonraki planda uygulanır; dosyadaki varsayılan her satırda görünür ve geri alınabilir. Kural etkinliği, "Yapıldı" işaretlenen önerilerin 28 gün sonra ölçülen sonucundan gelir ve önceliklendirmeyi etkiler. Google algoritma ve mevzuat değişikliklerini <a href="{{ route('operator.reports.annotations') }}" wire:navigate class="text-brand-600 hover:underline">grafik notlarına</a> da ekleyin.</p>
    </div>

    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($error !== '')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>@endif

    <nav class="flex gap-1 border-b border-gray-200 dark:border-gray-800">
        @foreach (['rules' => 'Kurallar', 'thresholds' => 'Eşikler ve listeler'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-500 text-brand-600' => $tab === $key, 'border-transparent text-gray-500' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'rules')
        @foreach (array_merge($advisorRules, ['seo' => $seoRules]) as $channel => $rules)
            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $channelLabels[$channel] ?? $channel }}</h2>
                <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($rules as $rule)
                        @php
                            $scope = $channel === 'seo' ? 'seo' : 'advisor';
                            $off = in_array($rule, $scope === 'seo' ? $disabledSeo : $disabledAdvisor, true);
                        @endphp
                        <div wire:key="rule-{{ $channel }}-{{ $rule }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span @class(['font-mono text-xs', 'text-gray-400 line-through' => $off, 'text-gray-800 dark:text-gray-200' => ! $off])>{{ $rule }}</span>
                            <span class="text-xs text-gray-500">{{ $effect($stats[$rule] ?? null) }}</span>
                            @if ($isAdmin)
                                <button type="button" wire:click="toggleRule('{{ $scope }}', '{{ $rule }}')" @class(['rounded px-2 py-0.5 text-xs ring-1 ring-inset', 'text-emerald-700 ring-emerald-300' => ! $off, 'text-gray-500 ring-gray-300' => $off])>{{ $off ? 'Kapalı' : 'Açık' }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    @else
        @foreach ($catalog as $file => $group)
            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $group['label'] }}</h2>
                @foreach ($group['sections'] as $section => $rows)
                    <details class="mt-2" @if ($loop->first) open @endif>
                        <summary class="cursor-pointer text-xs font-medium uppercase tracking-wide text-gray-500">{{ $section }} ({{ count($rows) }})</summary>
                        <div class="mt-2 space-y-2">
                            @foreach ($rows as $row)
                                <div wire:key="m-{{ md5($row['key']) }}" class="grid items-start gap-2 text-sm sm:grid-cols-[14rem_1fr_auto]">
                                    <label class="pt-1 font-mono text-xs {{ $row['overridden'] ? 'text-brand-600' : 'text-gray-600 dark:text-gray-400' }}" for="m-{{ md5($row['key']) }}">{{ $row['name'] }}</label>
                                    @if ($row['type'] === 'list')
                                        <textarea id="m-{{ md5($row['key']) }}" wire:model="values.{{ md5($row['key']) }}" rows="2" @disabled(! $isAdmin) class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-xs dark:border-gray-700 dark:text-white"></textarea>
                                    @else
                                        <input id="m-{{ md5($row['key']) }}" type="text" wire:model="values.{{ md5($row['key']) }}" @disabled(! $isAdmin) class="w-32 rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-xs dark:border-gray-700 dark:text-white" />
                                    @endif
                                    <div class="flex items-center gap-2 text-xs">
                                        @if ($isAdmin)
                                            <button type="button" wire:click="save('{{ $row['key'] }}')" class="rounded px-2 py-0.5 ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Kaydet</button>
                                            @if ($row['overridden'])<button type="button" wire:click="resetKey('{{ $row['key'] }}')" class="text-gray-500 hover:underline">Varsayılana dön</button>@endif
                                        @endif
                                        <span class="text-gray-400">varsayılan: {{ is_array($row['default']) ? count($row['default']).' öğe' : $row['default'] }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </section>
        @endforeach
    @endif
</div>
