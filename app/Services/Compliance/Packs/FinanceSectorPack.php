<?php

namespace App\Services\Compliance\Packs;

use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPack;

/**
 * Finans paketi (Faz 14). A DRAFT starter set of phrase checks, not a legal text: review with a lawyer and edit the
 * rules on screen (Ayarlar › Sektör paketleri). Patterns are matched on folded Turkish text.
 */
final class FinanceSectorPack implements SectorPack
{
    public function id(): string
    {
        return 'finance';
    }

    public function label(): string
    {
        return 'Finans';
    }

    public function description(): string
    {
        return 'Finans, yatırım ve kredi hizmetleri. SPK / BDDK reklam ilkeleri için taslak kontroller — hukuk görüşüyle ekrandan düzenlenmeli.';
    }

    public function sectorCodes(): array
    {
        return ['finance'];
    }

    public function defaultRules(): array
    {
        $all = ComplianceRuleKinds::TEXT_SOURCES;

        return [
            ['rule_key' => 'guaranteed_return', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Getiri garantisi', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['garantili getiri', 'garantili kazanc', 'kesin kazanc', 'risksiz', 'risk yok', 'kayip yok', 'sabit kazanc garantisi'],
                'message' => 'Yatırım getirisi garanti edilemez; risk bilgisi olmadan kazanç vaadi kullanılmamalı.'],
            ['rule_key' => 'unrealistic_claims', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Abartılı kazanç iddiası', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['zengin ol', 'hizli zengin', 'kisa surede kat', 'aylik %', 'gunluk kazanc', 'pasif gelir garantisi'],
                'message' => 'Gerçekçi olmayan kazanç iddiaları yanıltıcı reklam sayılabilir.'],
            ['rule_key' => 'missing_risk_note', 'kind' => ComplianceRuleKinds::REQUIRED, 'label' => 'Risk uyarısı', 'severity' => 'medium', 'applies_to' => ['website'], 'active' => false,
                'patterns' => ['risk uyarisi'],
                'message' => 'Yatırım içeriklerinde risk uyarısı bulunmalı (varsayılan kapalı, hukuk görüşüyle açın).'],
        ];
    }
}
