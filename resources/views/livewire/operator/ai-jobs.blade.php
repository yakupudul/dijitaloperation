@php
    $usd = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, $value > 0 && $value < 1 ? 4 : 2, ',', '.');
    $time = fn ($value): string => $value?->timezone(\App\Livewire\Operator\AiJobsPage::TIMEZONE)->format('d.m.Y H:i:s') ?? '—';
    $statusClass = fn (\App\Models\AiLiveOperation $row): string => match ($row->status) {
        'running' => 'text-live-700 dark:text-live-300',
        'queued' => 'text-warning-700 dark:text-warning-300',
        'done' => 'text-success-600',
        'cancelled' => 'text-gray-500',
        default => 'text-error-600',
    };
    $statusTone = fn (\App\Models\AiLiveOperation $row): string => match ($row->status) {
        'running' => 'live',
        'queued' => 'look',
        'done' => 'ok',
        'cancelled' => 'info',
        default => 'critical',
    };
    $input = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $card = 'card';
    // Plain Turkish names (2026-10-19): a stored label that is a code name (RunSiteOperationJob, site.cluster_audit) is
    // replaced by the label map; the code name stays in the title attribute only.
    $isTechnical = fn (?string $value): bool => is_string($value) && $value !== '' && ! str_contains(trim($value), ' ')
        && preg_match('/[._]|Job$|^[A-Z][a-z0-9]+[A-Z]/', $value) === 1;
    $labelOf = function (\App\Models\AiLiveOperation $row) use ($isTechnical): string {
        $label = (string) $row->label;
        if ($label === '' || $isTechnical($label)) {
            $mapped = \App\Support\Ai\AiOperationLabels::for($row->operation, $row->agent);

            return $isTechnical($mapped) ? ($row->isJob() ? 'Arka plan işi' : 'AI işlemi') : $mapped;
        }

        return $label;
    };
    $codeOf = fn (\App\Models\AiLiveOperation $row): string => collect([$row->operation, $isTechnical((string) $row->label) ? $row->label : null, $row->job_class ? class_basename((string) $row->job_class) : null])->filter()->unique()->implode(' · ');
@endphp
<div class="space-y-10" data-ai-jobs @if ($polling) wire:poll.5s @endif>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">AI işleri</h1>
            <p class="page-sub">Her AI çağrısı ve arka plan işi; kayıtlar {{ $retentionDays }} gün saklanır.</p>
        </div>
        @if ($isAdmin)
            <a href="{{ route('operator.settings.ai-operations') }}" wire:navigate class="btn w-full sm:w-auto" title="Ayarlar › AI işlemleri ve promptlar">AI işlemleri ve promptlar</a>
        @endif
    </header>

    @if (session('ai-jobs-status'))
        <p class="tone-ok -mt-4 rounded-lg px-4 py-2 text-sm" data-ai-jobs-status>{{ session('ai-jobs-status') }}</p>
    @endif

    <div class="-mt-4 space-y-4" data-ai-jobs-filters>
        <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 no-scrollbar dark:border-gray-800" aria-label="Durum">
            <button type="button" wire:click="$set('status', '')" @class(['tab', 'tab-active' => $status === ''])>Tümü</button>
            @foreach ($statuses as $key => $label)
                <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['tab', 'tab-active' => $status === $key])>{{ $label }}</button>
            @endforeach
        </nav>
        <div class="grid grid-cols-2 items-end gap-2 sm:flex sm:flex-wrap">
            <label class="col-span-2 flex min-w-0 flex-col gap-1 sm:w-64">
                <span class="text-xs text-gray-500">İşlem</span>
                <select wire:model.live="operation" class="{{ $input }} w-full max-w-full">
                    <option value="">Tümü</option>
                    @foreach ($operations as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="col-span-2 flex min-w-0 flex-col gap-1 sm:w-44">
                <span class="text-xs text-gray-500">Kullanıcı</span>
                <select wire:model.live="user" class="{{ $input }} w-full">
                    <option value="">Tümü</option>
                    @foreach ($userOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex min-w-0 flex-col gap-1">
                <span class="text-xs text-gray-500">Başlangıç</span>
                <input type="date" wire:model.live="from" class="{{ $input }} w-full sm:w-auto">
            </label>
            <label class="flex min-w-0 flex-col gap-1">
                <span class="text-xs text-gray-500">Bitiş</span>
                <input type="date" wire:model.live="to" class="{{ $input }} w-full sm:w-auto">
            </label>
            <button type="button" wire:click="resetFilters" class="btn-quiet col-span-2 h-9! justify-self-start">Filtreleri temizle</button>
        </div>
    </div>

    <div @class(['-mt-4 grid gap-5', 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]' => $detailData !== null])>
        <section class="min-w-0" data-ai-jobs-list>
            @php($narrow = $detailData !== null)
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            @if ($isAdmin)<th class="w-8 pr-0!"><span class="sr-only">Seç</span></th>@endif
                            <th>İşlem</th>
                            <th @class(['hidden', 'md:table-cell' => ! $narrow, '2xl:table-cell' => $narrow])>Kullanıcı</th>
                            <th @class(['hidden', 'sm:table-cell' => ! $narrow, '2xl:table-cell' => $narrow])>Başladı</th>
                            <th class="hidden text-right! sm:table-cell">Süre</th>
                            <th class="hidden text-right! sm:table-cell">Maliyet</th>
                            <th class="text-right!">Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="ai-job-{{ $row->id }}" @class(['cursor-pointer', '[&>td]:bg-gray-50! dark:[&>td]:bg-white/[0.04]!' => $detail === $row->id]) wire:click="show({{ $row->id }})" data-ai-job-row="{{ $row->id }}">
                                @if ($isAdmin)
                                    <td class="pr-0!" wire:click.stop>
                                        @unless ($row->isOpen())<input type="checkbox" value="{{ $row->id }}" wire:model.live="selected" aria-label="Seç" class="h-4 w-4 rounded border-gray-300">@endunless
                                    </td>
                                @endif
                                <td class="w-full max-w-0">
                                    <span class="break-words font-medium text-gray-900 dark:text-white" @if ($codeOf($row) !== '') title="{{ $codeOf($row) }}" @endif>{{ $labelOf($row) }}</span>
                                    @if ($row->isJob())<span class="ml-1 text-xs text-gray-500">iş</span>@endif
                                    @if ($row->subject)<p class="truncate text-xs text-gray-500" title="{{ $row->subject }}">{{ $row->subject }}</p>@endif
                                    @if ($row->error && ! $row->isOpen())<p class="truncate text-xs text-error-600 dark:text-error-400" title="{{ $row->error }}">{{ $row->error }}</p>@endif
                                </td>
                                <td @class(['hidden whitespace-nowrap text-xs text-gray-500', 'md:table-cell' => ! $narrow, '2xl:table-cell' => $narrow])>{{ $row->user_id && isset($users[$row->user_id]) ? $users[$row->user_id] : 'Sistem' }}</td>
                                <td @class(['hidden whitespace-nowrap text-xs tabular-nums text-gray-500', 'sm:table-cell' => ! $narrow, '2xl:table-cell' => $narrow])>{{ $time($row->isQueued() ? $row->queued_at : $row->started_at) }}</td>
                                <td class="hidden whitespace-nowrap text-right tabular-nums sm:table-cell">{{ $row->durationLabel() }}</td>
                                <td class="hidden whitespace-nowrap text-right tabular-nums sm:table-cell">{{ $usd($row->cost_usd) }}</td>
                                <td class="whitespace-nowrap text-right"><x-ta.pill :tone="$statusTone($row)">{{ $row->statusLabel() }}</x-ta.pill></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-gray-500">Bu filtrede AI işi yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-3">{{ $rows->links() }}</div>
            @if ($isAdmin)
                {{-- Record cleanup: a quiet footer; deleting the selected rows lives in the selection bar below. --}}
                <div class="flex flex-wrap items-center gap-2 pt-2 text-xs text-gray-500" data-ai-jobs-cleanup>
                    <span class="inline-flex items-center gap-1.5">
                        <input type="number" min="1" wire:model="olderThanDays" aria-label="Gün" class="h-8 w-16 rounded-lg border border-gray-200 bg-white px-2 text-[13px] dark:border-gray-700 dark:bg-transparent">
                        <button type="button" wire:click="deleteOlder" wire:confirm="Bu günden eski bitmiş kayıtlar silinsin mi?" class="btn">günden eski kayıtları sil</button>
                    </span>
                    @error('olderThanDays')<span class="text-error-600">{{ $message }}</span>@enderror
                    <span class="text-gray-500">Çalışan ve sıradaki işler silinmez.</span>
                </div>
                @if (count($selected) > 0)
                    <div class="sticky bottom-3 z-30 mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-theme-lg ring-1 ring-white/10 dark:bg-gray-800" data-ai-jobs-selection>
                        <span class="font-medium">{{ count($selected) }} kayıt seçili</span>
                        <button type="button" wire:click="deleteSelected" wire:confirm="Seçilen kayıtlar silinsin mi?" class="h-8 rounded-lg border border-white/20 px-3 text-xs font-medium text-white hover:bg-white/10" data-ai-jobs-delete-selected>Seçilenleri sil ({{ count($selected) }})</button>
                        <button type="button" wire:click="$set('selected', [])" class="ml-auto text-gray-300 hover:underline">Seçimi temizle</button>
                    </div>
                @endif
            @endif
        </section>

        @if ($detailData !== null)
            @php($item = $detailData['row'])
            <aside wire:key="ai-job-detail-{{ $item->id }}" x-data x-init="if (window.innerWidth < 1280) $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }))" class="{{ $card }} scroll-mt-16 self-start text-sm" data-ai-job-detail="{{ $item->id }}">
                <div class="flex items-start justify-between gap-3 px-4 py-3.5">
                    <div class="min-w-0">
                        <h2 class="section-title break-words" @if ($codeOf($item) !== '') title="{{ $codeOf($item) }}" @endif>{{ $labelOf($item) }}</h2>
                        @if ($detailData['purpose'])<p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $detailData['purpose'] }}</p>@endif
                    </div>
                    <button type="button" wire:click="closeDetail" class="btn-quiet h-7! px-2!" aria-label="Kapat">Kapat</button>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                    <x-ta.pill :tone="$statusTone($item)" class="text-sm!">{{ $item->statusLabel() }}</x-ta.pill>
                    @if ($item->cancelRequested() && $item->isOpen())
                        <span class="text-xs text-warning-700 dark:text-warning-300" data-ai-job-stop-requested>Durdurma isteği gönderildi</span>
                    @endif
                    <span class="ml-auto flex flex-wrap gap-2">
                        @if ($detailData['link'])
                            <a href="{{ $detailData['link'] }}" wire:navigate class="btn h-7! px-2.5! text-xs!" data-ai-job-link>Sonucun kullanıldığı yer</a>
                        @endif
                        @if ($isAdmin && $detailData['stoppable'])
                            <button type="button" wire:click="stop({{ $item->id }})" wire:confirm="{{ $item->isQueued() ? 'İş kuyruktan kaldırılsın mı?' : 'İş durdurulsun mu? Sürmekte olan AI çağrısı biter, sonucu kullanılmaz.' }}" class="btn h-7! px-2.5! text-xs!" data-ai-job-stop>{{ $item->isQueued() ? 'Kuyruktan kaldır' : 'Durdur' }}</button>
                        @endif
                    </span>
                </div>

                <dl class="grid grid-cols-1 gap-x-4 gap-y-2.5 border-t border-gray-200 px-4 py-3.5 text-[13px] sm:grid-cols-2 dark:border-gray-800">
                    <div><dt class="text-xs text-gray-500">Kullanıcı</dt><dd class="text-gray-900 dark:text-gray-200">{{ $item->user_id && isset($detailData['users'][$item->user_id]) ? $detailData['users'][$item->user_id] : 'Sistem' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Konu</dt><dd class="break-words text-gray-900 dark:text-gray-200">{{ $item->subject ?? '—' }}</dd></div>
                    @if ($item->queued_at)<div><dt class="text-xs text-gray-500">Sıraya alındı</dt><dd class="tabular-nums">{{ $time($item->queued_at) }}</dd></div>@endif
                    <div><dt class="text-xs text-gray-500">Başladı</dt><dd class="tabular-nums">{{ $item->isQueued() ? '—' : $time($item->started_at) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Bitti</dt><dd class="tabular-nums">{{ $time($item->finished_at) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Süre</dt><dd class="tabular-nums">{{ $item->durationLabel() }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Maliyet</dt><dd class="tabular-nums">{{ $usd($item->cost_usd) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Token (girdi / çıktı)</dt><dd class="tabular-nums">{{ $item->input_tokens !== null ? number_format($item->input_tokens, 0, ',', '.') : '—' }} / {{ $item->output_tokens !== null ? number_format($item->output_tokens, 0, ',', '.') : '—' }}</dd></div>
                    @unless ($item->isJob())
                        <div><dt class="text-xs text-gray-500">Sağlayıcı / model</dt><dd class="break-words">{{ $item->provider ?? '—' }} · {{ $item->model ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Prompt sürümü</dt><dd>{{ $detailData['version'] !== null ? 'v'.$detailData['version'] : 'Kod varsayılanı / kayıtsız' }}</dd></div>
                    @endunless
                    @if ($detailData['parent'])
                        <div class="sm:col-span-2"><dt class="text-xs text-gray-500">Bağlı olduğu iş</dt><dd><button type="button" wire:click="show({{ $detailData['parent']->id }})" class="link-neutral text-left">{{ $labelOf($detailData['parent']) }} (#{{ $detailData['parent']->id }}) · {{ $detailData['parent']->statusLabel() }}</button></dd></div>
                    @endif
                    @if ($item->cancel_requested_at)
                        <div class="sm:col-span-2"><dt class="text-xs text-gray-500">Durdurma isteği</dt><dd>{{ $time($item->cancel_requested_at) }}@if ($item->cancel_requested_by && isset($detailData['users'][$item->cancel_requested_by])), {{ $detailData['users'][$item->cancel_requested_by] }}@endif</dd></div>
                    @endif
                </dl>

                @if ($item->error)
                    <div class="border-t border-gray-200 px-4 py-3.5 dark:border-gray-800">
                        <p class="text-xs font-medium text-gray-700 dark:text-gray-200">{{ $item->status === 'cancelled' ? 'Not' : 'Hata' }}</p>
                        <p class="tone-critical mt-1 break-words rounded-lg px-3 py-2 text-xs" data-ai-job-error>{{ $item->error }}</p>
                    </div>
                @endif

                @if ($item->isJob())
                    <div class="border-t border-gray-200 px-4 py-3.5 dark:border-gray-800">
                        <p class="text-xs font-medium text-gray-700 dark:text-gray-200">AI çağrıları <span class="font-normal text-gray-500">{{ $detailData['calls']->count() }}</span></p>
                        <table class="mt-1 w-full text-xs">
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                @forelse ($detailData['calls'] as $call)
                                    <tr wire:key="ai-job-call-{{ $call->id }}" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:click="show({{ $call->id }})" data-ai-job-call="{{ $call->id }}">
                                        <td class="py-2 pr-2">{{ $labelOf($call) }}@if ($call->model)<span class="ml-1 text-gray-500">{{ $call->model }}</span>@endif</td>
                                        <td class="py-2 pr-2 text-right tabular-nums">{{ $call->durationLabel() }}</td>
                                        <td class="py-2 pr-2 text-right tabular-nums">{{ $usd($call->cost_usd) }}</td>
                                        <td class="py-2 text-right {{ $statusClass($call) }}">{{ $call->statusLabel() }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="py-2 text-gray-500">{{ $item->isQueued() ? 'İş henüz başlamadı.' : 'Henüz AI çağrısı yok.' }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="border-t border-gray-200 px-4 py-3.5 dark:border-gray-800">
                        <p class="text-xs font-medium text-gray-700 dark:text-gray-200">Gönderilen girdi</p>
                        <pre class="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300" data-ai-job-input>{{ $item->input_text ?? '—' }}</pre>
                        <p class="mt-3 text-xs font-medium text-gray-700 dark:text-gray-200">Çıktı</p>
                        <pre class="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300" data-ai-job-output>{{ $item->output_text ?? ($item->isOpen() ? 'Yanıt bekleniyor…' : '—') }}</pre>
                    </div>
                @endif

                @if ($item->operation || $item->agent || $item->job_uuid)
                    <details class="border-t border-gray-200 px-4 py-3 text-xs dark:border-gray-800" data-ai-job-technical>
                        <summary class="cursor-pointer font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">Teknik ayrıntı</summary>
                        <dl class="mt-2 grid grid-cols-1 gap-y-1.5 rounded-lg bg-gray-50 p-3 font-mono text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">
                            @if ($item->operation)<div><dt class="font-sans text-gray-500">İşlem kodu</dt><dd class="break-all">{{ $item->operation }}</dd></div>@endif
                            @if ($item->agent)<div><dt class="font-sans text-gray-500">Ajan</dt><dd class="break-all">{{ $item->agent }}</dd></div>@endif
                            @if ($item->job_uuid)<div><dt class="font-sans text-gray-500">Kuyruk işi</dt><dd class="break-all">{{ class_basename((string) $item->job_class) }} · {{ $item->job_uuid }}@if ($item->queue_name) · {{ $item->queue_name }}@endif</dd></div>@endif
                            <div><dt class="font-sans text-gray-500">Kayıt</dt><dd class="tabular-nums">#{{ $item->id }}</dd></div>
                        </dl>
                    </details>
                @endif
            </aside>
        @endif
    </div>
</div>
