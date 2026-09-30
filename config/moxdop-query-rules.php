<?php

/**
 * Sorgu kural motoru (QueryRuleEngine) — versioned rules, written from the operator's query exports (Sorgular › CSV
 * indir). Every word here is FOLDED (lowercase, Turkish letters → ASCII: "diş" → "dis", "ücret" → "ucret") and in its
 * base form: words are reduced to the shortest form that occurs in the query library before the rules apply
 * ("implantları" → "implant", "estetiği" → "estetik").
 *
 * Adım 1 · varyant: queries whose variant key is equal mean the same thing and show as one row ("+N varyant").
 * Adım 2 · konu / yön: facet words are sections of the same content (fiyat, nedir, nasıl…); what is left is the topic.
 *
 * Raise `version` on every change; "Kuralları uygula" (or the next import) recomputes every query.
 */
return [
    'version' => 1,

    'variant' => [
        // Dropped from every query: connectives, question particles.
        'drop' => ['ve', 'ile', 'icin', 'bir', 'mi', 'mu', 'midir', 'mudur', 'misin', 'da', 'de', 'ki', 'veya', 'yada',
            'the', 'and', 'of', 'for', 'a', 'an', 'to', 'in', 'on'],

        // Dropped when the whole word matches (years, bare numbers of 4 digits).
        'drop_patterns' => ['/^(19|20)\d{2}$/'],

        // Words that add nothing inside a service's queries, in every sector ("implant tedavisi" = "implant").
        'generic' => ['tedavi', 'uygulama', 'islem'],

        // Words a sector's queries imply (sector name folded => words): "diş implant" = "implant" in Diş sağlığı.
        'sector_implied' => [
            'dis sagligi' => ['dis'],
            'dis' => ['dis'],
            'sac ekimi' => ['sac'],
        ],

        // Same meaning, written differently: word (or phrase) => canonical word (or phrase). Applied before stemming.
        'synonyms' => [
            'ucret' => 'fiyat',
            'ucreti' => 'fiyat',
            'ucretleri' => 'fiyat',
            'fiat' => 'fiyat',
            'fiyati' => 'fiyat',
            'burnu' => 'burun',
            'agzi' => 'agiz',
            'all on four' => 'all on 4',
        ],

        // Frequent misspellings: word => correct word.
        'typos' => [
            'implamt' => 'implant',
            'impilant' => 'implant',
            'inplant' => 'implant',
            'implat' => 'implant',
            'zirkonyun' => 'zirkonyum',
            'zirkonya' => 'zirkonyum',
            'botox' => 'botoks',
        ],

        // Words never shortened by the library-based stemming ("dolgun" ≠ "dolgu").
        'no_stem' => ['dolgun', 'kasik'],
    ],

    'topic' => [
        // Facets: sections of the same content. facet => phrases (longest first wins; a word is in one facet).
        'facets' => [
            'fiyat' => ['fiyat', 'kac para', 'ne kadar', 'maliyet', 'para', 'tl', 'taksit', 'kampanya', 'indirim', 'ucuz', 'uygun fiyat'],
            'nedir' => ['nedir', 'ne demek', 'nelerdir', 'anlam', 'hakkinda', 'bilgi'],
            'nasil' => ['nasil yapilir', 'nasil', 'asama', 'yapilis', 'yapilir'],
            'yorum' => ['yorum', 'deneyim', 'tavsiye', 'oneri', 'sikayet'],
            'en_iyi' => ['en iyi', 'en uygun', 'en ucuz', 'iyi', 'kaliteli', 'uzman'],
            'sure' => ['ne kadar surer', 'kac gun', 'kac saat', 'kac seans', 'sure', 'surer'],
            'garanti' => ['garanti'],
            'avantaj' => ['avantaj', 'dezavantaj', 'fayda', 'arti', 'eksi'],
            'randevu' => ['randevu', 'iletisim', 'telefon', 'adres', 'muayene'],
        ],

        // Left-overs dropped from the topic once the facets are taken out.
        'drop' => ['en', 'cok', 'daha', 'gibi', 'kadar', 'hangi', 'ne'],
    ],
];
