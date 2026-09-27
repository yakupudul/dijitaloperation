<?php

namespace App\Livewire\Operator\Work;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * /ads-advisor — Danışman: the Komuta merkezi inbox pre-filtered to Reklam (Google Ads, Meta Ads, İşletme Profili
 * advisor items and ad alerts). The per-account board, negative-list send (ADR-064) and filters stay on the
 * detailed screen (/ads-advisor/detayli).
 */
#[Layout('operator.layouts.app')]
#[Title('Danışman')]
final class AdvisorInboxPage extends CommandCenterPage
{
    #[Url]
    public string $area = 'ads';

    protected function heading(): array
    {
        return [
            'title' => 'Danışman',
            'subtitle' => 'Google Ads, Meta Ads ve İşletme Profili\'nde yapılması gerekenler konuya göre. Sistem hazırlar, sen uygularsın; hesaplara hiçbir şey yazılmaz.',
            'detailed_route' => 'operator.ads_advisor.detailed',
            'detailed_label' => 'Ayrıntılı ekran (hesap panosu, negatif liste gönderimi)',
        ];
    }
}
