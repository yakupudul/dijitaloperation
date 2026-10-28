<?php

namespace App\Models;

use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One WhatsApp backup row (a phone or business): its own name, 64-digit key (kept encrypted here), uploaded files and
 * Excel. The operator marks one row "Görüşmelerde göster" (active): its chats sit in the inbox (WhatsApp integration,
 * line "backup") where the brain and reply drafts use them; every other row's chats wait on the archive integration
 * apart from everything, and move into the inbox when that row is marked (yakup, 2026-10-28).
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
        return ['backup_key' => 'encrypted', 'active' => 'boolean'];
    }

    /** The row shown in the inbox, if any. */
    public static function shown(): ?self
    {
        return self::query()->where('active', true)->first();
    }

    /** @return Builder<WhatsAppConversation> this row's chats, wherever they sit now */
    public function conversations(): Builder
    {
        return $this->active
            ? WhatsAppConversation::query()->where('integration_id', CoreIntegration::query()->where('provider', WhatsAppConnection::PROVIDER)->value('id'))
                ->where('phone_number_id', WhatsAppBackupImporter::LINE)
            : WhatsAppConversation::query()->where('integration_id', self::archiveId())->where('phone_number_id', $this->conversationLine());
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
