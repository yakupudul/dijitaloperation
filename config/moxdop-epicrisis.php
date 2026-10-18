<?php

/*
 * Marka epikrizi (yakup, 2026-10-16 "AI yazıp doğrudan kullansın, bu şekilde yap"): the fields of a brand's verified
 * record, how long each stays valid, which AI work reads it, and per sector which fields must be filled.
 *
 * Fields:
 *  - label: shown on the Epikriz tab and in questions.
 *  - single: one true value per scope (a second, different value from another source is a conflict).
 *  - days: how long a value stays valid after it was last seen at its source (then it is read again or asked).
 *  - slices: the AI work that reads it (content, ads, gbp, reply, meta).
 *  - ask: the question put to the operator when the field is missing (sector requirement).
 */
return [
    'fields' => [
        'service' => ['label' => 'Hizmet', 'single' => false, 'days' => 365, 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta'],
            'ask' => 'Markanın verdiği hizmetler hangileri?'],
        'branch' => ['label' => 'Şube', 'single' => false, 'days' => 365, 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta'],
            'ask' => 'Markanın şubeleri nerede (ilçe, şehir)?'],
        'phone' => ['label' => 'Telefon', 'single' => false, 'days' => 180, 'slices' => ['ads', 'gbp', 'reply', 'meta'],
            'ask' => 'Müşterilerin aradığı telefon numarası hangisi?'],
        'email' => ['label' => 'E-posta', 'single' => false, 'days' => 365, 'slices' => ['gbp', 'reply'], 'ask' => null],
        'address' => ['label' => 'Adres', 'single' => true, 'days' => 365, 'slices' => ['gbp', 'ads', 'reply'],
            'ask' => 'Şubenin açık adresi nedir?'],
        'hours' => ['label' => 'Çalışma saatleri', 'single' => true, 'days' => 180, 'slices' => ['gbp', 'ads', 'reply'], 'ask' => null],
        'expert' => ['label' => 'Hekim / uzman', 'single' => false, 'days' => 180, 'slices' => ['content', 'gbp', 'reply', 'meta'],
            'ask' => 'Çalışan hekimler ya da uzmanlar kimler, uzmanlık alanları ne?'],
        'experience' => ['label' => 'Deneyim / kuruluş', 'single' => true, 'days' => 365, 'slices' => ['content', 'ads', 'gbp', 'meta'],
            'ask' => 'Marka kaç yılında kuruldu ya da kaç yıllık deneyimi var?'],
        'warranty' => ['label' => 'Garanti', 'single' => true, 'days' => 365, 'slices' => ['content', 'ads', 'gbp', 'meta'],
            'ask' => 'Tedavilerde ya da ürünlerde verilen garanti nedir (hangi işte kaç yıl)?'],
        'material' => ['label' => 'Cihaz / malzeme markası', 'single' => false, 'days' => 365, 'slices' => ['content', 'ads', 'gbp', 'meta'],
            'ask' => 'Kullanılan cihaz ve malzeme markaları neler (ör. implant markası)?'],
        'license' => ['label' => 'Ruhsat / izin', 'single' => true, 'days' => 365, 'slices' => ['content', 'gbp'],
            'ask' => 'Ruhsat ya da faaliyet izni numarası nedir?'],
        'certificate' => ['label' => 'Sertifika / üyelik', 'single' => false, 'days' => 365, 'slices' => ['content', 'ads', 'gbp', 'meta'], 'ask' => null],
        'price' => ['label' => 'Fiyat', 'single' => true, 'days' => 90, 'slices' => ['ads', 'meta'], 'ask' => null],
        'package' => ['label' => 'Paket / kampanya', 'single' => false, 'days' => 90, 'slices' => ['ads', 'meta', 'gbp'], 'ask' => null],
        'payment' => ['label' => 'Ödeme / taksit', 'single' => false, 'days' => 180, 'slices' => ['content', 'ads', 'meta'], 'ask' => null],
        'process' => ['label' => 'Kendi süreci', 'single' => false, 'days' => 365, 'slices' => ['content', 'gbp', 'reply', 'meta'],
            'ask' => 'Bir müşterinin ilk temastan sonuca kadar sizde yaşadığı süreç nasıl?'],
        'differentiator' => ['label' => 'Farkı', 'single' => false, 'days' => 365, 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta'],
            'ask' => 'Markayı rakiplerinden ayıran somut 2-3 şey nedir?'],
        'abroad' => ['label' => 'Yurt dışı hizmeti', 'single' => false, 'days' => 180, 'slices' => ['content', 'ads', 'meta'], 'ask' => null],
        'language' => ['label' => 'Konuşulan dil', 'single' => false, 'days' => 365, 'slices' => ['content', 'ads', 'gbp', 'meta'], 'ask' => null],
        'website' => ['label' => 'Web sitesi', 'single' => true, 'days' => 365, 'slices' => ['gbp', 'ads'], 'ask' => null],
    ],

    /** Fields every brand of the sector must have (then a question is asked); `default` for every other sector. */
    'sectors' => [
        'default' => ['service', 'branch', 'phone', 'differentiator', 'experience'],
        'dental' => ['service', 'branch', 'phone', 'differentiator', 'experience', 'expert', 'warranty', 'material', 'license', 'process'],
        'healthcare' => ['service', 'branch', 'phone', 'differentiator', 'experience', 'expert', 'license', 'process'],
        'medical_aesthetics' => ['service', 'branch', 'phone', 'differentiator', 'experience', 'expert', 'license', 'process'],
    ],

    /** Synthesis kinds (layer 3) and the AI work each one is sent to. */
    'synthesis' => [
        'positioning' => ['label' => 'Konumlanma', 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta']],
        'audience' => ['label' => 'Hedef kitle', 'slices' => ['content', 'ads', 'meta']],
        'objection' => ['label' => 'İtiraz ve cevabı', 'slices' => ['content', 'ads', 'reply', 'meta']],
        'proof' => ['label' => 'Kanıt noktası', 'slices' => ['content', 'ads', 'gbp', 'meta']],
        'voice' => ['label' => 'Ses tonu', 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta']],
        'say' => ['label' => 'Söyle', 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta']],
        'dont_say' => ['label' => 'Söyleme', 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta']],
        'message_content' => ['label' => 'İçerikte ana mesaj', 'slices' => ['content']],
        'message_ads' => ['label' => 'Reklamda ana mesaj', 'slices' => ['ads']],
        'message_gbp' => ['label' => 'İşletme Profili\'nde ana mesaj', 'slices' => ['gbp']],
        'message_meta' => ['label' => 'Meta reklamında ana mesaj', 'slices' => ['meta']],
    ],

    /** Evidence kinds (layer 2, rules over the brand's data) and the AI work each one is sent to. */
    'evidence' => [
        'question' => ['label' => 'Müşterinin sorduğu', 'slices' => ['content', 'ads', 'gbp', 'meta']],
        'objection' => ['label' => 'Şikâyet / itiraz', 'slices' => ['content', 'ads', 'reply', 'meta']],
        'praise' => ['label' => 'Övülen yön', 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta']],
        'conversion' => ['label' => 'Müşteri sayılan işlem', 'slices' => ['ads', 'meta']],
        'ad_result' => ['label' => 'Reklamda sonuç getiren hizmet', 'slices' => ['content', 'ads', 'meta']],
        'competitor' => ['label' => 'Aynı şehirde aynı hizmet', 'slices' => ['ads', 'meta']],
        'season' => ['label' => 'Talep mevsimi', 'slices' => ['content', 'ads', 'gbp', 'meta']],
        'site_language' => ['label' => 'Site dili', 'slices' => ['content']],
    ],

    /** Lesson kinds (layer 4, learnt from the operator's rejections and the checks) — every AI work reads them. */
    'lessons' => [
        'rejected' => ['label' => 'Operatörün reddettiği', 'slices' => ['content', 'ads', 'gbp', 'reply', 'meta']],
        'check' => ['label' => 'Kontrolde tekrar takılan', 'slices' => ['content', 'gbp']],
        'works' => ['label' => 'İşe yarayan içerik', 'slices' => ['content', 'gbp']],
    ],

    /** The synthesis is written again after this many days, or sooner when this share of its basis changed. */
    'synthesis_days' => 30,
    'synthesis_change' => 0.25,

    /** Site facts are read again (AI) after this many days, or sooner when the key pages changed. */
    'facts_days' => 30,
    'key_pages' => 40,
];
