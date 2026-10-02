<?php

namespace App\Enums\IntelligenceCore;

enum SearchTermKind: string
{
    case GscQuery = 'gsc_query';
    case GoogleAdsSearchTerm = 'google_ads_search_term';
    case GoogleAdsKeyword = 'google_ads_keyword';
    case DataForSeoKeyword = 'dataforseo_keyword';
    case GbpSearchKeyword = 'gbp_search_keyword';
    case MoxdopTopic = 'moxdop_topic';
    case AiSearchQuery = 'ai_search_query';
}
