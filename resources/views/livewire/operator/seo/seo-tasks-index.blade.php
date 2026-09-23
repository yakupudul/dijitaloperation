<div class="space-y-6">
    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.nav.groups.operations'),
        'title' => 'SEO Görevleri',
        'subtitle' => 'Her hafta hangi sitede ne yazılacağı, neyin düzeltileceği ve hangi sayfanın güçlendirileceği. Etkiye göre sıralı; her görevde kanıt, yapılacaklar ve yazara verilecek brief var.',
    ])

    <livewire:operator.seo.seo-tasks-panel :key="'seo-tasks-global'" />
</div>
