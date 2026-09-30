<div data-ai-prompt-info-modal>
    @if ($info !== null)
        <div class="fixed inset-0 z-[100001] flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4 sm:items-center" wire:keydown.escape.window="close">
            <div class="absolute inset-0" wire:click="close" aria-hidden="true"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="ai-prompt-info-title" class="relative w-full max-w-3xl rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 id="ai-prompt-info-title" class="text-base font-semibold text-gray-900 dark:text-white">{{ $info['label'] }}</h2>
                        <p class="mt-0.5 font-mono text-xs text-gray-400">{{ $operation }}</p>
                    </div>
                    <button type="button" wire:click="close" class="rounded-lg px-2 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Kapat">✕</button>
                </div>

                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">{{ $info['purpose'] }}</p>

                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-200">Sürüm v{{ $info['current']->version }}</span>
                    @if ($info['isDefault'])
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" data-prompt-default>Varsayılan (kod)</span>
                    @else
                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300" data-prompt-custom>Düzenlenmiş{{ $info['author'] ? ' · '.$info['author'] : '' }}</span>
                    @endif
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-200">Model: {{ $info['model'] !== '' ? $info['model'] : 'Rota modeli (varsayılan)' }}</span>
                </div>

                @if ($editing)
                    <form wire:submit="save" class="mt-4 space-y-2">
                        <label for="ai-prompt-info-template" class="text-xs font-semibold text-gray-600 dark:text-gray-300">Şablon</label>
                        <textarea id="ai-prompt-info-template" wire:model="template" rows="16" class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-700 dark:text-white/90"></textarea>
                        @error('template')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                        @if ($info['variables'] !== [])
                            <p class="text-xs text-gray-500">Değişkenler: @foreach ($info['variables'] as $variable)<span class="font-mono">&#123;&#123;{{ $variable }}&#125;&#125;</span>@if (! $loop->last), @endif @endforeach</p>
                        @endif
                        <p class="text-xs text-gray-500">Kaydetmek yeni bir sürüm yayınlar; sonraki AI çalıştırmaları onu kullanır. Sabit güvenlik cümlesi kodla eklenir.</p>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" wire:click="$set('editing', false)" class="rounded-lg px-3 py-1.5 text-sm text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Vazgeç</button>
                            <button type="submit" class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white">Kaydet</button>
                        </div>
                    </form>
                @else
                    <pre class="mt-4 max-h-[50vh] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200" data-prompt-template>{{ $info['current']->template }}</pre>
                    @error('template')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                        @if ($canEdit)
                            <a href="{{ route('operator.settings.ai-operations', ['islem' => $operation]) }}" wire:navigate class="mr-auto text-xs font-semibold text-brand-600">Ayarlarda aç (geçmiş, örnekte dene)</a>
                            @if (! $info['isDefault'])
                                <button type="button" wire:click="resetDefault" wire:confirm="Kodun varsayılan promptu yeni sürüm olarak yayınlansın mı?" class="rounded-lg px-3 py-1.5 text-sm text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Varsayılana dön</button>
                            @endif
                            <button type="button" wire:click="edit" class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white">Düzenle</button>
                        @else
                            <p class="mr-auto text-xs text-gray-500">Promptu yalnız Admin düzenler.</p>
                        @endif
                        <button type="button" wire:click="close" class="rounded-lg px-3 py-1.5 text-sm text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Kapat</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
