<?php

namespace App\Models;

use App\Enums\ClientRequestChannel;
use App\Enums\ClientRequestScopeState;
use App\Enums\ClientRequestStatus;
use Database\Factories\ClientRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientRequest extends Model
{
    /** @use HasFactory<ClientRequestFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'brand_id',
        'digital_asset_id',
        'service_definition_id',
        'customer_contact_id',
        'owner_user_id',
        'created_by_user_id',
        'title',
        'description',
        'status',
        'channel',
        'priority',
        'effort',
        'due_label',
        'due_date',
        'intake_scope_state',
        'intake_scope_snapshot',
        'intake_scope_assessed_at',
        'idempotency_key',
        'closed_at',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<ServiceDefinition, $this>
     */
    public function serviceDefinition(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class);
    }

    /**
     * Client-side requester (Customer Relationship contact). Distinct from createdBy.
     *
     * @return BelongsTo<CustomerContact, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'customer_contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Internal MoxDOP actor who recorded the Request.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isWaitingOnClient(): bool
    {
        return $this->status === ClientRequestStatus::WaitingOnClient;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClientRequestStatus::class,
            'channel' => ClientRequestChannel::class,
            'intake_scope_state' => ClientRequestScopeState::class,
            'intake_scope_snapshot' => 'array',
            'intake_scope_assessed_at' => 'datetime',
            'due_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }
}
