<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One file a brand brought in itself (a WhatsApp backup, an Excel list) and what it added, so it can be taken back. */
class PanelImport extends Model
{
    use BelongsToBrand;

    public const string BACKUP = 'whatsapp_backup';

    public const string LEADS = 'leads';

    public const string CALLS = 'calls';

    public const array STATUSES = [
        'uploading' => 'Yükleniyor', 'uploaded' => 'Yüklendi', 'queued' => 'Sırada', 'running' => 'Aktarılıyor',
        'completed' => 'Tamamlandı', 'failed' => 'Olmadı', 'undone' => 'Geri alındı',
    ];

    protected $fillable = ['kind', 'status', 'file_name', 'size', 'received_bytes', 'path', 'secret', 'stats', 'error', 'created_by', 'started_at', 'finished_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'stats' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'size' => 'integer', 'received_bytes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'created_by');
    }

    /** Where the uploaded file waits; outside public/, removed as soon as the import ends. */
    public function file(): string
    {
        return storage_path('app/panel-imports/'.$this->brand_id.'/'.$this->id.'.bin');
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            'completed' => 'ok',
            'failed' => 'critical',
            'undone' => 'info',
            default => 'look',
        };
    }
}
