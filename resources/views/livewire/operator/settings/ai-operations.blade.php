@php
    $usd = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, $value > 0 && $value < 1 ? 3 : 2, ',', '.');
    $sec = fn (?int $ms): string => $ms === null ? '—' : number_format($ms / 1000, 1, ',', '.').' sn';
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
@endphp
<div class="space-y-5">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">AI işlemleri ve promptlar</h1>

    @if (session('status'))
        <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    @if ($detail === null)
        <p class="{{ $card }} px-5 py-3 text-sm text-gray-600 dark:text-gray-300" data-ai-spend>AI harcaması bu ay: {{ $usd($monthSpend) }} · harcama sınırı yok</p>

        <section class="{{ $card }} overflow-x-auto" data-ai-operations>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr>
                        <th class="px-5 py-2">İşlem</th>
                        <th class="px-3 py-2">Model</th>
                        <th class="px-3 py-2 text-right">Sürüm</th>
                        <th class="px-3 py-2 text-right">30 gün</th>
                        <th class="px-3 py-2 text-right">Ort. süre</th>
                        <th class="px-5 py-2 text-right">Maliyet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($rows as $row)
                        <tr wire:key="op-{{ $row['operation'] }}">
                            <td class="px-5 py-2">
                                <button type="button" wire:click="open('{{ $row['operation'] }}')" class="text-left font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $row['operation'] }}</button>
                                <p class="text-xs text-gray-500">{{ $row['purpose'] }}</p>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500">{{ $row['model'] !== '' ? $row['model'] : 'Rota modeli' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['version'] !== null ? 'v'.$row['version'] : '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['runs'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $sec($row['avg_ms']) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums">{{ $usd($row['cost']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @else
        @php($definition = $detail['definition'])
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <button type="button" wire:click="close" class="text-sm text-gray-500 hover:text-brand-600">← İşlemler</button>
                <h2 class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $operation }} <span class="text-sm text-gray-400">v{{ $detail['current']->version }}</span></h2>
                <p class="text-sm text-gray-500">{{ $detail['current']->purpose ?: $definition['purpose'] }}</p>
            </div>
        </div>

        <form wire:submit="save" class="{{ $card }} space-y-4 p-5" data-prompt-editor>
            <div>
                <label for="prompt-template" class="text-xs font-semibold text-gray-600 dark:text-gray-300">Şablon</label>
                <textarea id="prompt-template" wire:model="template" rows="18" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-700 dark:text-white/90"></textarea>
                @error('template')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-gray-500">Sabit cümle her sürüme kodla eklenir: “{{ \App\Services\Prompts\PromptRegistry::GUARD }}”</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Değişkenler</p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        @forelse ($definition['variables'] as $variable)
                            <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">&#123;&#123;{{ $variable }}&#125;&#125;</span>
                        @empty
                            <span class="text-xs text-gray-500">Yok</span>
                        @endforelse
                    </div>
                </div>
                <div>
                    <label for="prompt-model" class="text-xs font-semibold text-gray-600 dark:text-gray-300">Model</label>
                    <select id="prompt-model" wire:model="model" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm dark:border-gray-700 dark:text-white/90">
                        @foreach ($detail['models'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Bağlam kaynakları</p>
                <ul class="mt-1 list-inside list-disc text-xs text-gray-600 dark:text-gray-300">
                    @foreach ($definition['context_sources'] as $source)<li>{{ $source }}</li>@endforeach
                </ul>
            </div>
            <details>
                <summary class="cursor-pointer text-xs font-semibold text-gray-600 dark:text-gray-300">Çıktı yapısı</summary>
                <pre class="mt-1 max-h-80 overflow-auto rounded bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ json_encode($definition['output_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">Yeni sürüm olarak kaydet</button>
        </form>

        <section class="{{ $card }} space-y-3 p-5" data-prompt-trial @if (($detail['trial']['status'] ?? null) === 'running') wire:poll.3s @endif>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Örnekte dene</h3>
                <div class="flex flex-wrap gap-1">
                    @foreach ($detail['samples'] as $sample)
                        <button type="button" wire:click="useSample({{ $sample['id'] }})" wire:key="sample-{{ $sample['id'] }}" class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($sample['at'])->format('d.m H:i') }}{{ $sample['version'] !== null ? ' · v'.$sample['version'] : '' }}</button>
                    @endforeach
                </div>
            </div>
            <textarea wire:model="trialInput" rows="5" placeholder="Son çalıştırmalardan birini seçin ya da örnek girdi yapıştırın." class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-700 dark:text-white/90"></textarea>
            @error('trial')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            <div class="flex items-center gap-3">
                <button type="button" wire:click="runTrial" wire:loading.attr="disabled" @disabled(($detail['trial']['status'] ?? null) === 'running') class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-white dark:text-gray-900">Taslağı dene</button>
                <span class="text-xs text-gray-500">Yayınlanmamış şablon · öneri kaydedilmez</span>
            </div>
            @if ($detail['trial'] !== null)
                @if ($detail['trial']['status'] === 'running')
                    <p class="text-sm text-gray-500">Çalışıyor…</p>
                @elseif ($detail['trial']['status'] === 'failed')
                    <p class="text-sm text-rose-600">{{ $detail['trial']['message'] }}</p>
                @else
                    <p class="text-xs text-gray-500">{{ $detail['trial']['model'] }} · {{ $sec($detail['trial']['duration_ms']) }} · {{ $usd($detail['trial']['cost']) }}</p>
                    <pre class="max-h-96 overflow-auto rounded bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $detail['trial']['output'] }}</pre>
                @endif
            @endif
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="{{ $card }} p-5" data-prompt-versions>
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Sürüm geçmişi</h3>
                <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($detail['versions'] as $version)
                        <li class="flex items-center justify-between gap-2 py-2" wire:key="v-{{ $version->id }}">
                            <span>
                                v{{ $version->version }} · {{ $version->created_at?->format('d.m.Y H:i') }} · {{ $version->creator?->name ?? 'Sistem' }}
                                @if ($version->model)<span class="text-xs text-gray-500">· {{ $version->model }}</span>@endif
                            </span>
                            @if ($version->is_current)
                                <span class="text-xs font-medium text-emerald-600">Kullanımda</span>
                            @else
                                <button type="button" wire:click="revertTo({{ $version->version }})" wire:confirm="v{{ $version->version }} yeni sürüm olarak yayınlansın mı?" class="text-xs font-medium text-brand-600 hover:underline">Bu sürüme dön</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
            <section class="{{ $card }} p-5" data-prompt-runs>
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Son çalıştırmalar</h3>
                <table class="mt-2 w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($detail['runs'] as $run)
                            <tr>
                                <td class="py-2">{{ \Illuminate\Support\Carbon::parse($run['at'])->format('d.m.Y H:i') }}</td>
                                <td class="py-2 text-xs text-gray-500">{{ $run['version'] !== null ? 'v'.$run['version'] : '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $sec($run['duration_ms']) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $usd($run['cost']) }}</td>
                                <td @class(['py-2 text-right text-xs', 'text-emerald-600' => $run['status'] === 'ok', 'text-rose-600' => $run['status'] !== 'ok'])>{{ $run['status'] === 'ok' ? 'Başarılı' : 'Hata' }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-gray-500">Çalıştırma yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>
    @endif
</div>
