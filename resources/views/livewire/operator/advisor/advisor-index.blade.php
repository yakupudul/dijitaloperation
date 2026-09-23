<div class="space-y-6">
    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.nav.groups.operations'),
        'title' => 'Reklam Danışmanı',
        'subtitle' => 'Google Ads hesaplarında bu hafta gerçekten yapılması gerekenler: israfı kes, kârlı kampanyayı büyüt, ölçümü düzelt. Sistem hazırlar, sen Google Ads\'te uygularsın.',
    ])

    <livewire:operator.advisor.advisor-panel :key="'advisor-global'" />
</div>
