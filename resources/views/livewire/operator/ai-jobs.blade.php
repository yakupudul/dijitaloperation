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
    $input = 'rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900';
    $card = 'surface';
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
<div class="space-y-5" data-ai-jobs @if ($polling) wire:poll.5s @endif>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">AI işleri</h1>
            <p class="mt-1 text-sm text-gray-500">Çalışan, sıradaki ve geçmiş AI işleri ({{ $retentionDays }} gün saklanır). Satıra tıklayınca ayrıntı açılır.</p>
        </div>
        @if ($isAdmin)
            <a href="{{ route('operator.settings.ai-operations') }}" wire:navigate class="link-neutral text-sm">Ayarlar › AI işlemleri ve promptlar</a>
        @endif
    </div>

    @if (session('ai-jobs-status'))
        <p class="tone-ok rounded-lg px-4 py-2 text-sm" data-ai-jobs-status>{{ session('ai-jobs-status') }}</p>
    @endif

    <div class="{{ $card }} flex flex-wrap items-end gap-3 px-4 py-3 text-sm" data-ai-jobs-filters>
        <div class="flex flex-wrap gap-1">
            <button type="button" wire:click="$set('status', '')" @class(['rounded-full px-3 py-1', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $status === '', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $status !== ''])>Tümü</button>
            @foreach ($statuses as $key => $label)
                <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['rounded-full px-3 py-1', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $status === $key, 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $status !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <label class="flex min-w-0 flex-col gap-1">
            <span class="text-xs text-gray-500">İşlem</span>
            <select wire:model.live="operation" class="{{ $input }} max-w-full">
                <option value="">Tümü</option>
                @foreach ($operations as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex min-w-0 flex-col gap-1">
            <span class="text-xs text-gray-500">Kullanıcı</span>
            <select wire:model.live="user" class="{{ $input }}">
                <option value="">Tümü</option>
                @foreach ($userOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex min-w-0 flex-col gap-1">
            <span class="text-xs text-gray-500">Başlangıç</span>
            <input type="date" wire:model.live="from" class="{{ $input }}">
        </label>
        <label class="flex min-w-0 flex-col gap-1">
            <span class="text-xs text-gray-500">Bitiş</span>
            <input type="date" wire:model.live="to" class="{{ $input }}">
        </label>
        <button type="button" wire:click="resetFilters" class="text-xs font-semibold text-gray-500">Filtreleri temizle</button>
    </div>

    <div @class(['grid gap-5', 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]' => $detailData !== null])>
        <section class="{{ $card }} overflow-x-auto" data-ai-jobs-list>
            @php($narrow = $detailData !== null)
            <table class="w-full text-sm">
                <thead class="border-b border-gray-100 text-left text-xs text-gray-500 dark:border-gray-700">
                    <tr class="whitespace-nowrap">
                        @if ($isAdmin)<th class="w-8 py-2 pl-4"><span class="sr-only">Seç</span></th>@endif
                        <th class="px-3 py-2 font-medium">İşlem</th>
                        <th @class(['hidden px-3 py-2 font-medium', 'md:table-cell' => ! $narrow, '2xl:table-cell' => $narrow])>Kullanıcı</th>
                        <th @class(['hidden px-3 py-2 font-medium', 'sm:table-cell' => ! $narrow, '2xl:table-cell' => $narrow])>Başladı</th>
                        <th class="px-3 py-2 text-right font-medium">Süre</th>
                        <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">Maliyet</th>
                        <th class="px-4 py-2 text-right font-medium">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($rows as $row)
                        <tr wire:key="ai-job-{{ $row->id }}" @class(['cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40', 'bg-gray-50 dark:bg-white/[0.04]' => $detail === $row->id]) wire:click="show({{ $row->id }})" data-ai-job-row="{{ $row->id }}">
                            @if ($isAdmin)
                                <td class="py-2 pl-4" wire:click.stop>
                                    @unless ($row->isOpen())<input type="checkbox" value="{{ $row->id }}" wire:model.live="selected" aria-label="Seç">@endunless
                                </td>
                            @endif
                            <td class="min-w-0 px-3 py-2">
                                <span class="break-words font-medium text-gray-800 dark:text-white/90" @if ($codeOf($row) !== '') title="{{ $codeOf($row) }}" @endif>{{ $labelOf($row) }}</span>
                                @if ($row->isJob())<span class="ml-1 rounded bg-gray-100 px-1 text-[10px] text-gray-500 dark:bg-gray-700">iş</span>@endif
                                @if ($row->subject)<p class="max-w-md truncate text-xs text-gray-500" title="{{ $row->subject }}">{{ $row->subject }}</p>@endif
                                @if ($row->error && ! $row->isOpen())<p class="max-w-md truncate text-xs text-error-600" title="{{ $row->error }}">{{ $row->error }}</p>@endif
                            </td>
                            <td @class(['hidden px-3 py-2 text-xs text-gray-500', 'md:table-cell' => ! $narrow, '2xl:table-cell' => $narrow])>{{ $row->user_id && isset($users[$row->user_id]) ? $users[$row->user_id] : 'Sistem' }}</td>
                            <td @class(['hidden whitespace-nowrap px-3 py-2 text-xs text-gray-500', 'sm:table-cell' => ! $narrow, '2xl:table-cell' => $narrow])>{{ $time($row->isQueued() ? $row->queued_at : $row->started_at) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $row->durationLabel() }}</td>
                            <td class="hidden whitespace-nowrap px-3 py-2 text-right tabular-nums sm:table-cell">{{ $usd($row->cost_usd) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-xs font-medium {{ $statusClass($row) }}">{{ $row->statusLabel() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-4 text-gray-500">Bu filtrede AI işi yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-2">{{ $rows->links() }}</div>
            @if ($isAdmin)
                {{-- Record cleanup: a quiet footer; deleting the selected rows lives in the selection bar below. --}}
                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-gray-700" data-ai-jobs-cleanup>
                    <span class="inline-flex items-center gap-1">
                        <input type="number" min="1" wire:model="olderThanDays" aria-label="Gün" class="w-16 {{ $input }}">
                        <button type="button" wire:click="deleteOlder" wire:confirm="Bu günden eski bitmiş kayıtlar silinsin mi?" class="rounded-md px-2 py-1 font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5">günden eski kayıtları sil</button>
                    </span>
                    @error('olderThanDays')<span class="text-error-600">{{ $message }}</span>@enderror
                    <span class="text-gray-400">Çalışan ve sıradaki işler silinmez.</span>
                </div>
                @if (count($selected) > 0)
                    <div class="sticky bottom-3 z-30 m-3 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-theme-lg ring-1 ring-white/10 dark:bg-gray-800" data-ai-jobs-selection>
                        <span class="font-semibold">{{ count($selected) }} kayıt seçili</span>
                        <button type="button" wire:click="deleteSelected" wire:confirm="Seçilen kayıtlar silinsin mi?" class="h-8 rounded-lg border border-white/20 px-3 text-xs font-semibold text-error-300 hover:bg-white/10" data-ai-jobs-delete-selected>Seçilenleri sil ({{ count($selected) }})</button>
                        <button type="button" wire:click="$set('selected', [])" class="ml-auto text-gray-300 hover:underline">Seçimi temizle</button>
                    </div>
                @endif
            @endif
        </section>

        @if ($detailData !== null)
            @php($item = $detailData['row'])
            <aside class="{{ $card }} space-y-4 p-5 text-sm" data-ai-job-detail="{{ $item->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="break-words text-base font-semibold text-gray-800 dark:text-white/90" @if ($codeOf($item) !== '') title="{{ $codeOf($item) }}" @endif>{{ $labelOf($item) }}</h2>
                        @if ($detailData['purpose'])<p class="mt-1 text-gray-600 dark:text-gray-300">{{ $detailData['purpose'] }}</p>@endif
                    </div>
                    <button type="button" wire:click="closeDetail" class="text-xs font-semibold text-gray-500">Kapat ✕</button>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-semibold {{ $statusClass($item) }}">{{ $item->statusLabel() }}</span>
                    @if ($item->cancelRequested() && $item->isOpen())
                        <span class="rounded bg-warning-50 px-2 py-0.5 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-300" data-ai-job-stop-requested>Durdurma isteği gönderildi</span>
                    @endif
                    @if ($isAdmin && $detailData['stoppable'])
                        <button type="button" wire:click="stop({{ $item->id }})" wire:confirm="{{ $item->isQueued() ? 'İş kuyruktan kaldırılsın mı?' : 'İş durdurulsun mu? Sürmekte olan AI çağrısı biter, sonucu kullanılmaz.' }}" class="rounded-lg px-3 py-1 text-xs font-semibold text-error-700 ring-1 ring-inset ring-error-200 hover:bg-error-50 dark:text-error-300 dark:ring-error-500/30" data-ai-job-stop>{{ $item->isQueued() ? 'Kuyruktan kaldır' : 'Durdur' }}</button>
                    @endif
                    @if ($detailData['link'])
                        <a href="{{ $detailData['link'] }}" wire:navigate class="link-neutral text-xs" data-ai-job-link>Sonucun kullanıldığı yer →</a>
                    @endif
                </div>

                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-xs sm:grid-cols-2">
                    <div><dt class="text-gray-400">Kullanıcı</dt><dd class="text-gray-800 dark:text-gray-200">{{ $item->user_id && isset($detailData['users'][$item->user_id]) ? $detailData['users'][$item->user_id] : 'Sistem' }}</dd></div>
                    <div><dt class="text-gray-400">Konu</dt><dd class="text-gray-800 dark:text-gray-200">{{ $item->subject ?? '—' }}</dd></div>
                    @if ($item->queued_at)<div><dt class="text-gray-400">Sıraya alındı</dt><dd>{{ $time($item->queued_at) }}</dd></div>@endif
                    <div><dt class="text-gray-400">Başladı</dt><dd>{{ $item->isQueued() ? '—' : $time($item->started_at) }}</dd></div>
                    <div><dt class="text-gray-400">Bitti</dt><dd>{{ $time($item->finished_at) }}</dd></div>
                    <div><dt class="text-gray-400">Süre</dt><dd>{{ $item->durationLabel() }}</dd></div>
                    <div><dt class="text-gray-400">Maliyet</dt><dd>{{ $usd($item->cost_usd) }}</dd></div>
                    <div><dt class="text-gray-400">Token (girdi / çıktı)</dt><dd>{{ $item->input_tokens !== null ? number_format($item->input_tokens, 0, ',', '.') : '—' }} / {{ $item->output_tokens !== null ? number_format($item->output_tokens, 0, ',', '.') : '—' }}</dd></div>
                    @unless ($item->isJob())
                        <div><dt class="text-gray-400">Sağlayıcı / model</dt><dd>{{ $item->provider ?? '—' }} · {{ $item->model ?? '—' }}</dd></div>
                        <div><dt class="text-gray-400">Prompt sürümü</dt><dd>{{ $detailData['version'] !== null ? 'v'.$detailData['version'] : 'Kod varsayılanı / kayıtsız' }}</dd></div>
                    @endunless
                    <div><dt class="text-gray-400">Kayıt</dt><dd class="tabular-nums">#{{ $item->id }}</dd></div>
                    @if ($detailData['parent'])
                        <div class="col-span-2"><dt class="text-gray-400">Bağlı olduğu iş</dt><dd><button type="button" wire:click="show({{ $detailData['parent']->id }})" class="link-neutral text-left">{{ $labelOf($detailData['parent']) }} (#{{ $detailData['parent']->id }}) · {{ $detailData['parent']->statusLabel() }}</button></dd></div>
                    @endif
                    @if ($item->cancel_requested_at)
                        <div class="col-span-2"><dt class="text-gray-400">Durdurma isteği</dt><dd>{{ $time($item->cancel_requested_at) }}@if ($item->cancel_requested_by && isset($detailData['users'][$item->cancel_requested_by])) · {{ $detailData['users'][$item->cancel_requested_by] }}@endif</dd></div>
                    @endif
                </dl>

                @if ($item->operation || $item->agent || $item->job_uuid)
                    <details class="text-xs" data-ai-job-technical>
                        <summary class="cursor-pointer font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">Teknik ayrıntı</summary>
                        <dl class="mt-2 grid grid-cols-1 gap-y-1.5 rounded-lg bg-gray-50 p-3 font-mono text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                            @if ($item->operation)<div><dt class="font-sans text-gray-400">İşlem kodu</dt><dd class="break-all">{{ $item->operation }}</dd></div>@endif
                            @if ($item->agent)<div><dt class="font-sans text-gray-400">Ajan</dt><dd class="break-all">{{ $item->agent }}</dd></div>@endif
                            @if ($item->job_uuid)<div><dt class="font-sans text-gray-400">Kuyruk işi</dt><dd class="break-all">{{ class_basename((string) $item->job_class) }} · {{ $item->job_uuid }}@if ($item->queue_name) · {{ $item->queue_name }}@endif</dd></div>@endif
                        </dl>
                    </details>
                @endif

                @if ($item->error)
                    <div>
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $item->status === 'cancelled' ? 'Not' : 'Hata' }}</p>
                        <p class="mt-1 rounded-lg bg-error-50 px-3 py-2 text-xs text-error-700 dark:bg-error-500/10 dark:text-error-300" data-ai-job-error>{{ $item->error }}</p>
                    </div>
                @endif

                @if ($item->isJob())
                    <div>
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">AI çağrıları ({{ $detailData['calls']->count() }})</p>
                        <table class="mt-1 w-full text-xs">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @forelse ($detailData['calls'] as $call)
                                    <tr wire:key="ai-job-call-{{ $call->id }}" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:click="show({{ $call->id }})" data-ai-job-call="{{ $call->id }}">
                                        <td class="py-1.5 pr-2">{{ $labelOf($call) }}@if ($call->model)<span class="ml-1 text-gray-400">{{ $call->model }}</span>@endif</td>
                                        <td class="py-1.5 pr-2 text-right tabular-nums">{{ $call->durationLabel() }}</td>
                                        <td class="py-1.5 pr-2 text-right tabular-nums">{{ $usd($call->cost_usd) }}</td>
                                        <td class="py-1.5 text-right {{ $statusClass($call) }}">{{ $call->statusLabel() }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="py-1.5 text-gray-500">{{ $item->isQueued() ? 'İş henüz başlamadı.' : 'Henüz AI çağrısı yok.' }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <div>
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">Gönderilen girdi</p>
                        <pre class="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300" data-ai-job-input>{{ $item->input_text ?? '—' }}</pre>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">Çıktı</p>
                        <pre class="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300" data-ai-job-output>{{ $item->output_text ?? ($item->isOpen() ? 'Yanıt bekleniyor…' : '—') }}</pre>
                    </div>
                @endif
            </aside>
        @endif
    </div>
</div>
