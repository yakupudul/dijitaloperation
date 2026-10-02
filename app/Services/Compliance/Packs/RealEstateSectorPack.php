<?php

namespace App\Services\Compliance\Packs;

use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPack;

/**
 * Emlak paketi (Faz 14). A DRAFT starter set of phrase checks, not a legal text: review with a lawyer and edit the
 * rules on screen (Ayarlar › Sektör paketleri). Patterns are matched on folded Turkish text.
 */
final class RealEstateSectorPack implements SectorPack
{
    public function id(): string
    {
        return 'real_estate';
    }

    public function label(): string
    {
        return 'Emlak';
    }

    public function description(): string
    {
        return 'Emlak ve gayrimenkul. Yanıltıcı reklam (Ticari Reklam ve Haksız Ticari Uygulamalar Yönetmeliği) için taslak kontroller.';
    }

    public function sectorCodes(): array
    {
        return ['real_estate', 'construction'];
    }

    public function defaultRules(): array
    {
        $all = ComplianceRuleKinds::TEXT_SOURCES;

        return [
            ['rule_key' => 'value_guarantee', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Değer artışı / kira garantisi', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['garantili kira', 'kira garantisi', 'kesin deger artisi', 'deger kazanmasi garanti', 'garantili yatirim', 'kesin kazanc'],
                'message' => 'Değer artışı veya kira getirisi garanti edilemez.'],
            ['rule_key' => 'superlatives', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Üstünlük iddiası', 'severity' => 'medium', 'applies_to' => $all,
                'patterns' => ['en iyi konum', 'en ucuz', 'en uygun fiyat', 'bir numara', 'turkiyenin en'],
                'message' => 'Kanıtlanamayan üstünlük iddiaları yanıltıcı reklam sayılabilir.'],
            ['rule_key' => 'urgency', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Sahte aciliyet', 'severity' => 'low', 'applies_to' => $all,
                'patterns' => ['son daire', 'son 1 daire', 'kacirmayin', 'son gun', 'sinirli sayida'],
                'message' => 'Doğru değilse aciliyet ifadeleri yanıltıcıdır; stok bilgisini doğrulayın.'],
        ];
    }
}
