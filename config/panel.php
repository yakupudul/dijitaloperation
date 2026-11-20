<?php

// Müşteri paneli (panel.moximu.com): the same MoxDOP install answers on this host with the client screens only.
return [
    'domain' => env('PANEL_DOMAIN', 'panel.moximu.com'),
    'name' => env('PANEL_NAME', 'Moximu Panel'),
    // Files a brand's users attach to a person: private disk, never under public/.
    'files_disk' => env('PANEL_FILES_DISK', 'local'),
    'max_file_kb' => 20480,
];
