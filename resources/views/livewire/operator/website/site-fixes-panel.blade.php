@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $statusBadge = [
        'open' => ['Açık', 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300'],
        'queued' => ['Gönderiliyor', 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'],
        'drafted' => ['WordPress’te taslak', 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'],
        'applied' => ['Uygulandı', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        'failed' => ['Hata', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
        'undone' => ['Geri alındı', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
        'dismissed' => ['Yok sayıldı', 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400'],
    ];
    $show = static function (mixed $value): string {
        if ($value === null || $value === '') return '—';
        if (is_bool($value)) return $value ? 'noindex (gizli)' : 'index (Google’da)';
        if (is_array($value)) return isset($value['anchor']) ? '"'.$value['anchor'].'" → '.$value['url'] : json_encode($value, JSON_UNESCAPED_UNICODE);
        return (string) $value;
    };
    $phaseNames = ['1' => '1 · Başlık, açıklama, alt metin, schema', '2' => '2 · Yönlendirme, noindex, canonical, iç bağlantı', '3' => '3 · Sayfa metni ve yeni sayfa', 'all' => 'Hepsi'];
@endphp
<div class="space-y-5" @if ($polling) wire:poll.4s @endif>
    <section class="{{ $card }} p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Site düzeltmeleri</h2>
                <p class="mt-1 text-sm text-gray-500">Sistem sorunları son tarama ve WordPress verisinden bulur, AI düzeltme önerir, sen kontrol edip düzenlersin. Siteye yalnız Admin onayıyla yazılır; her değişiklik buradan geri alınabilir.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ta.button type="button" wire:click="find" size="sm" variant="outline">Sorunları bul</x-ta.button>
                <x-ta.button type="button" wire:click="propose('values')" size="sm" :disabled="($aiState['values'] ?? null) === 'running'">{{ ($aiState['values'] ?? null) === 'running' ? 'AI öneriyor…' : '✨ Değerleri AI ile öner' }}</x-ta.button>
                <x-ta.button type="button" wire:click="propose('links')" size="sm" variant="outline" :disabled="($aiState['links'] ?? null) === 'running'">{{ ($aiState['links'] ?? null) === 'running' ? 'AI arıyor…' : '✨ İç bağlantı öner' }}</x-ta.button>
            </div>
        </div>
        @foreach (['values', 'links'] as $k)
            @if (is_string($aiState[$k] ?? null) && str_starts_with($aiState[$k], 'failed'))<p class="mt-2 text-xs text-rose-600">{{ \Illuminate\Support\Str::after($aiState[$k], 'failed: ') }}</p>@endif
        @endforeach

        @if (! $connector['paired'])
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Bu sitede eşleştirilmiş MoxDOP Connector yok. Sorunlar ve öneriler görünür; siteye yazmak için eklentiyi kurup Veri Kaynakları’ndan eşleştir.</p>
        @elseif (! $connector['ready'])
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Eklenti {{ $connector['version'] ?? '?' }}; siteye yazmak için en az {{ $connector['minimum'] }} gerekli. Güncelledikten sonra WordPress › Ayarlar › MoxDOP Connector’da “SEO fixes” (ve sayfa metni için “Content updates”) seçeneğini aç.</p>
        @else
            <p class="mt-4 text-xs text-gray-500">Eklenti {{ $connector['version'] }}. WordPress › Ayarlar › MoxDOP Connector’da “SEO fixes” ve “Content updates” kapalıysa site isteği reddeder.</p>
        @endif
        @if ($message !== '')
            <p @class(['mt-3 rounded-lg px-3 py-2 text-sm', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $tone === 'success', 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300' => $tone !== 'success'])>{{ $message }}</p>
        @endif
    </section>

    <div class="flex flex-wrap items-center gap-2">
        @foreach ($phaseNames as $key => $label)
            <button type="button" wire:click="$set('phase', '{{ $key }}')" @class(['rounded-full px-3 py-1 text-xs ring-1 ring-inset', 'bg-brand-50 text-brand-700 ring-brand-300' => $phase === (string) $key, 'text-gray-600 ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $phase !== (string) $key])>{{ $label }}@if (isset($counts[$key])) ({{ $counts[$key] }})@endif</button>
        @endforeach
        <select wire:model.live="status" class="ml-auto rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
            <option value="open">Yapılacaklar</option><option value="applied">Uygulananlar</option><option value="dismissed">Yok sayılanlar</option><option value="all">Hepsi</option>
        </select>
    </div>

    <section class="{{ $card }} divide-y divide-gray-100 dark:divide-gray-800">
        @forelse ($items as $item)
            @php
                [$statusLabel, $statusClass] = $statusBadge[$item->status] ?? [$item->status, ''];
                $content = in_array($item->type, ['content_update', 'new_page'], true);
                $proposed = is_array($item->proposed) && array_key_exists('value', $item->proposed);
                $selectable = ! $content && $canWrite && $connector['ready'] && $proposed && in_array($item->status, ['open', 'failed', 'undone'], true);
                $editing = array_key_exists($item->id, $edits);
                $pageState = $pageStates[$item->id] ?? null;
            @endphp
            <div wire:key="fix-{{ $item->id }}" class="flex gap-3 px-5 py-4">
                <div class="pt-1">@if ($selectable)<input type="checkbox" wire:model.live="selected.{{ $item->id }}" class="rounded border-gray-300">@endif</div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $item->typeLabel() }}</span>
                        <span class="rounded px-2 py-0.5 text-[11px] font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                        @if ($item->status === 'applied' && data_get($item->current, 'verification.state') === 'verified')
                            <span class="rounded px-2 py-0.5 text-[11px] font-medium bg-emerald-50 text-emerald-700" title="Değişiklikten sonra sayfa yeniden tarandı; sorun görünmüyor.">Sitede doğrulandı</span>
                        @elseif ($item->status === 'applied' && data_get($item->current, 'verification.state') === 'still_present')
                            <span class="rounded px-2 py-0.5 text-[11px] font-medium bg-amber-50 text-amber-800" title="Yeniden taramada sorun hâlâ görünüyor: önbellek, tema ya da başka bir SEO eklentisi değeri eziyor olabilir.">Sitede hâlâ görünüyor</span>
                        @endif
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $item->label }}</p>
                    </div>
                    @if ($item->url)<p class="mt-0.5 truncate text-xs text-gray-400">{{ $item->url }}</p>@endif
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $item->reason }}</p>
                    @if ($item->error)<p class="mt-1 text-xs text-rose-600">{{ $item->error }}</p>@endif

                    @if (! $content)
                        <div class="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]"><p class="text-gray-400">Şu an</p><p class="mt-0.5 whitespace-pre-line break-words text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($show(data_get($item->current, 'value')), 400) }}</p></div>
                            <div class="rounded-lg bg-emerald-50/60 px-3 py-2 dark:bg-emerald-500/5">
                                <p class="text-gray-400">Yeni @if ($item->proposed_by) <span>({{ ['ai' => 'AI', 'operator' => 'sen', 'rule' => 'kural'][$item->proposed_by] ?? $item->proposed_by }})</span>@endif</p>
                                @if ($editing)
                                    @if ($item->type === 'noindex')
                                        <select wire:model="edits.{{ $item->id }}" class="mt-1 w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"><option value="0">index (Google’da görünsün)</option><option value="1">noindex (gizli kalsın)</option></select>
                                    @else
                                        <textarea wire:model="edits.{{ $item->id }}" rows="{{ $item->type === 'schema' ? 10 : 2 }}" class="mt-1 w-full rounded border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900"></textarea>
                                    @endif
                                    <div class="mt-1 flex gap-2"><button type="button" wire:click="saveValue({{ $item->id }})" class="font-medium text-brand-600">Kaydet</button><button type="button" wire:click="cancelEdit({{ $item->id }})" class="text-gray-500">Vazgeç</button></div>
                                @else
                                    <p class="mt-0.5 whitespace-pre-line break-words text-gray-800 dark:text-gray-200">{{ $proposed ? \Illuminate\Support\Str::limit($show($item->value()), 600) : 'Öneri yok — “Değerleri AI ile öner” ya da düzenle.' }}</p>
                                    @if (filled(data_get($item->proposed, 'note')))<p class="mt-1 text-[11px] text-gray-500">{{ data_get($item->proposed, 'note') }}</p>@endif
                                    @if (in_array($item->status, ['open', 'failed', 'undone'], true) && $item->type !== 'internal_link')
                                        <button type="button" wire:click="$set('edits.{{ $item->id }}', @js($item->type === 'noindex' ? ($item->value() ? '1' : '0') : (is_scalar($item->value()) ? (string) $item->value() : '')))" class="mt-1 font-medium text-brand-600">Düzenle</button>
                                    @endif
                                    @if ($item->type === 'seo_title' && $proposed)<span class="ml-2 text-[11px] text-gray-400">{{ mb_strlen((string) $item->value()) }} karakter</span>@endif
                                    @if ($item->type === 'seo_description' && $proposed)<span class="ml-2 text-[11px] text-gray-400">{{ mb_strlen((string) $item->value()) }} karakter</span>@endif
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            @if (in_array($item->status, ['open', 'failed', 'undone'], true))
                                <button type="button" wire:click="writePage({{ $item->id }})" @disabled($pageState === 'running') class="rounded-lg bg-violet-600 px-3 py-1.5 font-semibold text-white disabled:opacity-50">{{ $pageState === 'running' ? 'AI yazıyor…' : ($proposed ? '✨ Yeniden yaz' : '✨ AI ile yaz') }}</button>
                            @endif
                            @if ($proposed)
                                <button type="button" wire:click="toggle({{ $item->id }})" class="font-medium text-brand-600">{{ $openItem === $item->id ? 'Metni gizle' : 'Metni gör' }}</button>
                                @if ($canWrite && $connector['ready'] && in_array($item->status, ['open', 'failed', 'undone'], true))
                                    <button type="button" wire:click="sendDraft({{ $item->id }})" wire:confirm="Metin WordPress’e taslak olarak gönderilsin mi? Yayındaki sayfa değişmez." class="rounded-lg border border-gray-300 px-3 py-1.5 font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200">WordPress’e taslak gönder</button>
                                @endif
                            @endif
                            @if ($item->status === 'drafted')
                                @if (data_get($item->current, 'edit_url'))<a href="{{ data_get($item->current, 'edit_url') }}" target="_blank" rel="noopener" class="font-medium text-brand-600">WordPress’te düzenle ↗</a>@endif
                                @if (data_get($item->current, 'preview_url'))<a href="{{ data_get($item->current, 'preview_url') }}" target="_blank" rel="noopener" class="font-medium text-brand-600">Önizle ↗</a>@endif
                                @if ($canWrite && $item->type === 'content_update')
                                    <button type="button" wire:click="applyContent({{ $item->id }})" wire:confirm="Taslaktaki yeni sürüm yayındaki sayfanın yerine geçsin mi? WordPress eski sürümü saklar." class="rounded-lg bg-emerald-600 px-3 py-1.5 font-semibold text-white">Yayına al</button>
                                @endif
                            @endif
                            @if ($pageState && str_starts_with($pageState, 'failed'))<span class="text-rose-600">{{ \Illuminate\Support\Str::after($pageState, 'failed: ') }}</span>@endif
                        </div>
                        @if ($openItem === $item->id && $proposed)
                            <div class="mt-2 max-h-96 overflow-y-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                                <p class="font-semibold">{{ data_get($item->proposed, 'value.title') }}</p>
                                @if (filled(data_get($item->proposed, 'note')))<p class="mt-1 italic text-gray-500">{{ data_get($item->proposed, 'note') }}</p>@endif
                                <div class="mt-2 whitespace-pre-line">{{ trim(preg_replace('/\n{3,}/', "\n\n", strip_tags(str_replace(['</h2>', '</h3>', '</p>', '</li>'], ["\n\n", "\n\n", "\n\n", "\n"], (string) data_get($item->proposed, 'value.html'))))) }}</div>
                            </div>
                        @endif
                    @endif
                </div>
                <div class="shrink-0 pt-1">
                    @if (in_array($item->status, ['open', 'dismissed'], true))
                        <button type="button" wire:click="dismiss({{ $item->id }})" class="text-xs text-gray-400 hover:text-gray-600">{{ $item->status === 'dismissed' ? 'Geri getir' : 'Yok say' }}</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-sm text-gray-500">Bu görünümde düzeltme yok. “Sorunları bul” ile son taramaya göre listele.</p>
        @endforelse
    </section>

    @if ($canWrite && count(array_filter($selected)) > 0)
        <div class="sticky bottom-4 flex items-center justify-between gap-3 rounded-xl bg-gray-900 px-5 py-3 text-sm text-white shadow-lg">
            <span>{{ count(array_filter($selected)) }} düzeltme seçili</span>
            <button type="button" wire:click="applySelected" wire:confirm="Seçili düzeltmeler siteye yazılsın mı? Her biri buradan geri alınabilir." class="rounded-lg bg-emerald-500 px-4 py-2 font-semibold">Seçilenleri siteye uygula</button>
        </div>
    @endif

    @if ($actions->isNotEmpty())
        <section class="{{ $card }} p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white">Siteye yapılan değişiklikler</h3>
            <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                @foreach ($actions as $action)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="text-gray-700 dark:text-gray-300">
                            {{ ['site_fix' => 'Düzeltme', 'content_draft' => 'Taslak', 'content_apply' => 'Sayfa yayına alındı'][$action->action] ?? $action->action }}
                            @if ($action->action === 'site_fix') · {{ $action->result['applied'] ?? 0 }} uygulandı @if (($action->result['failed'] ?? 0) > 0)· {{ $action->result['failed'] }} hata @endif @endif
                            @if (data_get($action->result, 'verification.state') === 'crawling') · sayfalar yeniden taranıyor @elseif (data_get($action->result, 'verification.state') === 'done') · doğrulama: {{ data_get($action->result, 'verification.verified', 0) }} tamam @if (data_get($action->result, 'verification.still_present', 0) > 0), {{ data_get($action->result, 'verification.still_present') }} hâlâ görünüyor @endif @endif
                            · {{ $action->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="text-xs text-gray-500">{{ $action->statusLabel() }}</span>
                            @if ($action->error)<span class="text-xs text-rose-600">{{ \Illuminate\Support\Str::limit($action->error, 120) }}</span>@endif
                            @if ($canWrite && $action->isUndoable())
                                <button type="button" wire:click="undo({{ $action->id }})" wire:confirm="Bu değişiklik geri alınsın mı?" class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-700 dark:border-gray-700 dark:text-gray-300">Geri al</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
