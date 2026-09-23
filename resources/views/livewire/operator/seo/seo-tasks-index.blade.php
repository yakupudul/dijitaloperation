<div class="space-y-6">
    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.nav.groups.operations'),
        'title' => 'SEO Görevleri',
        'subtitle' => 'Bu hafta hangi sitede ne yazılacak, ne düzeltilecek, hangi sayfa güçlendirilecek. Her görevde kanıt, adımlar ve yazara verilecek brief hazır.',
    ])

    <livewire:operator.seo.seo-tasks-panel :key="'seo-tasks-global'" />
</div>
