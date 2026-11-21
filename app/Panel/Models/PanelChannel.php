<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;

/** One connected channel of a brand: the id Meta's events carry for it, its state, and when it last spoke. */
class PanelChannel extends Model
{
    use BelongsToBrand;

    public const string CONNECTED = 'connected';

    public const string DISCONNECTED = 'disconnected';

    /** key => [label, what the id is called] */
    public const array KINDS = [
        'whatsapp' => ['WhatsApp', 'Telefon numarası kimliği (phone number ID)'],
        'instagram' => ['Instagram', 'Instagram hesap kimliği'],
        'facebook' => ['Facebook', 'Sayfa kimliği (page ID)'],
        'meta_form' => ['Meta formu', 'Formların bağlı olduğu sayfa kimliği (page ID)'],
    ];

    protected $fillable = ['channel', 'status', 'external_id', 'account_id', 'label', 'token', 'connected_at', 'disconnected_at', 'last_event_at', 'last_error', 'meta', 'connected_by'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'connected_at' => 'datetime', 'disconnected_at' => 'datetime', 'last_event_at' => 'datetime', 'meta' => 'array'];
    }

    public function isConnected(): bool
    {
        return $this->status === self::CONNECTED;
    }
}
