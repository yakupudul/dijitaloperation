<?php

namespace App\Livewire\Operator\Work;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * /seo-tasks — SEO görevleri: the Komuta merkezi inbox pre-filtered to SEO. Briefs, WordPress drafts (ADR-064),
 * service mapping answers and plan refresh stay on the detailed screen (/seo-tasks/detayli).
 */
#[Layout('operator.layouts.app')]
#[Title('SEO Görevleri')]
final class SeoInboxPage extends CommandCenterPage
{
    #[Url]
    public string $area = 'seo';

    protected function heading(): array
    {
        return [
            'title' => 'SEO Görevleri',
            'subtitle' => 'Hangi sitede ne yazılacak, ne düzeltilecek, hangi sayfa güçlendirilecek; konuya göre, etkilenen sitelerle birlikte.',
            'detailed_route' => 'operator.seo_tasks.detailed',
            'detailed_label' => 'Ayrıntılı ekran (brief, WordPress taslağı, hizmet eşleştirme)',
        ];
    }
}
