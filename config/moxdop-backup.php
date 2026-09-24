<?php

/*
| Faz 10d — sistem yedeği. Veritabanı her gece sıkıştırılmış olarak storage/app/backups altına alınır; `remote_disk`
| verilirse (config/filesystems.php'deki bir disk, ör. s3) aynı dosya oraya da kopyalanır.
*/
return [
    'enabled' => env('MOXDOP_BACKUP_ENABLED', true),
    'directory' => storage_path('app/backups'),
    'keep' => (int) env('MOXDOP_BACKUP_KEEP', 14),
    'remote_disk' => env('MOXDOP_BACKUP_REMOTE_DISK'),
    // Son başarılı yedek bu kadar saatten eskiyse Sistem Sağlığı uyarır.
    'stale_hours' => 26,
    'pg_dump' => env('MOXDOP_PG_DUMP', 'pg_dump'),
    'mysqldump' => env('MOXDOP_MYSQLDUMP', 'mysqldump'),
];
