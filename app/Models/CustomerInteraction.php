<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One contact with a customer (call, WhatsApp, e-mail, meeting, note) and the follow-up it created. */
class CustomerInteraction extends Model
{
    public const array CHANNELS = ['call' => 'Telefon', 'whatsapp' => 'WhatsApp', 'email' => 'E-posta', 'meeting' => 'Toplantı', 'note' => 'Not'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'next_action_at' => 'datetime', 'next_action_done_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
