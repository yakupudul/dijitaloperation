{{-- Ayarlar › Bağlantılar (Aşama 6): four steps, each done or with its one next action. --}}
<section class="min-w-0 text-sm" data-connection-steps>
    <div class="section-head">
        <h2 class="section-title">Bağlantılar <span class="section-count">{{ $done }}/{{ count($steps) }} hazır</span></h2>
    </div>
    @if ($message !== '')
        <p class="mb-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-connection-message>{{ $message }}</p>
    @endif
    <ol class="card">
        @foreach ($steps as $i => $step)
            <li class="card-row" wire:key="step-{{ $step['key'] }}" data-connection-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
                <span @class(['flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold', 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400' => $step['done'], 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $step['done']]) aria-hidden="true">{{ $step['done'] ? '✓' : $i + 1 }}</span>
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-gray-900 dark:text-white">{{ $step['title'] }}</p>
                    <p class="mt-0.5 text-[13px] text-gray-500">{{ $step['line'] }}</p>
                </div>
                @if ($step['action'])
                    @if (isset($step['action']['url']))
                        <a href="{{ $step['action']['url'] }}" wire:navigate class="{{ $step['done'] ? 'btn-quiet' : 'btn' }}">{{ $step['action']['label'] }}</a>
                    @else
                        <button type="button" wire:click="{{ $step['action']['click'] }}" wire:loading.attr="disabled" class="{{ $step['done'] ? 'btn-quiet' : 'btn' }}" data-step-action="{{ $step['key'] }}">{{ $step['action']['label'] }}</button>
                    @endif
                @endif
            </li>
        @endforeach
    </ol>
</section>
