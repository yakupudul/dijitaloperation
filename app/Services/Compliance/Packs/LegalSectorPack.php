<?php

namespace App\Services\Compliance\Packs;

use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPack;

/**
 * Hukuk paketi (Faz 14). A DRAFT starter set of phrase checks, not a legal text: review with a lawyer and edit the
 * rules on screen (Ayarlar › Sektör paketleri). Patterns are matched on folded Turkish text.
 */
final class LegalSectorPack implements SectorPack
{
    public function id(): string
    {
        return 'legal';
    }

    public function label(): string
    {
        return 'Hukuk';
    }

    public function description(): string
    {
        return 'Avukatlık ve hukuk büroları. Avukatlık mesleğinde reklam yasağı (Avukatlık Kanunu m.55, TBB Meslek Kuralları) için taslak kontroller — hukuk görüşüyle ekrandan düzenlenmeli.';
    }

    public function sectorCodes(): array
    {
        return ['legal'];
    }

    public function defaultRules(): array
    {
        $all = ComplianceRuleKinds::TEXT_SOURCES;

        return [
            ['rule_key' => 'superlatives', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Üstünlük iddiası', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['en iyi avukat', 'en iyi hukuk', 'en basarili', 'bir numara', 'lider hukuk', 'uzman avukat kadrosu ile en'],
                'message' => 'Avukatlık tanıtımında üstünlük iddiası kullanılmamalı; yalnız bilgilendirme yapılmalı.'],
            ['rule_key' => 'outcome_guarantee', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Sonuç / kazanma vaadi', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['kazanma garantisi', 'davayi kazan', 'kesin kazan', 'garantili', '%100', 'yuzde yuz', 'kesin sonuc'],
                'message' => 'Dava sonucu vaat edilemez; kazanma garantisi ifadeleri kaldırılmalı.'],
            ['rule_key' => 'solicitation', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'İş toplama / ücret teklifi', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['ucretsiz danisma', 'ucretsiz avukat', 'indirim', 'kampanya', 'uygun ucretli avukat', 'en ucuz avukat'],
                'message' => 'Ücret, indirim veya ücretsiz danışma ile iş sağlamaya yönelik ifade kullanılmamalı.'],
        ];
    }
}
