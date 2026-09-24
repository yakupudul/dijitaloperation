<?php

/*
| Faz 9 — Aylık rapor v2.
*/
return [
    // Müşteriye gönderilen imzalı rapor bağlantısının geçerlilik süresi (gün).
    'client_link_days' => 60,

    /* Day-1 drafts also get their AI commentary queued (uses the AI budget). */
    'auto_commentary' => (bool) env('MOXDOP_MONTHLY_AUTO_COMMENTARY', true),
];
