<?php

/*
| Faz 10d — sistem yedeği. Veritabanı her gece sıkıştırılmış olarak storage/app/backups altına alınır; `remote_disk`
| verilirse (config/filesystems.php'deki bir disk, ör. s3) aynı dosya oraya da kopyalanır.
*/
return [
    'enabled' => env('MOXDOP_BACKUP_ENABLED', true),
    'directory' => env('MOXDOP_BACKUP_DIR') ?: storage_path('app/backups'),
    // The dump is written and verified here first, then moved into `directory`. Default: on the main disk (not /tmp,
    // which can be a small tmpfs — "fwrite(): … errno=28 No space left on device").
    'temp_directory' => env('BACKUP_TEMP_DIR') ?: storage_path('app/backup-tmp'),
    // Free-space check before a backup: database size × ratio (gzip'd SQL dump) + margin must be free.
    'compression_ratio' => (float) env('MOXDOP_BACKUP_COMPRESSION_RATIO', 0.35),
    'free_space_margin_mb' => (int) env('MOXDOP_BACKUP_FREE_MARGIN_MB', 200),
    'keep' => (int) env('MOXDOP_BACKUP_KEEP', 14),
    'remote_disk' => env('MOXDOP_BACKUP_REMOTE_DISK'),
    // Son başarılı yedek bu kadar saatten eskiyse Sistem Sağlığı uyarır.
    'stale_hours' => 26,
    'pg_dump' => env('MOXDOP_PG_DUMP', 'pg_dump'),
    'mysqldump' => env('MOXDOP_MYSQLDUMP', 'mysqldump'),
    // Faz 14: clients used by `moxdop:backup:restore`.
    'psql' => env('MOXDOP_PSQL', 'psql'),
    'mysql' => env('MOXDOP_MYSQL', 'mysql'),
];
