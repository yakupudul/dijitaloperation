<?php

namespace App\Services\Compliance;

/** Rule kinds and the content sources the auditor reads. */
final class ComplianceRuleKinds
{
    public const string FORBIDDEN = 'forbidden_phrase';

    public const string REQUIRED = 'required_phrase';

    public const string TARGETING = 'targeting';

    /** Sources with text: AI drafts (advisor), AI SEO briefs, live Meta ad text, website pages, Business Profile. */
    public const array TEXT_SOURCES = ['ai_draft', 'seo_brief', 'meta_ad', 'website', 'gbp'];

    public const array SOURCE_LABELS = [
        'ai_draft' => 'AI taslağı',
        'seo_brief' => 'SEO briefi',
        'meta_ad' => 'Meta reklamı',
        'meta_targeting' => 'Meta hedefleme',
        'website' => 'Web sitesi',
        'gbp' => 'İşletme Profili',
    ];
}
