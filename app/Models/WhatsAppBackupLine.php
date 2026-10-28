<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An extra WhatsApp backup next to the inbox's own: another phone or number whose backup the operator extracts with
 * its own 64-digit key (kept encrypted here). Its chats are stored on the archive integration, apart from the inbox,
 * the brain and the reply drafts, and leave MoxDOP as its own Excel export.
 */
class WhatsAppBackupLine extends Model
{
    /** Provider of the integration that holds the extra backups' chats (never active: nothing is collected for it). */
    public const ARCHIVE_PROVIDER = 'whatsapp_backup_archive';

    protected $table = 'whatsapp_backup_lines';

    protected $guarded = ['id'];

    protected $hidden = ['backup_key'];

    protected function casts(): array
    {
        return ['backup_key' => 'encrypted'];
    }

    public function imports(): HasMany
    {
        return $this->hasMany(WhatsAppBackupImport::class, 'line_id');
    }

    /** phone_number_id of this line's conversations on the archive integration. */
    public function conversationLine(): string
    {
        return 'backup:'.$this->id;
    }

    public static function archive(): CoreIntegration
    {
        return CoreIntegration::query()->firstOrCreate(['provider' => self::ARCHIVE_PROVIDER], [
            'name' => 'WhatsApp yedek arşivi', 'status' => CoreIntegration::STATUS_DISABLED, 'config' => [],
        ]);
    }

    public static function archiveId(): ?int
    {
        return CoreIntegration::query()->where('provider', self::ARCHIVE_PROVIDER)->value('id');
    }
}
