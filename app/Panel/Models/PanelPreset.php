<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;

/** A brand's own ready text, written once and picked afterwards: a topic people ask about, a note, a message. */
class PanelPreset extends Model
{
    use BelongsToBrand;

    public const string INTEREST = 'interest';

    public const string NOTE = 'note';

    public const string MESSAGE = 'message';

    protected $fillable = ['kind', 'label', 'body', 'created_by'];
}
