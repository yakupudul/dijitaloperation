<?php

namespace App\Support\Demo;

use App\Services\Playbooks\PlaybookReadService;

/**
 * Deterministic Client Value Story, Reports, and Decision presentation (Milestone 4).
 *
 * Assembled from existing Findings / Opportunities / Recommendations / Work /
 * Operational Outcomes / Business Outcomes / Decisions — no production tables.
 *
 * Future persistence (not implemented):
 * - ReportConfiguration: brand_id, period, language, section toggles, operator note
 * - ValueStorySnapshot: optional cached narrative for a period (still derived from evidence)
 * - DecisionRecord: when decisions need first-class storage beyond Activity + Recommendation state
 * - KnowledgeArticle: deferred — Playbooks + Brand Context + Files remain the knowledge model
 */
final class ClientValueFixtures
{
    /**
     * @return list<string>
     */
    public static function valueSections(): array
    {
        return ['overview', 'story', 'outcomes', 'decisions', 'reports'];
    }

    /**
     * @return list<string>
     */
    public static function reportSectionKeys(): array
    {
        return [
            'executive_summary',
            'observations',
            'completed_work',
            'operational_outcomes',
            'business_outcomes',
            'opportunities',
            'next_actions',
            'supporting_metrics',
        ];
    }

    /**
     * Compact Brand Value overview numbers — reconcile with Demo fixtures.
     *
     * @return array<string, mixed>
     */
    public static function valueSummary(string $period = 'last_28'): array
    {
        $story = self::valueStory($period);

        return [
            'period' => $period,
            'period_label' => $story['period_label'],
            'observed' => count($story['observations']),
            'decided' => count($story['decisions']),
            'delivered' => count($story['completed_work']),
            'operational_outcomes' => count($story['operational_changes']),
            'business' => $story['business_outcomes'],
            'open_opportunities' => count($story['opportunities']),
            'next' => count($story['next_actions']),
        ];
    }

    /**
     * Deterministic Client Value Story for Atlas Dental (primary demo brand).
     *
     * @return array<string, mixed>
     */
    public static function valueStory(string $period = 'last_28', string $reportLocale = 'en'): array
    {
        $outcomes = DemoState::businessOutcomes($period);
        $periodLabel = (string) ($outcomes['period_label'] ?? DemoCatalog::periodFactors($period)['label'] ?? $period);
        $locale = in_array($reportLocale, ['en', 'tr'], true) ? $reportLocale : 'en';

        $observations = [
            [
                'id' => 'obs-implant-demand',
                'text' => self::t($locale, 'Search demand for implant treatments remained strong.', 'İmplant tedavilerine yönelik arama talebi güçlü kaldı.'),
                'source_type' => 'opportunity',
                'source_label' => 'Opportunity',
                'source_url' => route('demo.opportunities', ['view' => 'open']),
            ],
            [
                'id' => 'obs-organic-gap',
                'text' => self::t($locale, 'Organic visibility for the same implant demand remained limited.', 'Aynı implant talebinde organik görünürlük sınırlı kaldı.'),
                'source_type' => 'opportunity',
                'source_label' => 'Opportunity',
                'source_url' => route('demo.opportunities', ['view' => 'open']),
            ],
            [
                'id' => 'obs-measurement',
                'text' => self::t($locale, 'Lead measurement contained one mapping gap that limited reviewability.', 'Lead ölçümünde incelemeyi kısıtlayan bir eşleme boşluğu vardı.'),
                'source_type' => 'finding',
                'source_label' => 'Finding',
                'source_url' => route('demo.findings'),
            ],
            [
                'id' => 'obs-meta-angle',
                'text' => self::t($locale, 'Meta creative delivery became concentrated around one angle.', 'Meta kreatif dağılımı tek bir açı etrafında yoğunlaştı.'),
                'source_type' => 'finding',
                'source_label' => 'Finding',
                'source_url' => route('demo.findings'),
            ],
            [
                'id' => 'obs-review',
                'text' => self::t($locale, 'Weekly Google Ads review flagged conversion mapping for follow-up.', 'Haftalık Google Ads kontrolü dönüşüm eşlemesini takip için işaretledi.'),
                'source_type' => 'recurring_review',
                'source_label' => 'Recurring Review',
                'source_url' => route('demo.work.show', ['workId' => 'rr-gads-aug13', 'type' => 'recurring_review']),
            ],
        ];

        $decisions = self::meaningfulDecisions($locale);
        $completedWork = [
            [
                'id' => 'cw-gads-review',
                'text' => self::t($locale, 'Completed weekly Google Ads review.', 'Haftalık Google Ads kontrolü tamamlandı.'),
                'source_url' => route('demo.tasks'),
            ],
            [
                'id' => 'cw-mapping',
                'text' => self::t($locale, 'Corrected conversion mapping for lead measurement.', 'Lead ölçümü için dönüşüm eşlemesi düzeltildi.'),
                'source_url' => route('demo.task', ['taskId' => 't-investigate-lead-measurement']),
            ],
            [
                'id' => 'cw-implant-content',
                'text' => self::t($locale, 'Updated implant landing-page content.', 'İmplant açılış sayfası içeriği güncellendi.'),
                'source_url' => route('demo.website'),
            ],
            [
                'id' => 'cw-search-terms',
                'text' => self::t($locale, 'Reviewed search terms for waste and intent mismatch.', 'Arama terimleri israf ve niyet uyumsuzluğu için incelendi.'),
                'source_url' => route('demo.google-ads.overview'),
            ],
            [
                'id' => 'cw-meta-brief',
                'text' => self::t($locale, 'Prepared new Meta creative brief for secondary angle.', 'İkincil açı için yeni Meta kreatif brifi hazırlandı.'),
                'source_url' => route('demo.meta.overview'),
            ],
            [
                'id' => 'cw-doctor-title',
                'text' => self::t($locale, 'Completed client request: doctor title on homepage.', 'Müşteri talebi tamamlandı: ana sayfada doktor unvanı.'),
                'source_url' => route('demo.work.show', ['workId' => 'req-doctor-title', 'type' => 'client_request']),
            ],
            [
                'id' => 'cw-creative-qa',
                'text' => self::t($locale, 'QA-approved Meta creative replacement.', 'Meta kreatif değişimi kalite kontrolünden geçti.'),
                'source_url' => route('demo.work.show', ['workId' => 'appr-qa-creative', 'type' => 'approval']),
            ],
        ];

        $operationalChanges = collect(BusinessOutcomeFixtures::operationalOutcomes())
            ->map(function (array $row, int $i) use ($locale): array {
                $label = (string) ($row['label'] ?? '');
                $detail = (string) ($row['detail'] ?? '');

                return [
                    'id' => 'op-'.$i,
                    'text' => $label,
                    'note' => self::t(
                        $locale,
                        'Observed after related work — causation is not established. '.$detail,
                        'İlgili çalışmadan sonra gözlemlendi — nedensellik kanıtlanmamıştır. '.$detail
                    ),
                    'source_url' => route('demo.brand', ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'value', 'value' => 'outcomes']),
                ];
            })
            ->values()
            ->all();

        $opportunities = collect(DemoState::opportunitiesWithStatus())
            ->filter(fn (array $row): bool => ($row['brand_id'] ?? '') === DemoCatalog::BRAND_ID)
            ->filter(fn (array $row): bool => in_array(($row['status'] ?? 'open'), ['open', 'in_review'], true))
            ->pipe(fn ($c) => collect(OpportunityFixtures::sortByBusinessRelevance($c->values()->all())))
            ->take(4)
            ->map(function (array $row) use ($locale): array {
                return [
                    'id' => $row['id'] ?? '',
                    'title' => $row['title'] ?? '',
                    'goal' => $row['goal_title'] ?? self::t($locale, 'Primary growth goal', 'Birincil büyüme hedefi'),
                    'service' => $row['service_label'] ?? '',
                    'source_url' => route('demo.opportunities', ['view' => 'open']),
                ];
            })
            ->values()
            ->all();

        $nextActions = [
            [
                'id' => 'next-content',
                'text' => self::t($locale, 'Complete implant content expansion (accepted recommendation).', 'İmplant içerik genişlemesini tamamla (kabul edilen öneri).'),
                'source_url' => route('demo.recommendations'),
            ],
            [
                'id' => 'next-seo-review',
                'text' => self::t($locale, 'Run monthly SEO coverage review.', 'Aylık SEO kapsam kontrolünü çalıştır.'),
                'source_url' => route('demo.work.show', ['workId' => 'rr-seo-aug14', 'type' => 'recurring_review']),
            ],
            [
                'id' => 'next-meta-angle',
                'text' => self::t($locale, 'Test secondary Meta creative angle after new assets arrive.', 'Yeni varlıklar gelince ikincil Meta kreatif açısını test et.'),
                'source_url' => route('demo.tasks'),
            ],
        ];

        $businessAvailable = is_array($outcomes) && isset($outcomes['qualified_leads']);

        return [
            'brand_id' => DemoCatalog::BRAND_ID,
            'brand_name' => 'Atlas Dental Ankara',
            'customer_name' => 'Atlas Group',
            'period' => $period,
            'period_label' => $periodLabel,
            'locale' => $locale,
            'observations' => $observations,
            'decisions' => $decisions,
            'completed_work' => $completedWork,
            'operational_changes' => $operationalChanges,
            'business_outcomes' => $businessAvailable ? [
                'available' => true,
                'platform_leads' => $outcomes['platform_leads'] ?? null,
                'qualified_leads' => $outcomes['qualified_leads'] ?? null,
                'consultations' => $outcomes['consultations'] ?? null,
                'patients' => $outcomes['patients'] ?? null,
                'qualified_rate' => $outcomes['qualified_rate'] ?? null,
                'revenue_display' => $outcomes['revenue_display'] ?? __('operator.outcomes.not_available'),
                'provenance' => $outcomes['provenance'] ?? 'Demo',
                'note' => $outcomes['note'] ?? '',
                'unavailable_message' => null,
            ] : [
                'available' => false,
                'unavailable_message' => self::t(
                    $locale,
                    'Business outcome data unavailable for this period.',
                    'Bu dönem için ticari sonuç verisi mevcut değil.'
                ),
            ],
            'opportunities' => $opportunities,
            'next_actions' => $nextActions,
            'ai_assisted' => false,
            'causation_disclaimer' => self::t(
                $locale,
                'Observed after related work — causation is not established.',
                'İlgili çalışmadan sonra gözlemlendi — nedensellik kanıtlanmamıştır.'
            ),
        ];
    }

    /**
     * Meaningful decisions only (not Activity noise).
     *
     * @return list<array<string, mixed>>
     */
    public static function meaningfulDecisions(string $locale = 'en'): array
    {
        $base = [
            [
                'id' => 'dec-expand-organic',
                'title' => self::t($locale, 'Expand implant organic content coverage', 'İmplant organik içerik kapsamını genişlet'),
                'status' => self::t($locale, 'Accepted', 'Kabul edildi'),
                'why' => self::t($locale, 'Supports primary Goal and active SEO Service Scope. High paid demand + weak organic coverage.', 'Birincil hedefi ve aktif SEO hizmet kapsamını destekler. Yüksek ücretli talep + zayıf organik kapsam.'),
                'by' => 'Yakup',
                'date' => '13 Aug 2026',
                'source' => self::t($locale, 'Opportunity', 'Fırsat'),
                'source_url' => route('demo.opportunities'),
            ],
            [
                'id' => 'dec-keep-gads-structure',
                'title' => self::t($locale, 'Keep current Google Ads campaign structure', 'Mevcut Google Ads kampanya yapısını koru'),
                'status' => self::t($locale, 'Accepted', 'Kabul edildi'),
                'why' => self::t($locale, 'Price-intent keywords remain in a separate campaign.', 'Fiyat niyetli anahtar kelimeler ayrı kampanyada kalır.'),
                'by' => 'Ayşe Demir',
                'date' => '12 Aug 2026',
                'source' => self::t($locale, 'Recommendation', 'Öneri'),
                'source_url' => route('demo.recommendations'),
            ],
            [
                'id' => 'dec-defer-meta',
                'title' => self::t($locale, 'Defer secondary Meta creative angle', 'İkincil Meta kreatif açısını ertele'),
                'status' => self::t($locale, 'Deferred', 'Ertelendi'),
                'why' => self::t($locale, 'Waiting for new creative assets.', 'Yeni kreatif varlıklar bekleniyor.'),
                'by' => 'Can Öztürk',
                'date' => '12 Aug 2026',
                'source' => self::t($locale, 'Opportunity', 'Fırsat'),
                'source_url' => route('demo.opportunities'),
            ],
            [
                'id' => 'dec-accept-creative',
                'title' => self::t($locale, 'Replace underperforming Meta creative PB-Video-03', 'Düşük performanslı Meta kreatif PB-Video-03’ü değiştir'),
                'status' => self::t($locale, 'Accepted', 'Kabul edildi'),
                'why' => self::t($locale, 'CPL deterioration on priority offering.', 'Öncelikli teklifte CPL bozulması.'),
                'by' => 'Ayşe Demir',
                'date' => '12 Aug 2026',
                'source' => self::t($locale, 'Recommendation', 'Öneri'),
                'source_url' => route('demo.recommendations'),
            ],
        ];

        $captured = DemoState::captureDecisions();
        foreach ($captured as $note) {
            if (($note['kind'] ?? '') !== 'decision') {
                continue;
            }
            $base[] = [
                'id' => $note['id'] ?? ('cap-'.count($base)),
                'title' => $note['title'] ?? 'Decision',
                'status' => self::t($locale, 'Recorded', 'Kaydedildi'),
                'why' => $note['body'] ?? '',
                'by' => 'Demo Operator',
                'date' => $note['captured_at'] ?? '',
                'source' => self::t($locale, 'Capture', 'Hızlı kayıt'),
                'source_url' => route('demo.brand', ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'value', 'value' => 'decisions']),
            ];
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function reportPreview(array $config = []): array
    {
        $period = (string) ($config['period'] ?? 'last_28');
        $locale = (string) ($config['language'] ?? 'en');
        $sections = $config['sections'] ?? array_fill_keys(self::reportSectionKeys(), true);
        if (! is_array($sections)) {
            $sections = array_fill_keys(self::reportSectionKeys(), true);
        }
        foreach (self::reportSectionKeys() as $key) {
            if (! array_key_exists($key, $sections)) {
                $sections[$key] = true;
            }
            $sections[$key] = (bool) $sections[$key];
        }

        $story = self::valueStory($period, $locale);
        $operatorNote = trim((string) ($config['operator_note'] ?? ''));
        $tone = ($config['tone'] ?? 'client') === 'internal' ? 'internal' : 'client';

        $executive = [
            self::t($locale, 'Implant demand remained the Brand’s strongest growth theme.', 'İmplant talebi Markanın en güçlü büyüme teması olmayı sürdürdü.'),
            self::t($locale, 'The agency completed seven operational work items in the selected period.', 'Ajans seçilen dönemde yedi operasyonel iş kalemini tamamladı.'),
            self::t($locale, 'Lead measurement reliability improved after mapping correction.', 'Eşleme düzeltmesinden sonra lead ölçüm güvenilirliği iyileşti.'),
            self::t(
                $locale,
                sprintf(
                    '%s qualified leads and %s consultations were recorded (operator-maintained Demo data).',
                    (string) ($story['business_outcomes']['qualified_leads'] ?? '—'),
                    (string) ($story['business_outcomes']['consultations'] ?? '—')
                ),
                sprintf(
                    '%s nitelikli lead ve %s konsültasyon kaydedildi (operatör Demo verisi).',
                    (string) ($story['business_outcomes']['qualified_leads'] ?? '—'),
                    (string) ($story['business_outcomes']['consultations'] ?? '—')
                )
            ),
            self::t($locale, 'Organic demand coverage remains the main open growth opportunity.', 'Organik talep kapsamı ana açık büyüme fırsatı olmayı sürdürüyor.'),
        ];

        return [
            'demo_label' => self::t($locale, 'Demo Report Preview', 'Demo Rapor Önizleme'),
            'brand_name' => $story['brand_name'],
            'customer_name' => $story['customer_name'],
            'period' => $period,
            'period_label' => $story['period_label'],
            'language' => $locale,
            'tone' => $tone,
            'sections' => $sections,
            'operator_note' => $operatorNote,
            'executive_summary' => $executive,
            'story' => $story,
            'supporting_metrics' => self::supportingMetrics($period, $locale),
            'future_delivery_note' => self::t(
                $locale,
                'PDF, email, and public share links are future capabilities — not available in this Demo.',
                'PDF, e-posta ve genel paylaşım bağlantıları gelecek yeteneklerdir — bu Demoda yok.'
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function supportingMetrics(string $period, string $locale = 'en'): array
    {
        $f = DemoCatalog::periodFactors($period);
        $factor = (float) ($f['results_factor'] ?? 1.0);

        return [
            [
                'channel' => 'Google Ads',
                'provenance' => self::t($locale, 'Google Ads measured · Demo', 'Google Ads ölçümü · Demo'),
                'metrics' => [
                    ['label' => self::t($locale, 'Spend', 'Harcama'), 'value' => '₺'.number_format((int) round(48500 * $factor))],
                    ['label' => self::t($locale, 'Conversions', 'Dönüşüm'), 'value' => (string) (int) round(64 * $factor)],
                ],
            ],
            [
                'channel' => 'Meta Ads',
                'provenance' => self::t($locale, 'Meta measured · Demo', 'Meta ölçümü · Demo'),
                'metrics' => [
                    ['label' => self::t($locale, 'Results', 'Sonuç'), 'value' => (string) (int) round(89 * $factor)],
                    ['label' => 'CPL', 'value' => '₺'.number_format((int) round(210 / max(0.85, $factor)))],
                ],
            ],
            [
                'channel' => 'GA4',
                'provenance' => self::t($locale, 'GA4 measured · Demo', 'GA4 ölçümü · Demo'),
                'metrics' => [
                    ['label' => self::t($locale, 'Business Actions', 'İş Eylemleri'), 'value' => (string) (int) round(142 * $factor)],
                ],
            ],
            [
                'channel' => 'Search Console',
                'provenance' => self::t($locale, 'GSC measured · Demo', 'GSC ölçümü · Demo'),
                'metrics' => [
                    ['label' => self::t($locale, 'Clicks', 'Tıklama'), 'value' => (string) (int) round(1820 * $factor)],
                    ['label' => self::t($locale, 'Impressions', 'Gösterim'), 'value' => (string) (int) round(48200 * $factor)],
                ],
            ],
            [
                'channel' => 'GBP',
                'provenance' => self::t($locale, 'GBP measured · Demo', 'GBP ölçümü · Demo'),
                'metrics' => [
                    ['label' => self::t($locale, 'Customer actions', 'Müşteri eylemleri'), 'value' => (string) (int) round(312 * $factor)],
                ],
            ],
        ];
    }

    /**
     * Customer-level report cards — no incompatible metric summing.
     *
     * @return array<string, mixed>
     */
    public static function customerReports(string $customerId): array
    {
        $brands = collect(DemoState::all()['brands'] ?? [])
            ->filter(fn (array $b): bool => ($b['customer_id'] ?? '') === $customerId)
            ->values()
            ->all();

        if ($brands === []) {
            $brands = [[
                'id' => DemoCatalog::BRAND_ID,
                'name' => 'Atlas Dental Ankara',
                'customer_id' => DemoCatalog::CUSTOMER_ID,
            ]];
        }

        $cards = [];
        foreach ($brands as $brand) {
            $isPrimary = ($brand['id'] ?? '') === DemoCatalog::BRAND_ID;
            $summary = $isPrimary
                ? self::valueSummary('this_month')
                : [
                    'delivered' => 4,
                    'operational_outcomes' => 1,
                    'open_opportunities' => 3,
                    'business' => ['available' => false],
                ];

            $cards[] = [
                'brand_id' => $brand['id'] ?? '',
                'brand_name' => $brand['name'] ?? 'Brand',
                'completed_work' => (int) ($summary['delivered'] ?? 0),
                'improvements' => (int) ($summary['operational_outcomes'] ?? 0),
                'opportunities' => (int) ($summary['open_opportunities'] ?? 0),
                'report_url' => route('demo.brand', [
                    'brand' => $brand['id'] ?? DemoCatalog::BRAND_ID,
                    'tab' => 'value',
                    'value' => 'reports',
                ]),
                'value_url' => route('demo.brand', [
                    'brand' => $brand['id'] ?? DemoCatalog::BRAND_ID,
                    'tab' => 'value',
                ]),
            ];
        }

        return [
            'customer_id' => $customerId,
            'single_brand' => count($cards) === 1,
            'brands' => $cards,
            'aggregation_note' => __('operator.value.customer_no_blind_aggregation'),
        ];
    }

    /**
     * Dashboard compact Recent Value signal.
     *
     * @return list<array<string, mixed>>
     */
    public static function recentValue(): array
    {
        $summary = self::valueSummary('this_month');
        $business = $summary['business'];

        return [
            [
                'brand' => 'Atlas Dental Ankara',
                'line' => sprintf(
                    '%d tasks completed this month · %d improvements observed · %s qualified leads recorded',
                    $summary['delivered'],
                    $summary['operational_outcomes'],
                    $business['available'] ? (string) ($business['qualified_leads'] ?? '—') : '—'
                ),
                'url' => route('demo.brand', ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'value']),
            ],
        ];
    }

    /**
     * Contextual knowledge for Work / Task detail.
     *
     * @return array<string, mixed>
     */
    public static function workKnowledgeContext(?string $playbookId = null): array
    {
        $playbook = null;
        if (is_string($playbookId) && $playbookId !== '') {
            $playbook = app(PlaybookReadService::class)->findPresentation($playbookId);
        }

        $key = is_array($playbook) ? ($playbook['stable_key'] ?? $playbook['id'] ?? null) : null;

        return [
            'service' => is_array($playbook) ? ($playbook['service_label'] ?? null) : null,
            'goal' => null,
            'playbook' => [
                'id' => $key,
                'name' => is_array($playbook) ? ($playbook['name'] ?? null) : null,
                'url' => $key !== null
                    ? route('demo.settings.playbook', ['playbookId' => $key])
                    : null,
            ],
            'decision' => null,
            'references' => is_array($playbook) ? ($playbook['references'] ?? []) : [],
            'qa_guidance' => is_array($playbook) ? ($playbook['qa_guidance'] ?? []) : [],
            'checklist' => is_array($playbook) ? ($playbook['checklist'] ?? []) : [],
        ];
    }

    private static function t(string $locale, string $en, string $tr): string
    {
        return $locale === 'tr' ? $tr : $en;
    }
}
