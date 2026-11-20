<?php

namespace App\Panel\Services;

use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelPreset;
use Illuminate\Support\Collection;

/** The brand's ready lists: topics for "İlgilendiği konu", note templates and ready WhatsApp messages. */
final class PanelPresets
{
    /** Topics to pick from: the saved ones first, then every topic already written on a person of the brand. */
    public function interests(): Collection
    {
        $saved = PanelPreset::query()->where('kind', PanelPreset::INTEREST)->orderBy('label')->pluck('label');
        $used = PanelPerson::query()->whereNotNull('interest')->where('interest', '!=', '')->distinct()->orderBy('interest')->limit(200)->pluck('interest');

        return $saved->concat($used)->map(fn ($label): string => trim((string) $label))->filter()
            ->unique(fn (string $label): string => self::key($label))->values();
    }

    /** @return Collection<int, PanelPreset> */
    public function of(string $kind): Collection
    {
        return PanelPreset::query()->where('kind', $kind)->orderBy('label')->get();
    }

    /** Adds a ready text; one with the same name (whatever the letter case) is updated instead of doubled. */
    public function add(string $kind, string $label, ?string $body, ?int $actorId): PanelPreset
    {
        $label = mb_substr(trim(preg_replace('/\s+/u', ' ', $label) ?? ''), 0, 190);
        $body = $body !== null && trim($body) !== '' ? trim($body) : null;
        $existing = PanelPreset::query()->where('kind', $kind)->get()->first(fn (PanelPreset $preset): bool => self::key($preset->label) === self::key($label));
        if ($existing !== null) {
            if ($body !== null) {
                $existing->forceFill(['body' => $body])->save();
            }

            return $existing;
        }

        return PanelPreset::query()->create(['kind' => $kind, 'label' => $label, 'body' => $body, 'created_by' => $actorId]);
    }

    /** A ready message for one person: "{ad}" becomes their first name. */
    public static function fill(string $body, string $name): string
    {
        return str_replace(['{ad}', '{AD}', '{Ad}'], trim((string) strtok(trim($name), ' ')), $body);
    }

    private static function key(string $label): string
    {
        return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], trim($label)));
    }
}
