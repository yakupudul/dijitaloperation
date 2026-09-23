<div class="space-y-6">
    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.nav.groups.operations'),
        'title' => 'Danışman',
        'subtitle' => 'Google Ads, Meta Ads ve İşletme Profili\'nde bu hafta gerçekten yapılması gerekenler. Sistem hazırlar, sen uygularsın; hesaplara hiçbir şey yazılmaz.',
    ])

    <livewire:operator.advisor.advisor-panel :key="'advisor-global'" />
</div>
