<?php

/*
 * Sağlayıcı nöbeti (Bağlantı Düzeni 4.5): when each API version the system calls stops answering.
 * Dates are the providers' own published dates, read on 2026-11-11; a month given without a day is kept as its first
 * day (Google says "any time in that month"). null = the provider has not published the date yet.
 * Sources: developers.google.com/google-ads/api/docs/sunset-dates, developers.facebook.com/docs/graph-api/changelog/versions
 */
return [
    'checked_on' => '2026-11-11',
    'google_ads' => ['v22' => '2026-10-01', 'v23' => '2027-02-01', 'v24' => '2027-05-01', 'v25' => '2027-08-01', 'v26' => '2027-11-01'],
    'meta_graph' => ['v23.0' => '2027-10-08', 'v24.0' => '2028-02-18', 'v25.0' => '2028-07-29', 'v26.0' => null],
    // The Marketing API (ads) retires its versions earlier than the Graph API.
    'meta_marketing' => ['v23.0' => '2026-06-09', 'v24.0' => '2026-10-06', 'v25.0' => null, 'v26.0' => null],
];
