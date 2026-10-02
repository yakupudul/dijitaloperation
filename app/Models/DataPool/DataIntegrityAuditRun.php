<?php

namespace App\Models\DataPool;

use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataIntegrityAuditRun extends Model
{
    protected $table = 'data_integrity_audit_runs';

    protected $fillable = [
        'uuid',
        'status',
        'mode',
        'scope_type',
        'scope',
        'initiated_by_user_id',
        'contract_registry_version',
        'storage_contract_version',
        'formula_registry_version',
        'integrity_registry_version',
        'audit_rules_version',
        'started_at',
        'completed_at',
        'checks_total',
        'checks_pass',
        'checks_pass_with_limitation',
        'checks_warning',
        'checks_fail',
        'checks_unverified',
        'checks_not_applicable',
        'provider_readiness',
        'summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrityAuditStatus::class,
            'mode' => IntegrityAuditMode::class,
            'scope' => 'array',
            'provider_readiness' => 'array',
            'summary' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function checkResults(): HasMany
    {
        return $this->hasMany(DataIntegrityCheckResult::class, 'audit_run_id');
    }
}
