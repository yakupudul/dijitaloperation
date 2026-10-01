<?php

namespace App\Providers;

use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Business Profile AI routes (review reply, Faz 7 services compare, description, post from page) and the
 * Faz 5 Google Ads and Faz 6 Meta AI routes. Rule engines and system checks run without AI.
 */
final class AdvisorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::BRAND_CARE,
            'name' => 'Brand Care Agent',
            'module' => 'advisor',
            'description' => 'Marka bakım ajanı: weekly, active brands only, runs only when the Marka dosyası changed. Reads the dossier and the changed sections, proposes at most 5 tasks into the work list and asks for missing facts. Nothing is written outside MoxDOP.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::BRAND_CHIEF,
            'name' => 'Chief Weekly Plan',
            'module' => 'advisor',
            'description' => 'Şef: every Monday, one plan across the active brands (at most 10 lines) from the brand care notes, goals and open work counts. Read-only.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GBP_REVIEW_REPLY,
            'name' => 'Google Review Reply Draft',
            'module' => 'advisor',
            'description' => 'On operator click, drafts the owner\'s reply to one Google review of the brand (reviewer name not sent, sector compliance rules applied). Copy-paste only; nothing is posted.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GBP_SERVICES_COMPARE,
            'name' => 'Business Profile Services Compare',
            'module' => 'gbp',
            'description' => 'İşletme Profili "Hizmetleri karşılaştır": the brand\'s approved services vs the profile\'s categories and services list → missing services (exact names) and category notes. Suggestions only; the operator changes the profile on Google.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GBP_DESCRIPTION,
            'name' => 'Business Profile Description',
            'module' => 'gbp',
            'description' => 'İşletme Profili "Açıklama öner": a proposed profile description (≤ 750 characters) from brand memory, services and areas, sector compliance checked. Copy only; nothing is written to Google.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GBP_POST_FROM_PAGE,
            'name' => 'Business Profile Post From Page',
            'module' => 'gbp',
            'description' => 'İşletme Profili "Siteden paylaş": one post (≤ 1500 characters) from one page of the brand\'s site with a link to that page, sector compliance checked. Publishing needs Admin approval (ADR-073).',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::META_CREATIVES,
            'name' => 'Meta Creatives',
            'module' => 'meta',
            'description' => 'Meta "Kreatif öner": per main service ad texts, headlines, video hooks and test variants from the account\'s own creatives and results, sector compliance checked. Copy / CSV only; nothing is written to Meta.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::META_STRUCTURE,
            'name' => 'Meta Campaign Structure',
            'module' => 'meta',
            'description' => 'Meta "Kampanya yapısı öner": campaign / ad set structure and remarketing proposals from campaigns, ad sets, services, areas and lead marks. Instructions only; nothing is written to Meta.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::META_LANDING,
            'name' => 'Meta Form / Landing',
            'module' => 'meta',
            'description' => 'Meta "Form / açılış sayfası öner": improvements for the lead forms and landing pages of the ads from page content, GA4 and lead marks. Instructions only; nothing is written.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        foreach ([
            [AiRouteKeys::GOOGLE_ADS_SEARCH_TERMS, 'Google Ads Search Terms Review', 'Google Ads "Arama terimlerini incele": intent and service fit per search term, negatives with match type, scope and the useful queries each could block. Shared-list negatives go to Google after Admin approval (ADR-064); the rest to the Editor file.'],
            [AiRouteKeys::GOOGLE_ADS_STRUCTURE, 'Google Ads Structure & Budget', 'Google Ads "Kampanya yapısı öner": campaign / ad group structure and daily budget split by the main services, plus an experiment plan. Draft + approval → Google Ads Editor file; nothing is written to Google.'],
            [AiRouteKeys::GOOGLE_ADS_AD_TEXTS, 'Google Ads Ad Texts', 'Google Ads "Reklam metni yaz": one responsive search ad (headlines ≤ 30, descriptions ≤ 90 characters) and its landing page for one ad group, sector compliance checked. Draft + approval → Google Ads Editor file.'],
        ] as [$key, $name, $description]) {
            $this->app->make(AiRouteRegistry::class)->register([
                'key' => $key, 'name' => $name, 'module' => 'google_ads', 'description' => $description,
                'default_steps' => AiDefaultSteps::analysis(),
            ]);
        }
    }
}
