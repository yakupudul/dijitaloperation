<?php

namespace App\Models;

use Database\Factories\OperatorFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid',
    'user_id',
    'disk',
    'path',
    'original_name',
    'mime',
    'size',
    'scope_type',
    'scope_id',
    'customer_id',
    'brand_id',
    'digital_asset_id',
    'task_id',
    'description',
    'tags',
])]
class OperatorFile extends Model
{
    /** @use HasFactory<OperatorFileFactory> */
    use HasFactory;

    public const SCOPES = [
        'personal',
        'agency',
        'customer',
        'brand',
        'digital_asset',
        'task',
    ];

    /**
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf',
        'doc', 'docx',
        'xls', 'xlsx',
        'txt', 'csv',
        'zip',
    ];

    /**
     * @var list<string>
     */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'application/csv',
        'application/zip',
        'application/x-zip-compressed',
    ];

    /**
     * @var list<string>
     */
    public const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar',
        'exe', 'bat', 'cmd', 'com', 'msi', 'scr',
        'sh', 'bash', 'cgi', 'pl', 'py', 'rb',
        'js', 'html', 'htm', 'svg',
    ];

    protected static function booted(): void
    {
        static::creating(function (OperatorFile $file): void {
            if ($file->uuid === null || $file->uuid === '') {
                $file->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'size' => 'integer',
        ];
    }
}
