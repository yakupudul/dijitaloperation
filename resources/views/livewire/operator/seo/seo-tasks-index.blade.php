<div class="space-y-6">
    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.nav.groups.operations'),
        'title' => 'SEO Görevleri',
        'subtitle' => 'Tüm markalar için tek liste: düzelt, güçlendir, oluştur, AI görünürlük. Öncelik sırasına göre; her satırda kanıt, checklist ve içerik briefi.',
    ])

    <livewire:operator.seo.seo-tasks-panel :key="'seo-tasks-global'" />
</div>
