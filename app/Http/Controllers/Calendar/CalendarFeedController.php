<?php

namespace App\Http\Controllers\Calendar;

use App\Services\Calendar\CalendarIcs;
use Illuminate\Http\Response;

/** The read-only calendar feed Google Takvim subscribes to (no login: the secret address is the key). */
final class CalendarFeedController
{
    public function __invoke(string $token, CalendarIcs $ics): Response
    {
        $user = CalendarIcs::userFor($token);
        abort_if($user === null, 404);

        return response($ics->render($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="moxdop.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}
