<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One uploaded WhatsApp Business backup: uploading → uploaded → queued → running → completed / failed. The key is
 * kept encrypted only between "Çıkar" and the end of extraction.
 */
class WhatsAppBackupImport extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_backup_imports';

    protected $guarded = [];

    protected $hidden = ['backup_key'];

    protected function casts(): array
    {
        return [
            'size' => 'integer', 'received_bytes' => 'integer', 'backup_key' => 'encrypted',
            'stats' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }

    public function path(): string
    {
        return storage_path('app/private/whatsapp-backups/'.$this->id.'.crypt15');
    }
}
