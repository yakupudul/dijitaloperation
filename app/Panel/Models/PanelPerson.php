<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use App\Panel\Support\PhoneKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One person of a brand; the phone number is what tells two people apart. Requests, notes and files hang on it. */
class PanelPerson extends Model
{
    use BelongsToBrand;

    protected $table = 'panel_people';

    protected $fillable = ['name', 'phone', 'email', 'gender', 'birth_year', 'city', 'district', 'stage', 'lost_reason', 'source', 'interest', 'assigned_user_id', 'appointment_at', 'last_activity_at'];

    protected function casts(): array
    {
        return ['appointment_at' => 'datetime', 'last_activity_at' => 'datetime', 'birth_year' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $person): void {
            $person->phone_key = PhoneKey::of($person->phone);
        });
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'assigned_user_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PanelRequest::class, 'person_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PanelEvent::class, 'person_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PanelReminder::class, 'person_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(PanelFile::class, 'person_id');
    }

    public function age(): ?int
    {
        return $this->birth_year ? max(0, (int) now()->year - (int) $this->birth_year) : null;
    }
}
