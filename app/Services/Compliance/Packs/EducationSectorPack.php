<?php

namespace App\Services\Compliance\Packs;

use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPack;

/**
 * Eğitim paketi (Faz 14). A DRAFT starter set of phrase checks, not a legal text: review with a lawyer and edit the
 * rules on screen (Ayarlar › Sektör paketleri). Patterns are matched on folded Turkish text.
 */
final class EducationSectorPack implements SectorPack
{
    public function id(): string
    {
        return 'education';
    }

    public function label(): string
    {
        return 'Eğitim';
    }

    public function description(): string
    {
        return 'Eğitim kurumları ve kurslar. Başarı / yerleştirme vaatleri için taslak kontroller.';
    }

    public function sectorCodes(): array
    {
        return ['education'];
    }

    public function defaultRules(): array
    {
        $all = ComplianceRuleKinds::TEXT_SOURCES;

        return [
            ['rule_key' => 'success_guarantee', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Başarı / yerleştirme garantisi', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['basari garantisi', 'garantili basari', '%100 basari', 'yuzde yuz basari', 'kesin kazanirsiniz', 'yerlestirme garantisi', 'garantili gecis'],
                'message' => 'Sınav başarısı veya yerleştirme garanti edilemez.'],
            ['rule_key' => 'superlatives', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Üstünlük iddiası', 'severity' => 'medium', 'applies_to' => $all,
                'patterns' => ['en iyi dershane', 'en iyi kurs', 'en basarili', 'bir numara', 'turkiyenin en'],
                'message' => 'Kanıtlanamayan üstünlük iddiası kullanılmamalı.'],
        ];
    }
}
