<?php

/*
 * SEO content pipeline Faz 3–4: topic map and İçerik Stüdyosu (article ideas → AI articles → WordPress drafts / WXR).
 */
return [
    'topic_map' => [
        'weekly_time' => '05:55', // after the query hub (05:30), before the SEO plan (06:30)
    ],

    'article' => [
        'min_words' => (int) env('MOXDOP_ARTICLE_MIN_WORDS', 850),
        'max_words' => (int) env('MOXDOP_ARTICLE_MAX_WORDS', 1100),
        'faq_questions' => 4,
        'internal_links_min' => 2,
        'internal_links_max' => 3,
        'meta_title_max' => 60,
        'meta_description_max' => 155,
        // Rough token counts for the cost estimate shown before a bulk run.
        'estimate_input_tokens' => 4500,
        'estimate_output_tokens' => 3200,
        'localize_input_tokens' => 5000,
        'localize_output_tokens' => 3200,
    ],

    'ideas' => [
        'max_per_request' => 60,
        'ai_batch_size' => 30,           // one AI call per batch of gap ideas, never per idea
        'existing_title_threshold' => 0.8, // at or above: the topic is already written, the idea is not proposed
        'similar_title_threshold' => 0.5,  // at or above: "benzer mevcut yazı" is shown next to the idea
        'location_ideas_per_run' => 6,
    ],

    'bulk' => [
        'max_articles' => 60,
    ],

    'schedule' => [
        'timezone' => 'Europe/Istanbul',
        'default_time' => '10:00',
        'default_per_day' => 1,
    ],

    /*
     * Closing informational note per sector pack (YMYL). Added to every article of a brand whose sector pack applies;
     * the writer is asked to keep it and it is appended when missing.
     */
    'disclaimers' => [
        'health' => 'Bu yazı genel bilgilendirme amaçlıdır; tanı ve tedavi için mutlaka hekiminize danışın.',
        'legal' => 'Bu yazı genel bilgilendirme amaçlıdır ve hukuki görüş yerine geçmez; somut durumunuz için bir avukata danışın.',
        'finance' => 'Bu yazı genel bilgilendirme amaçlıdır ve yatırım tavsiyesi değildir.',
        'food_supplement' => 'Bu yazı genel bilgilendirme amaçlıdır; gıda takviyeleri ilaç değildir, kullanmadan önce hekiminize danışın.',
    ],
];
