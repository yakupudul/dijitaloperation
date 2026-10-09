<?php

/**
 * Sorgu kural motoru (QueryRuleEngine) — versioned rules, written from the operator's query exports (Sorgular › CSV
 * indir). Every word here is FOLDED (lowercase, Turkish letters → ASCII: "diş" → "dis", "ücret" → "ucret") and in its
 * base form: words are reduced to the shortest form that occurs in the query library before the rules apply
 * ("implantları" → "implant", "estetiği" → "estetik"); rare misspellings one letter away from a frequent word are
 * found automatically ("implamt" → "implant").
 *
 * Adım 1 · varyant: queries whose variant key is equal mean the same thing and show as one row ("+N varyant").
 * Adım 2 · konu / yön: facet words are sections of the same content (fiyat, nedir, nasıl…); what is left is the topic.
 * English / German queries get their own keys ("[en] …"): content is written per language.
 *
 * v2 (2026-10-01): written from the 189 279-query export — English / German markers, facets and plurals, sector words
 * of Diş sağlığı, Medikal estetik, Perde, Geri dönüşüm.
 * v3 (2026-10-09): informational searches ("nedir", "nasıl", "ne kadar sürer", "avantajları") keep their own topic key
 * (" #info") instead of merging with the commercial ones ("fiyat", "randevu"); "çok" / "daha" stay in the topic ("çok
 * kanallı kanal tedavisi" ≠ "kanal tedavisi"); "dental" / "is" are no English markers ("dental implant", "hurda iş
 * makinesi" are Turkish), and "is" is no longer dropped (Turkish "iş").
 * Raise `version` on every change; "Kuralları uygula" (or the next import) recomputes every query.
 */
return [
    'version' => 3,

    // "AI ile kümele" parts (not rules; changing them needs no new version): topics of the skeleton call, topics per
    // placing call.
    'cluster' => ['skeleton_topics' => 400, 'place_topics' => 300],

    'variant' => [
        // Dropped from every query: connectives, question particles (Turkish, English, German).
        'drop' => ['ve', 'ile', 'icin', 'bir', 'mi', 'mu', 'midir', 'mudur', 'misin', 'mudur', 'da', 'de', 'ki', 'veya', 'yada', 'ya',
            'the', 'and', 'of', 'for', 'a', 'an', 'to', 'in', 'on', 'or', 'are', 'do', 'does', 'did', 'can', 'my', 'i', 'you',
            'your', 'it', 'be', 'with', 'from', 'at', 'by', 'this', 'that',
            'der', 'die', 'das', 'und', 'mit', 'ein', 'eine', 'einen', 'ist', 'im', 'zu', 'bei', 'fur', 'von'],

        // Dropped when the whole word matches (years).
        'drop_patterns' => ['/^(19|20)\d{2}$/'],

        // Words that add nothing inside a service's queries, in every sector ("implant tedavisi" = "implant").
        'generic' => ['tedavi', 'uygulama', 'islem', 'treatment', 'procedure', 'behandlung'],

        // Words a sector's queries imply (sector name folded => words): "diş implant" = "implant" in Diş sağlığı.
        'sector_implied' => [
            'dis sagligi' => ['dis', 'dental', 'tooth', 'teeth', 'zahn', 'zahne'],
            'medikal estetik ve plastik cerrahi' => ['estetik', 'aesthetic', 'cosmetic'],
            'guzellik ve kisisel bakim' => [],
            'perde ve ev tekstili' => ['perde'],
            'geri donusum' => ['hurda'],
            'sac ekimi' => ['sac'],
        ],

        // Same meaning, written differently: word (or phrase) => canonical word (or phrase). Applied after stemming.
        'synonyms' => [
            'ucret' => 'fiyat',
            'ucreti' => 'fiyat',
            'ucretleri' => 'fiyat',
            'fiat' => 'fiyat',
            'fiyati' => 'fiyat',
            'burnu' => 'burun',
            'agzi' => 'agiz',
            'cekim' => 'cekimi',
            'all on four' => 'all on 4',
            'all on four implant' => 'all on 4',
            'yirmilik' => '20 lik',
            '20lik' => '20 lik',
            'ozelde' => 'ozel',
            'turkiyede' => 'turkiye',
        ],

        // Frequent misspellings the automatic detection cannot see (too frequent or too far): word => correct word.
        'typos' => [
            'implamt' => 'implant',
            'impilant' => 'implant',
            'inplant' => 'implant',
            'implat' => 'implant',
            'zirkonyun' => 'zirkonyum',
            'zirkonya' => 'zirkonyum',
            'zirkon' => 'zirkonyum',
            'botox' => 'botoks',
            'ortadonti' => 'ortodonti',
            'beyazlatma' => 'beyazlatma',
        ],

        // Words never shortened, never taken for a misspelling ("dolgun" ≠ "dolgu", "dentin" ≠ "dent").
        'no_stem' => ['dolgun', 'kasik', 'dentin', 'denta', 'meme', 'kas', 'kasi', 'dis', 'disi', 'dise', 'disler', 'ise', 'isi'],

        // Not Turkish: a query holding one of these words gets the language prefix ("[en] implant").
        'languages' => [
            'en' => ['the', 'how', 'what', 'why', 'when', 'which', 'are', 'does', 'can', 'teeth', 'tooth', 'after', 'cost', 'price',
                'prices', 'best', 'near', 'with', 'my', 'your', 'for', 'of', 'to', 'and', 'surgery', 'extraction', 'pain',
                'removal', 'treatment', 'clinic', 'dentist', 'wisdom', 'root', 'canal', 'filling', 'braces', 'whitening', 'veneers',
                'veneer', 'crown', 'crowns', 'implants', 'jaw', 'gum', 'gums', 'hair', 'laser', 'skin', 'breast', 'nose', 'face',
                'transplant', 'lift', 'before', 'long', 'take'],
            'de' => ['der', 'die', 'das', 'und', 'nach', 'wie', 'was', 'kosten', 'zahn', 'zahne', 'zahnarzt', 'mit', 'ist', 'ein',
                'eine', 'schmerzen', 'kiefer', 'ziehen', 'weisheitszahn', 'behandlung'],
        ],
    ],

    'topic' => [
        // Facets: sections of the same content. facet => phrases (longest first wins; a word is in one facet).
        'facets' => [
            'fiyat' => ['fiyat', 'kac para', 'ne kadar', 'maliyet', 'para', 'tl', 'taksit', 'kampanya', 'indirim', 'ucuz', 'uygun fiyat',
                'price', 'cost', 'how much', 'cheap', 'kosten', 'preis'],
            'nedir' => ['nedir', 'ne demek', 'nelerdir', 'anlam', 'hakkinda', 'bilgi', 'what', 'meaning', 'was'],
            'nasil' => ['nasil yapilir', 'nasil', 'asama', 'yapilis', 'yapilir', 'how', 'wie'],
            'yorum' => ['yorum', 'deneyim', 'tavsiye', 'oneri', 'sikayet', 'yaptiranlar', 'review', 'erfahrung'],
            'en_iyi' => ['en iyi', 'en uygun', 'en ucuz', 'iyi', 'kaliteli', 'uzman', 'best', 'top'],
            'sure' => ['ne kadar surer', 'kac gun', 'kac saat', 'kac seans', 'sure', 'surer', 'how long', 'wie lange'],
            'garanti' => ['garanti', 'warranty'],
            'avantaj' => ['avantaj', 'dezavantaj', 'fayda', 'arti', 'eksi', 'benefit'],
            'randevu' => ['randevu', 'iletisim', 'telefon', 'adres', 'muayene', 'appointment'],
            'yakin' => ['en yakin', 'yakinimda', 'yakin', 'near me', 'nearby', 'near'],
        ],

        // Informational facets: a query holding one gets its own topic key ("implant #info"), the article that explains
        // is not the page that sells.
        'info_facets' => ['nedir', 'nasil', 'sure', 'avantaj'],

        // Left-overs dropped from the topic once the facets are taken out.
        'drop' => ['en', 'gibi', 'kadar', 'hangi', 'ne', 'me', 'much'],
    ],
];
