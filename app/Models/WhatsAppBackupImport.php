<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One uploaded WhatsApp Business backup: uploading → uploaded → queued → running → completed / failed. The key is
 * kept encrypted only between "Çıkar" and the end of extraction. Each queueing is one attempt; an attempt that stops
 * moving (see WhatsAppBackupImporter::recoverStalled) is started again.
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
            'size' => 'integer', 'received_bytes' => 'integer', 'backup_key' => 'encrypted', 'attempt' => 'integer',
            'stats' => 'array', 'queued_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /** When the current attempt last showed life: its start, its last progress update, or its queueing. */
    public function lastActivityAt(): ?CarbonImmutable
    {
        $at = $this->status === 'running' ? ($this->updated_at ?? $this->started_at) : ($this->queued_at ?? $this->updated_at);

        return $at === null ? null : CarbonImmutable::instance($at);
    }

    public function path(): string
    {
        return storage_path('app/private/whatsapp-backups/'.$this->id.'.crypt15');
    }
}
