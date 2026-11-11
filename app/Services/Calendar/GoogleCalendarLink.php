<?php

namespace App\Services\Calendar;

use App\Models\Reminder;
use App\Services\Integrations\Google\GoogleWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Günlük Düzen 4.3: Takvim and Google Takvim show the same day. An own entry (iş, toplantı) is written to the main
 * Google calendar when it is saved and removed when it is deleted; what the operator adds in Google is read when
 * Takvim opens (kept five minutes) and shown as read-only lines. No scheduled job: writes follow a save, reads
 * follow a view.
 */
final class GoogleCalendarLink
{
    private const string API = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public function __construct(private readonly GoogleWorkspace $workspace) {}

    public function linked(): bool
    {
        return rescue(fn (): bool => $this->workspace->has(GoogleWorkspace::CALENDAR), false, false);
    }

    /** Writes the entry as an event (new or changed); an entry without a day, or a note, has none. */
    public function push(Reminder $reminder): void
    {
        if ($reminder->remind_at === null || $reminder->isNote()) {
            $this->remove($reminder->google_event_id);
            $reminder->google_event_id !== null && $reminder->newQuery()->whereKey($reminder->id)->update(['google_event_id' => null]);

            return;
        }
        $body = $this->event($reminder);
        $response = $reminder->google_event_id !== null
            ? $this->request()->put(self::API.'/'.rawurlencode((string) $reminder->google_event_id), $body)
            : $this->request()->post(self::API, $body);
        if (in_array($response->status(), [404, 410], true) && $reminder->google_event_id !== null) {
            // Deleted in Google: written again as a new event.
            $response = $this->request()->post(self::API, $body);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Google Takvim etkinliği yazılamadı (HTTP '.$response->status().').');
        }
        $id = (string) $response->json('id');
        if ($id !== '' && $id !== $reminder->google_event_id) {
            $reminder->newQuery()->whereKey($reminder->id)->update(['google_event_id' => $id]);
        }
    }

    public function remove(?string $eventId): void
    {
        if (blank($eventId)) {
            return;
        }
        $response = $this->request()->delete(self::API.'/'.rawurlencode((string) $eventId));
        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException('Google Takvim etkinliği silinemedi (HTTP '.$response->status().').');
        }
    }

    /**
     * What stands in Google's main calendar in the range and was not written from here.
     *
     * @return list<array{date: string, time: ?string, end: ?string, title: string, place: ?string, url: ?string}>
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $this->linked()) {
            return [];
        }

        return Cache::remember('calendar.google.'.$from->toDateString().'.'.$to->toDateString(), 300, function () use ($from, $to): array {
            try {
                $response = $this->request()->timeout(6)->get(self::API, [
                    'timeMin' => $from->toRfc3339String(), 'timeMax' => $to->toRfc3339String(), 'singleEvents' => 'true', 'orderBy' => 'startTime',
                    'maxResults' => 250, 'fields' => 'items(id,status,summary,location,htmlLink,start,end,extendedProperties/private)',
                ]);
                if (! $response->successful()) {
                    return [];
                }
                $out = [];
                foreach ((array) $response->json('items', []) as $item) {
                    if (($item['status'] ?? '') === 'cancelled' || filled(data_get($item, 'extendedProperties.private.moxdop'))) {
                        continue;
                    }
                    $allDay = isset($item['start']['date']);
                    $start = CarbonImmutable::parse((string) ($item['start']['dateTime'] ?? $item['start']['date'] ?? ''), CalendarFeed::TZ)->setTimezone(CalendarFeed::TZ);
                    $end = isset($item['end']['dateTime']) ? CarbonImmutable::parse((string) $item['end']['dateTime'])->setTimezone(CalendarFeed::TZ) : null;
                    $out[] = [
                        'date' => $start->toDateString(), 'time' => $allDay ? null : $start->format('H:i'), 'end' => $end?->format('H:i'),
                        'title' => mb_substr(trim((string) ($item['summary'] ?? '')) ?: 'Başlıksız etkinlik', 0, 200),
                        'place' => filled($item['location'] ?? null) ? mb_substr((string) $item['location'], 0, 200) : null,
                        'url' => is_string($item['htmlLink'] ?? null) && str_starts_with($item['htmlLink'], 'https://') ? $item['htmlLink'] : null,
                    ];
                }

                return $out;
            } catch (Throwable $error) {
                report($error);

                return [];
            }
        });
    }

    /** @return array<string, mixed> */
    private function event(Reminder $reminder): array
    {
        $start = CarbonImmutable::instance($reminder->remind_at)->setTimezone(CalendarFeed::TZ);
        $end = $reminder->ends_at !== null ? CarbonImmutable::instance($reminder->ends_at)->setTimezone(CalendarFeed::TZ) : $start->addMinutes(30);
        if ($end->lte($start)) {
            $end = $start->addMinutes(30);
        }
        $body = [
            'summary' => ($reminder->done_at !== null && $reminder->repeat === 'none' ? '✓ ' : '').(string) $reminder->title,
            'description' => (string) ($reminder->notes ?? ''),
            'location' => (string) ($reminder->place ?? ''),
            'extendedProperties' => ['private' => ['moxdop' => (string) $reminder->id]],
        ] + ($reminder->all_day
            ? ['start' => ['date' => $start->toDateString()], 'end' => ['date' => $start->addDay()->toDateString()]]
            : ['start' => ['dateTime' => $start->toRfc3339String(), 'timeZone' => CalendarFeed::TZ], 'end' => ['dateTime' => $end->toRfc3339String(), 'timeZone' => CalendarFeed::TZ]]);
        $rule = ['weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'][$reminder->repeat] ?? null;
        if ($rule !== null) {
            $body['recurrence'] = ['RRULE:FREQ='.$rule];
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->workspace->token())->acceptJson()->connectTimeout(8)->timeout(20);
    }
}
