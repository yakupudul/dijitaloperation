<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a person's history: a note, a call, a request, a stage change, a file, a reminder. */
class PanelEvent extends Model
{
    use BelongsToBrand;

    public const string NOTE = 'note';

    public const string CALL = 'call';

    public const string REQUEST = 'request';

    public const string STAGE = 'stage';

    public const string CREATED = 'created';

    public const string ASSIGNED = 'assigned';

    public const string FILE = 'file';

    public const string REMINDER = 'reminder';

    public const string EDITED = 'edited';

    protected $fillable = ['person_id', 'type', 'body', 'meta', 'panel_user_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'meta' => 'array', 'occurred_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(PanelPerson::class, 'person_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'panel_user_id');
    }
}
