@php
    $usd = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, $value > 0 && $value < 1 ? 4 : 2, ',', '.');
    $time = fn ($value): string => $value?->timezone(\App\Livewire\Operator\AiJobsPage::TIMEZONE)->format('d.m.Y H:i:s') ?? '—';
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $statusClass = fn (\App\Models\AiLiveOperation $row): string => match ($row->status) {
        'running' => 'text-violet-700 dark:text-violet-300',
        'queued' => 'text-amber-700 dark:text-amber-300',
        'done' => 'text-emerald-600',
        'cancelled' => 'text-gray-500',
        default => 'text-rose-600',
    };
    $input = 'rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900';
@endphp
<div class="space-y-5" data-ai-jobs @if ($polling) wire:poll.5s @endif>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">AI işleri</h1>
            <p class="text-sm text-gray-500">Çalışan, sıradaki ve geçmiş AI işleri ({{ $retentionDays }} gün saklanır). Satıra tıklayınca ayrıntı açılır.</p>
        </div>
        @if ($isAdmin)
            <a href="{{ route('operator.settings.ai-operations') }}" wire:navigate class="text-sm font-semibold text-brand-600">Ayarlar › AI işlemleri ve promptlar</a>
        @endif
    </div>

    @if (session('ai-jobs-status'))
        <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" data-ai-jobs-status>{{ session('ai-jobs-status') }}</p>
    @endif

    <div class="{{ $card }} flex flex-wrap items-end gap-3 px-5 py-3 text-sm" data-ai-jobs-filters>
        <div class="flex flex-wrap gap-1">
            <button type="button" wire:click="$set('status', '')" @class(['rounded-full px-3 py-1', 'bg-brand-500 text-white' => $status === '', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $status !== ''])>Tümü</button>
            @foreach ($statuses as $key => $label)
                <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['rounded-full px-3 py-1', 'bg-brand-500 text-white' => $status === $key, 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $status !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <label class="flex flex-col gap-1">
            <span class="text-xs text-gray-500">İşlem</span>
            <select wire:model.live="operation" class="{{ $input }}">
                <option value="">Tümü</option>
                @foreach ($operations as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs text-gray-500">Kullanıcı</span>
            <select wire:model.live="user" class="{{ $input }}">
                <option value="">Tümü</option>
                @foreach ($userOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs text-gray-500">Başlangıç</span>
            <input type="date" wire:model.live="from" class="{{ $input }}">
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs text-gray-500">Bitiş</span>
            <input type="date" wire:model.live="to" class="{{ $input }}">
        </label>
        <button type="button" wire:click="resetFilters" class="text-xs font-semibold text-gray-500">Filtreleri temizle</button>
    </div>

    <div @class(['grid gap-5', 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]' => $detailData !== null])>
        <section class="{{ $card }} overflow-x-auto" data-ai-jobs-list>
            @if ($isAdmin)
                <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-2 text-xs dark:border-gray-700">
                    <button type="button" wire:click="deleteSelected" wire:confirm="Seçilen kayıtlar silinsin mi?" class="rounded-lg border border-rose-200 px-2 py-1 font-semibold text-rose-600" data-ai-jobs-delete-selected>Seçilenleri sil ({{ count($selected) }})</button>
                    <span class="flex items-center gap-1">
                        <input type="number" min="1" wire:model="olderThanDays" class="w-16 {{ $input }}">
                        <button type="button" wire:click="deleteOlder" wire:confirm="Bu günden eski bitmiş kayıtlar silinsin mi?" class="rounded-lg border border-rose-200 px-2 py-1 font-semibold text-rose-600">günden eski kayıtları sil</button>
                    </span>
                    @error('olderThanDays')<span class="text-rose-600">{{ $message }}</span>@enderror
                    <span class="text-gray-400">Çalışan ve sıradaki işler silinmez.</span>
                </div>
            @endif
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr>
                        @if ($isAdmin)<th class="w-8 py-2 pl-5"></th>@endif
                        <th class="px-3 py-2">İşlem</th>
                        <th class="px-3 py-2">Kullanıcı</th>
                        <th class="px-3 py-2">Başladı</th>
                        <th class="px-3 py-2 text-right">Süre</th>
                        <th class="px-3 py-2 text-right">Maliyet</th>
                        <th class="px-5 py-2 text-right">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($rows as $row)
                        <tr wire:key="ai-job-{{ $row->id }}" @class(['cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40', 'bg-brand-50/60 dark:bg-brand-500/10' => $detail === $row->id]) wire:click="show({{ $row->id }})" data-ai-job-row="{{ $row->id }}">
                            @if ($isAdmin)
                                <td class="py-2 pl-5" wire:click.stop>
                                    @unless ($row->isOpen())<input type="checkbox" value="{{ $row->id }}" wire:model.live="selected" aria-label="Seç">@endunless
                                </td>
                            @endif
                            <td class="px-3 py-2">
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ $row->label }}</span>
                                @if ($row->isJob())<span class="ml-1 rounded bg-gray-100 px-1 text-[10px] uppercase text-gray-500 dark:bg-gray-700">iş</span>@endif
                                @if ($row->subject)<p class="max-w-md truncate text-xs text-gray-500">{{ $row->subject }}</p>@endif
                                @if ($row->error && ! $row->isOpen())<p class="max-w-md truncate text-xs text-rose-600" title="{{ $row->error }}">{{ $row->error }}</p>@endif
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500">{{ $row->user_id && isset($users[$row->user_id]) ? $users[$row->user_id] : 'Sistem' }}</td>
                            <td class="px-3 py-2 text-xs text-gray-500">{{ $time($row->isQueued() ? $row->queued_at : $row->started_at) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row->durationLabel() }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $usd($row->cost_usd) }}</td>
                            <td class="px-5 py-2 text-right text-xs font-medium {{ $statusClass($row) }}">{{ $row->statusLabel() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-4 text-gray-500">Bu filtrede AI işi yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-5 py-2">{{ $rows->links() }}</div>
        </section>

        @if ($detailData !== null)
            @php($item = $detailData['row'])
            <aside class="{{ $card }} space-y-4 p-5 text-sm" data-ai-job-detail="{{ $item->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $item->label }}</h2>
                        @if ($item->operation)<p class="font-mono text-xs text-gray-400">{{ $item->operation }}</p>@endif
                        @if ($detailData['purpose'])<p class="mt-1 text-gray-600 dark:text-gray-300">{{ $detailData['purpose'] }}</p>@endif
                    </div>
                    <button type="button" wire:click="closeDetail" class="text-xs font-semibold text-gray-500">Kapat ✕</button>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-semibold {{ $statusClass($item) }}">{{ $item->statusLabel() }}</span>
                    @if ($item->cancelRequested() && $item->isOpen())
                        <span class="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300" data-ai-job-stop-requested>Durdurma isteği gönderildi</span>
                    @endif
                    @if ($isAdmin && $detailData['stoppable'])
                        <button type="button" wire:click="stop({{ $item->id }})" wire:confirm="{{ $item->isQueued() ? 'İş kuyruktan kaldırılsın mı?' : 'İş durdurulsun mu? Sürmekte olan AI çağrısı biter, sonucu kullanılmaz.' }}" class="rounded-lg bg-rose-600 px-3 py-1 text-xs font-semibold text-white" data-ai-job-stop>{{ $item->isQueued() ? 'Kuyruktan kaldır' : 'Durdur' }}</button>
                    @endif
                    @if ($detailData['link'])
                        <a href="{{ $detailData['link'] }}" wire:navigate class="text-xs font-semibold text-brand-600" data-ai-job-link>Sonucun kullanıldığı yer →</a>
                    @endif
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
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
                    <div><dt class="text-gray-400">Kayıt</dt><dd class="font-mono">#{{ $item->id }}@if ($item->agent) · {{ $item->agent }}@endif</dd></div>
                    @if ($item->job_uuid)
                        <div><dt class="text-gray-400">Kuyruk işi</dt><dd class="break-all font-mono">{{ class_basename((string) $item->job_class) }} · {{ $item->job_uuid }}@if ($item->queue_name) · {{ $item->queue_name }}@endif</dd></div>
                    @endif
                    @if ($detailData['parent'])
                        <div class="col-span-2"><dt class="text-gray-400">Bağlı olduğu iş</dt><dd><button type="button" wire:click="show({{ $detailData['parent']->id }})" class="font-semibold text-brand-600">{{ $detailData['parent']->label }} (#{{ $detailData['parent']->id }}) · {{ $detailData['parent']->statusLabel() }}</button></dd></div>
                    @endif
                    @if ($item->cancel_requested_at)
                        <div class="col-span-2"><dt class="text-gray-400">Durdurma isteği</dt><dd>{{ $time($item->cancel_requested_at) }}@if ($item->cancel_requested_by && isset($detailData['users'][$item->cancel_requested_by])) · {{ $detailData['users'][$item->cancel_requested_by] }}@endif</dd></div>
                    @endif
                </dl>

                @if ($item->error)
                    <div>
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $item->status === 'cancelled' ? 'Not' : 'Hata' }}</p>
                        <p class="mt-1 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" data-ai-job-error>{{ $item->error }}</p>
                    </div>
                @endif

                @if ($item->isJob())
                    <div>
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">AI çağrıları ({{ $detailData['calls']->count() }})</p>
                        <table class="mt-1 w-full text-xs">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @forelse ($detailData['calls'] as $call)
                                    <tr wire:key="ai-job-call-{{ $call->id }}" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:click="show({{ $call->id }})" data-ai-job-call="{{ $call->id }}">
                                        <td class="py-1.5 pr-2">{{ $call->label }}@if ($call->model)<span class="ml-1 text-gray-400">{{ $call->model }}</span>@endif</td>
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
