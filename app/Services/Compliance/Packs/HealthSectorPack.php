<?php

namespace App\Services\Compliance\Packs;

use App\Services\Compliance\ComplianceRuleKinds;
use App\Services\Compliance\SectorPack;

/**
 * Sağlık paketi (health, dental, medical aesthetics). The rules are a DRAFT starter set based on the long-standing
 * principles of Turkish health promotion rules (no superlatives, guarantees, inducements, patient testimonials,
 * before/after or comparison; information, not promotion). They are NOT a legal text: the 12.11.2025 Official
 * Gazette (33075) change must be reviewed by a lawyer and the rules edited on screen accordingly.
 */
final class HealthSectorPack implements SectorPack
{
    public function id(): string
    {
        return 'health';
    }

    public function label(): string
    {
        return 'Sağlık';
    }

    public function description(): string
    {
        return 'Sağlık, diş ve medikal estetik markaları için tanıtım yasağı kontrolleri. Taslak kurallar — RG 12.11.2025 / 33075 değişikliği için hukuk görüşü alınıp ekrandan güncellenmeli.';
    }

    public function sectorCodes(): array
    {
        return ['healthcare', 'dental', 'medical_aesthetics'];
    }

    public function defaultRules(): array
    {
        $all = ComplianceRuleKinds::TEXT_SOURCES;

        return [
            ['rule_key' => 'superlatives', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Üstünlük iddiası', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['en iyi', 'en iyisi', 'en ucuz', 'en uygun fiyat', 'en guvenilir', 'en basarili', 'en deneyimli', 'bir numara', '1 numara', 'lider', 'tek adres', 'turkiyenin en', 'turkiye nin en', 'sektorun en'],
                'message' => 'Sağlık tanıtımında üstünlük / en iyi iddiası kullanılmamalı. Bilgilendirici, ölçülebilir ifade kullan.'],
            ['rule_key' => 'guarantees', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Sonuç garantisi', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['garanti', 'garantili', 'garantiyle', 'kesin sonuc', 'kesin cozum', '%100', '% 100', 'yuzde yuz', 'agrisiz', 'acisiz', 'risksiz', 'mucize', 'kalici cozum'],
                'message' => 'Tedavi sonucu garanti edilemez; "garantili, kesin sonuç, ağrısız, %100" gibi ifadeleri kaldır.'],
            ['rule_key' => 'inducements', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Kampanya / indirim / hediye', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['indirim', 'kampanya', 'ucretsiz muayene', 'ucretsiz kontrol', 'bedava', 'hediye', 'firsat', 'son gun', 'sinirli sayida', 'kupon'],
                'message' => 'Sağlık hizmetinde indirim, kampanya, hediye veya ücretsiz muayene ile talep yaratılmamalı.'],
            ['rule_key' => 'testimonials', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Hasta yorumu / teşekkür', 'severity' => 'medium', 'applies_to' => ['ai_draft', 'seo_brief', 'meta_ad', 'website'],
                'patterns' => ['hasta yorumlari', 'hastalarimizin yorumlari', 'memnun hastalarimiz', 'hastalarimizin gorusleri', 'hasta hikayeleri', 'tesekkur mektuplari'],
                'message' => 'Hasta yorumu, teşekkür veya hikâyeleri tanıtım amacıyla kullanılmamalı.'],
            ['rule_key' => 'before_after', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Öncesi / sonrası', 'severity' => 'high', 'applies_to' => $all,
                'patterns' => ['oncesi sonrasi', 'oncesi ve sonrasi', 'once ve sonra', 'before after'],
                'message' => 'Öncesi / sonrası görsel veya ifadesi kullanılmamalı.'],
            // Content we propose (SEO briefs, AI drafts) must not lead with price; live site/ad texts are not scanned for it.
            ['rule_key' => 'price_emphasis', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Fiyat vurgusu', 'severity' => 'medium', 'applies_to' => ['ai_draft', 'seo_brief'],
                'patterns' => ['fiyat', 'fiyatlari', 'fiyatlandirma', 'ucret', 'ucretleri', 'ne kadar tutar'],
                'message' => 'Sağlık hizmeti içeriği fiyat/ücret üzerine kurulmamalı; bilgilendirme (süreç, kimlere uygun, riskler) öne çıkmalı.'],
            ['rule_key' => 'comparison', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'Kıyaslama', 'severity' => 'medium', 'applies_to' => $all,
                'patterns' => ['diger kliniklerden', 'diger hastanelerden', 'rakiplerimizden', 'digerlerinden farkli olarak'],
                'message' => 'Başka kurum veya hekimlerle kıyaslama yapılmamalı.'],
            // ADR-076: localized (English) drafts follow the same principles. Only content we produce is scanned:
            // common words like "safe"/"fast" would flood live-site and ad audits with false positives.
            ['rule_key' => 'claims_en', 'kind' => ComplianceRuleKinds::FORBIDDEN, 'label' => 'İngilizce üstünlük / garanti / fiyat ifadesi', 'severity' => 'high', 'applies_to' => ['ai_draft', 'seo_brief'],
                'patterns' => ['best', 'the best', 'painless', 'pain free', 'pain-free', 'guarantee', 'guaranteed', 'guarantees', 'safe', 'safest', 'comfortable', 'fast', 'fastest',
                    'price', 'prices', 'pricing', 'cheap', 'cheapest', 'affordable', 'discount', 'free consultation', 'expert', 'experts', 'number one', 'no. 1', 'market leader',
                    'world class', 'world-class', '100%', 'miracle', 'risk free', 'risk-free', 'permanent solution', 'before and after', 'before & after'],
                'message' => 'İngilizce sağlık metninde de üstünlük, garanti, ağrısızlık/rahatlık/hız vaadi ve fiyat ifadesi kullanılmamalı (best, painless, guarantee, safe, comfortable, fast, price, expert…). Bilgilendirici ifade kullan.'],
            ['rule_key' => 'minors_targeting', 'kind' => ComplianceRuleKinds::TARGETING, 'label' => '18 yaş altı hedefleme', 'severity' => 'high', 'applies_to' => ['meta_targeting'],
                'patterns' => ['age_min<18'],
                'message' => 'Sağlık reklamları 18 yaş altını hedeflememeli; reklam setinde en düşük yaşı 18 yap.'],
            ['rule_key' => 'institution_identity', 'kind' => ComplianceRuleKinds::REQUIRED, 'label' => 'Kurum adı / ruhsat bilgisi', 'severity' => 'low', 'applies_to' => ['website'], 'active' => false,
                'patterns' => ['ruhsat'],
                'message' => 'Hukuk görüşüne göre sitede zorunlu kurum/ruhsat bilgisi varsa bu kuralı düzenleyip aç (varsayılan kapalı).'],
        ];
    }
}
