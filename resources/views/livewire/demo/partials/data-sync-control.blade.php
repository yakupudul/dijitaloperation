<div class="space-y-2">
    @if($feedback !== '')
        <div @class([
            'rounded-lg px-3 py-2 text-xs ring-1 ring-inset',
            'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $feedbackTone === 'success',
            'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => $feedbackTone !== 'success',
        ])>{{ $feedback }}</div>
    @endif

    @include('livewire.demo.partials.data-sync-status', [
        'syncStatus' => $syncStatus,
        'action' => 'updateData',
        'buttonLabel' => $buttonLabel !== '' ? $buttonLabel : (app()->getLocale() === 'tr' ? 'Şimdi Güncelle' : 'Update Now'),
        'title' => $title !== '' ? $title : (app()->getLocale() === 'tr' ? 'Veri Güncelliği' : 'Data Freshness'),
        'showProviders' => $showProviders,
        'showButton' => $showButton,
        'compact' => $compact,
    ])
</div>