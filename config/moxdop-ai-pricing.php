<?php

/*
|--------------------------------------------------------------------------
| AI fiyatları (USD / 1 milyon token) ve aylık bütçe
|--------------------------------------------------------------------------
| Maliyet = girdi × input + çıktı × output (+ önbellek okuma/yazma). Listede olmayan
| model için maliyet "bilinmiyor" kaydedilir ve bütçe dolduğunda o model çalıştırılmaz
| (sıfır maliyetli olduğu kanıtlanamaz). Fiyatlar değişince yalnızca bu dosya güncellenir.
| Anthropic fiyatları: Anthropic API fiyat tablosu (2026-06). OpenAI / Gemini fiyatlarını
| hesabınızdaki güncel tarifeden ekleyin.
*/

return [
    'monthly_budget_usd' => (float) env('AI_MONTHLY_BUDGET_USD', 25),

    'models' => [
        'anthropic' => [
            'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
            'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00],
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
            'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00],
        ],
        'openai' => [],
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
