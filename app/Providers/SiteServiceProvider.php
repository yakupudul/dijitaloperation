<?php

namespace App\Providers;

use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Faz 4a Web sitesi ekranı (SEO çekirdeği): AI routes of the website screen operations. Prompts live in
 * config/moxdop-prompts.php (PromptRegistry); every output is validated against the data pack before it is stored.
 */
final class SiteServiceProvider extends ServiceProvider
{
    /** @var array<string, array{0: string, 1: string, 2: string}> key => [name, description, steps] */
    private const array ROUTES = [
        AiRouteKeys::SITE_PAGE_CATEGORIES => ['Site Page Categories', 'Web sitesi: one batched call categorizes the pages the rules could not place (hizmet / blog / kurumsal / sss / lokasyon / diğer).', 'classification'],
        AiRouteKeys::SITE_SERVICE_PAGES => ['Site Service Pages', 'Web sitesi AI adım 1: hizmet / lokasyon pages the name rules could not match → one approved brand service or none.', 'classification'],
        AiRouteKeys::SITE_IMAGE_ALTS => ['Site Image Alts', 'Görsel alt metni: alt text for WordPress images without one, from the file name and the page; Admin approval before WordPress.', 'classification'],
        AiRouteKeys::SITE_CLUSTER_PAGES => ['Site Cluster Pages', 'Web sitesi AI adım 2: coverage / intent judgement of the ambiguous cluster ↔ page mappings, one call per service.', 'classification'],
        AiRouteKeys::SITE_CLUSTER_MATCH => ['Site Cluster Match', 'Küme ↔ içerik: which page answers each cluster of one service, read from page titles, headings and text (candidates chosen by word overlap).', 'classification'],
        AiRouteKeys::SITE_CLUSTER_GAPS => ['Site Cluster Gaps', 'Küme eksikleri: what one page does not answer of its clusters (queries, facets, AI questions, service areas when the cluster needs them).', 'classification'],
        AiRouteKeys::QUERIES_AI_QUERIES => ['Query AI Questions', 'AI sorguları: 4–8 questions people ask AI assistants per cluster of one service ("{bölge}" placeholder for local ones).', 'classification'],
        AiRouteKeys::SITE_PAGE_SUMMARY => ['Site Page Summaries', 'Marka hafızası: 2–4 sentence summary + key facts of pages used in analysis (batched).', 'classification'],
        AiRouteKeys::SITE_URL_ANALYSIS => ['Site URL Analysis', 'URL analizi: suggestions for one URL from its data pack; URLs / numbers / quotes are checked against the pack.', 'analysis'],
        AiRouteKeys::SITE_SEO_FIELDS_BATCH => ['Site SEO Fields Batch', 'Onarım masası toplu hazırlık: SEO titles and meta descriptions of up to 15 pages of one site per call; checked by rules, Admin approval before WordPress.', 'classification'],
        AiRouteKeys::SITE_APPLY_CHANGE => ['Site Apply Change', '"AI ile yap": the new version of the fields / HTML a suggestion changes; compliance gate before display, Admin approval before WordPress.', 'analysis'],
        AiRouteKeys::SITE_STANDARD_FROM_DECISION => ['Site Standard From Decision', '"Bu karardan standart öner": a scoped standard (URL / marka / sektör / genel) from an approved decision; operator edits and approves.', 'classification'],
        AiRouteKeys::SITE_WEEKLY_CONTENT => ['Site Weekly Content', 'İçerik: weekly content plan (new pages / posts, updates) within the brand capacity.', 'analysis'],
        AiRouteKeys::SITE_CONTENT_DISCOVERY => ['Site Content Discovery', 'İçerik: opportunities from brand queries outside every cluster.', 'analysis'],
        AiRouteKeys::COMPLIANCE_FORBIDDEN_TERMS => ['Forbidden Terms', 'Sorgular › Yasaklı ifadeler "AI ile öner": candidate phrases content of one sector must not use (claims, guarantees, superlatives); the operator approves each.', 'classification'],
        AiRouteKeys::SITE_CONTENT_RECIPE => ['Site Content Recipe', 'İçerik fikirleri "SEO analizi": ordered, concrete steps (technical first) for one idea of the site from its page, gaps, Search Console score and site pages; numbers checked against the pack.', 'analysis'],
        AiRouteKeys::CONTENT_IDEAS => ['Content Ideas', '"Yeni fikir üret": extra content ideas of one cluster that each need their own page (system-wide pool; brand context only from a brand screen).', 'analysis'],
        AiRouteKeys::SITE_WRITE_ARTICLE => ['Site Write Article', 'İçerik "Taslak hazırla": full article HTML; compliance gate and Admin approval before the WordPress draft.', 'analysis'],
    ];

    public function boot(): void
    {
        $registry = $this->app->make(AiRouteRegistry::class);
        foreach (self::ROUTES as $key => [$name, $description, $steps]) {
            $registry->register([
                'key' => $key,
                'name' => $name,
                'module' => 'site',
                'description' => $description,
                'default_steps' => $steps === 'analysis' ? AiDefaultSteps::analysis() : AiDefaultSteps::classification(),
            ]);
        }
    }
}
