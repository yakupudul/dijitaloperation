<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Meta lead of an ad account with the operator's manual quality mark (no contact details are stored).
 */
class MetaLead extends Model
{
    public const array MARKS = ['uygun' => 'Uygun', 'randevu' => 'Randevu', 'satis' => 'Satış', 'uygunsuz' => 'Uygunsuz'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'marked_at' => 'immutable_datetime'];
    }

    public function markLabel(): string
    {
        return self::MARKS[$this->mark] ?? 'İşaretsiz';
    }
}
