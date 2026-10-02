<?php

namespace App\Services\Compliance\Packs;

use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPack;

/**
 * Gıda / takviye paketi (Faz 14). A DRAFT starter set of phrase checks, not a legal text: review with a lawyer and edit the
 * rules on screen (Ayarlar › Sektör paketleri). Patterns are matched on folded Turkish text.
 */
final class FoodSupplementSectorPack implements SectorPack
{
    public function id(): string
    {
        return 'food_supplement';
    }

    public function label(): string
    {
        return 'Gıda / takviye';
    }

    public function description(): string
    {
        return 'Gıda ve gıda takviyesi. Türk Gıda Kodeksi — gıdalara hastalık tedavi/önleme özelliği atfedilemez; taslak kontroller.';
    }

    public function sectorCodes(): array
    {
        return ['food_beverage'];
    }

    public function defaultRules(): array
    {
        $all = ComplianceRuleKinds::TEXT_SOURCES;

        return [
            ['rule_key' => 'health_claims', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Tedavi / hastalık iddiası', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['tedavi eder', 'iyilestirir', 'hastaligi onler', 'kanseri', 'diyabete iyi', 'seker hastaligina', 'tansiyonu dusurur', 'bagisikligi guclendirir', 'zayiflatir', 'kilo verdirir', 'mucize'],
                'message' => 'Gıda veya takviyeye tedavi, hastalık önleme ya da zayıflatma özelliği atfedilemez.'],
            ['rule_key' => 'guarantees', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Sonuç garantisi', 'severity' => 'medium', 'applies_to' => $all,
                'patterns' => ['garantili', '%100 dogal ve etkili', 'kesin sonuc', 'yan etkisiz'],
                'message' => 'Sonuç garantisi ve "yan etkisiz" iddiası kullanılmamalı.'],
        ];
    }
}
