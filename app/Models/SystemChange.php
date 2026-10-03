<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Geliştirme havuzu: one fix or improvement of MoxDOP itself. Claude proposes (or the operator writes one) → the
 * operator approves → Claude codes it, pushes the branch and writes the deploy commands (ready) → the operator deploys
 * and marks it (deployed) → Claude checks the live system (verified / failed). Rejected ones stay as a record so the
 * same finding is not proposed again (fingerprint).
 */
class SystemChange extends Model
{
    public const array KINDS = ['bug', 'collection', 'page', 'design', 'improvement'];

    public const array KIND_LABELS = ['bug' => 'Yazılım hatası', 'collection' => 'Veri çekimi', 'page' => 'Sayfa hatası', 'design' => 'Tasarım', 'improvement' => 'İyileştirme'];

    public const string PROPOSED = 'proposed';

    public const string APPROVED = 'approved';

    public const string IN_PROGRESS = 'in_progress';

    public const string READY = 'ready';

    public const string DEPLOYED = 'deployed';

    public const string VERIFIED = 'verified';

    public const string FAILED = 'failed';

    public const string REJECTED = 'rejected';

    public const array STATUS_LABELS = [
        self::PROPOSED => 'Onay bekliyor', self::APPROVED => 'Onaylandı · Claude yapacak', self::IN_PROGRESS => 'Claude çalışıyor',
        self::READY => 'Deploy bekliyor', self::DEPLOYED => 'Deploy edildi · Claude kontrol edecek', self::VERIFIED => 'Tamam',
        self::FAILED => 'Kontrolde sorun', self::REJECTED => 'Reddedildi',
    ];

    public const array PRIORITY_LABELS = [1 => 'Acil', 2 => 'Normal', 3 => 'Düşük'];

    /** @var list<string> */
    protected $fillable = [
        'kind', 'title', 'detail', 'evidence', 'fingerprint', 'priority', 'source', 'status', 'operator_note', 'decided_by',
        'decided_at', 'branch', 'commit_sha', 'deploy_commands', 'work_note', 'ready_at', 'deployed_at', 'verified_at', 'verify_note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'priority' => 'integer', 'decided_at' => 'datetime', 'ready_at' => 'datetime',
            'deployed_at' => 'datetime', 'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return array<string, mixed> how a change is shown to Claude */
    public function toTool(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'status' => $this->status, 'priority' => $this->priority, 'source' => $this->source,
            'title' => $this->title, 'detail' => $this->detail, 'evidence' => $this->evidence ?? [], 'operator_note' => $this->operator_note,
            'branch' => $this->branch, 'commit' => $this->commit_sha, 'work_note' => $this->work_note, 'verify_note' => $this->verify_note,
            'proposed_at' => $this->created_at?->toIso8601String(), 'decided_at' => $this->decided_at?->toIso8601String(),
            'deployed_at' => $this->deployed_at?->toIso8601String(),
        ];
    }
}
