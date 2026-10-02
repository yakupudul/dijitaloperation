<?php

/*
|--------------------------------------------------------------------------
| AI fiyatları (USD / 1 milyon token) ve aylık bütçe
|--------------------------------------------------------------------------
| Maliyet = girdi × input + çıktı × output (+ önbellek okuma/yazma). Listede olmayan
| model için maliyet "bilinmiyor" kaydedilir ve bütçe dolduğunda o model çalıştırılmaz
| (sıfır maliyetli olduğu kanıtlanamaz). Fiyatlar değişince yalnızca bu dosya güncellenir.
| Anthropic fiyatları: Anthropic API fiyat tablosu (2026-06). OpenAI: resmî API fiyat listesi
| (standart katman); rotalarda kullanılan modellerin hepsi burada olmalı — yoksa kullanım $0.00
| görünür ve bütçe işlemez (AiPricingCoverageTest). Gemini fiyatlarını hesabınızdaki tarifeden ekleyin.
*/

return [
    // Operatör onayı: ayda ~100 USD. Ayarlar › AI ekranından kaydedilen değer bunu geçersiz kılar.
    'monthly_budget_usd' => (float) env('AI_MONTHLY_BUDGET_USD', 100),

    // Günlük AI tavanı: ALL AI calls of the day (Europe/Istanbul), automatic and clicked alike; no paid call starts once
    // the day spent this much. Ayarlar › AI işlemleri overrides it. 0 = no daily ceiling.
    'daily_auto_budget_usd' => (float) env('AI_DAILY_AUTO_BUDGET_USD', 1),

    // Areas (operation prefix: queries.*, site.*, brand.*…) whose AI may run without an operator click. "*" = all.
    'automatic_areas' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_AUTOMATIC_AREAS', 'queries'))))),

    'models' => [
        'anthropic' => [
            'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
            'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00],
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
            'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00],
        ],
        'openai' => [
            'gpt-5' => ['input' => 1.25, 'output' => 10.00],
            'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
            'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
            'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
            'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
            'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
            'text-embedding-3-small' => ['input' => 0.02, 'output' => 0.0],
            'text-embedding-3-large' => ['input' => 0.13, 'output' => 0.0],
        ],
        'gemini' => [],
        // Groq ücretsiz katmanı; ücretli plana geçilirse model fiyatlarını buraya ekleyin.
        'groq' => ['*' => ['input' => 0.0, 'output' => 0.0]],
        // OpenRouter: ":free" ile biten modeller ücretsiz; diğerleri için fiyat ekleyin.
        'openrouter' => [],
    ],

    // Önbellek çarpanları (girdi fiyatına göre).
    'cache_read_multiplier' => 0.1,
    'cache_write_multiplier' => 1.25,
];
