<?php

namespace App\Services\Gbp\Desk;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\GbpWriter;
use App\Services\Gbp\GbpAssistant;
use App\Services\Gbp\GbpSuggestions;
use App\Support\TurkishPublicHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Açıklama ve saatler (ADR-079):
 *  - Açıklama: the profile's description (≤ 750 characters, service and area words, no contact data). AI proposes it
 *    (`gbp.description`, the existing İşletme Profili suggestion); the Admin reads / edits and sends it to Google.
 *  - Özel günler: official holidays of the next 120 days; for each, the profiles that have not entered special hours
 *    for those dates. The Admin picks closed / custom hours per holiday for a brand and sends them to every chosen
 *    profile (only those dates change on Google).
 */
final class ProfileFields
{
    public const int DESCRIPTION_GOOD = 250;

    public const int HOLIDAY_WINDOW_DAYS = 120;

    public function __construct(private readonly GbpAssistant $assistant) {}

    /**
     * @param  Collection<int, DigitalAsset>  $locations
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, array{current: string, length: int, state: string, label: string, suggestion: ?Suggestion, proposed: ?string, ai: ?array<string, mixed>, last_write: ?ExternalWriteAction}>
     */
    public function descriptions(Collection $locations, array $snapshots): array
    {
        $ids = $locations->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $suggestions = Suggestion::query()->where('channel', GbpSuggestions::CHANNEL)->where('target_type', GbpSuggestions::TARGET)->whereIn('target_id', $ids)
            ->where('action_type', 'gbp_description')->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED])->latest('id')->get()->unique('target_id')->keyBy('target_id');
        $writes = ExternalWriteAction::query()->whereIn('digital_asset_id', $ids)->where('action', ExternalWriteAction::ACTION_PROFILE_FIELDS)
            ->latest('id')->get()->filter(fn (ExternalWriteAction $a): bool => array_key_exists('description', (array) data_get($a->request_payload, 'fields', [])))->unique('digital_asset_id')->keyBy('digital_asset_id');
        $out = [];
        foreach ($locations as $location) {
            $current = (string) ($snapshots[$location->id]['description'] ?? '');
            $length = mb_strlen($current);
            $suggestion = $suggestions->get($location->id);
            $proposed = $suggestion !== null ? (string) data_get($suggestion->action, 'proposed', '') : null;
            $state = match (true) {
                ! isset($snapshots[$location->id]) => 'no_data',
                $proposed !== null && $proposed !== '' && trim($proposed) !== trim($current) => 'proposal',
                $length === 0 => 'missing',
                $length < self::DESCRIPTION_GOOD => 'short',
                default => 'ok',
            };
            $out[$location->id] = [
                'current' => $current, 'length' => $length, 'state' => $state,
                'label' => ['no_data' => 'Profil verisi yok', 'proposal' => 'Yeni açıklama hazır', 'missing' => 'Açıklama yok', 'short' => 'Açıklama kısa', 'ok' => 'Açıklama yeterli'][$state],
                'suggestion' => $suggestion, 'proposed' => $proposed, 'ai' => $this->assistant->state((int) $location->id, GbpAssistant::OP_DESCRIPTION),
                'last_write' => $writes->get($location->id),
            ];
        }

        return $out;
    }

    /**
     * Queues the AI description for the given profiles (skips those already running).
     *
     * @param  Collection<int, DigitalAsset>  $locations
     */
    public function prepareDescriptions(Collection $locations): int
    {
        $queued = 0;
        foreach ($locations as $location) {
            if (($this->assistant->state((int) $location->id, GbpAssistant::OP_DESCRIPTION)['status'] ?? null) === 'running') {
                continue;
            }
            $this->assistant->queue($location, GbpAssistant::OP_DESCRIPTION);
            $queued++;
        }

        return $queued;
    }

    /**
     * Sends a description to Google (Admin). A proposal is written against the description the profile had then: when
     * the profile's description changed since (latest collection), the proposal is stale and refused.
     *
     * @throws ValidationException
     */
    public function sendDescription(User $user, DigitalAsset $location, string $text, ?Suggestion $suggestion = null): ExternalWriteAction
    {
        if ($suggestion !== null && (int) $suggestion->target_id !== (int) $location->id) {
            $suggestion = null;
        }
        if ($suggestion !== null && is_array($suggestion->action) && array_key_exists('current', $suggestion->action)) {
            $now = app(GbpDesk::class)->snapshots([(int) $location->id])[$location->id]['description'] ?? null;
            if ($now === null || trim((string) $now) !== trim((string) $suggestion->action['current'])) {
                throw ValidationException::withMessages(['description' => 'Profilin açıklaması öneri hazırlandıktan sonra değişti; öneri eskidi.']);
            }
        }

        return app(ExternalWriteService::class)->requestProfileFields($user, $location, ['description' => trim($text)], 'Açıklama', $suggestion);
    }

    /**
     * Official holidays of the coming window.
     *
     * @return list<array{key: string, name: string, dates: list<string>}>
     */
    public function holidays(): array
    {
        return TurkishPublicHolidays::upcoming(CarbonImmutable::now('Europe/Istanbul')->startOfDay(), self::HOLIDAY_WINDOW_DAYS);
    }

    /**
     * Per profile, which dates of the holiday already have special hours on Google (and how).
     *
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  list<string>  $dates
     * @return array<int, array{set: array<string, string>, missing: list<string>}>
     */
    public function holidayState(array $snapshots, array $dates): array
    {
        $out = [];
        foreach ($snapshots as $assetId => $snapshot) {
            $set = [];
            foreach ((array) $snapshot['special_hours'] as $period) {
                $date = GbpWriter::periodDate((array) $period);
                if (in_array($date, $dates, true)) {
                    $set[$date] = (bool) ($period['closed'] ?? false) ? 'Kapalı' : self::time($period['openTime'] ?? null).'–'.self::time($period['closeTime'] ?? null);
                }
            }
            $out[$assetId] = ['set' => $set, 'missing' => array_values(array_diff($dates, array_keys($set)))];
        }

        return $out;
    }

    /**
     * Admin: the same holiday hours to several profiles (one write each).
     *
     * @param  Collection<int, DigitalAsset>  $locations
     * @param  list<array{date: string, closed?: bool, open?: string, close?: string}>  $rows
     * @return array{sent: int, failed: list<string>}
     */
    public function sendHours(User $user, Collection $locations, array $rows, string $holiday): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['hours' => 'Gönderilecek gün yok.']);
        }
        $sent = 0;
        $failed = [];
        foreach ($locations as $location) {
            try {
                app(ExternalWriteService::class)->requestProfileFields($user, $location, ['special_hours' => $rows], 'Özel gün saatleri · '.$holiday);
                $sent++;
            } catch (ValidationException $exception) {
                $failed[] = GbpDesk::shortName((string) $location->name).': '.collect($exception->errors())->flatten()->first();
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private static function time(mixed $t): string
    {
        return is_array($t) ? sprintf('%02d:%02d', (int) ($t['hours'] ?? 0), (int) ($t['minutes'] ?? 0)) : '';
    }
}
