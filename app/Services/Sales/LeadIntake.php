<?php

namespace App\Services\Sales;

use App\Models\AgencySetting;
use App\Models\SalesLead;
use App\Services\Assistant\PushNotifier;
use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Prospects that arrive by themselves (moximu.com forms now, Meta lead forms later): one card per person, where it came
 * from kept on the card, the operator told at once. At night (22:00-08:00) nobody is woken; the 08:30 summary lists
 * them first. Contact within the hour matters most, so a day-time form is pushed the moment it lands.
 */
final class LeadIntake
{
    public const int QUIET_FROM = 22;

    public const int QUIET_UNTIL = 8;

    /** The WordPress option the Moximu theme reads its endpoint and token from. */
    public const string SITE_OPTION = 'mx_lead_form';

    public function __construct(private readonly SalesLeads $leads, private readonly PushNotifier $push) {}

    /** The token moximu.com sends with each form; made (or remade) in Satış › Form bağlantısı. */
    public static function token(): ?string
    {
        $token = AgencySetting::query()->first()?->lead_form_token;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function issueToken(?int $siteId = null): string
    {
        $token = 'mxf_'.Str::random(40);
        $settings = AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        $settings->forceFill(['lead_form_token' => $token, 'lead_form_site_id' => $siteId])->save();

        return $token;
    }

    /**
     * "Siteyi bağla": a new token goes straight into the site's own settings (option mx_lead_form) through the
     * connector, so nobody copies a secret. The old token stops working at the same moment.
     */
    public function connectSite(int $siteId): void
    {
        $previous = AgencySetting::query()->first();
        $old = [$previous?->lead_form_token, $previous?->lead_form_site_id];
        $token = self::issueToken($siteId);
        try {
            app(WordPressSiteBuilder::class)->tool($siteId, 'set_option', ['name' => self::SITE_OPTION, 'value' => [
                'endpoint' => route('api.sales.form'), 'token' => $token, 'connected_at' => now()->toIso8601String(),
            ]]);
        } catch (Throwable $error) {
            // The site did not take it: the previous connection keeps working.
            AgencySetting::query()->first()?->forceFill(['lead_form_token' => $old[0], 'lead_form_site_id' => $old[1]])->save();

            throw $error;
        }
    }

    public static function siteId(): ?int
    {
        $id = AgencySetting::query()->value('lead_form_site_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * One form submission. Returns the card (a known phone or e-mail adds to its card instead of opening a new one).
     *
     * @param  array<string, mixed>  $input
     */
    public function fromForm(array $input, string $source = 'form'): SalesLead
    {
        $field = static fn (string $key, int $max = 255): string => mb_substr(trim(strip_tags((string) ($input[$key] ?? ''))), 0, $max);
        $origin = array_filter([
            'form' => $field('form', 80), 'page' => $field('page', 500), 'referrer' => $field('referrer', 500), 'service' => $field('service', 120),
            'utm_source' => $field('utm_source', 120), 'utm_medium' => $field('utm_medium', 120), 'utm_campaign' => $field('utm_campaign', 160),
            'utm_term' => $field('utm_term', 160), 'gclid' => $field('gclid', 200), 'fbclid' => $field('fbclid', 200),
        ], fn (string $v): bool => $v !== '');
        $message = $field('message', 4000);
        $data = [
            'name' => $field('name'), 'company' => $field('company') !== '' ? $field('company') : $field('clinic'), 'city' => $field('city', 120), 'branch' => $field('branch', 120),
            'phone' => $field('phone', 40), 'email' => $field('email'), 'website' => $field('website'),
            'heard_from' => $this->heardFrom($field('heard_from', 60), $origin), 'source' => $source,
        ];
        if (SalesLeads::phoneKey($data['phone']) === null && SalesLeads::email($data['email']) === null) {
            throw ValidationException::withMessages(['phone' => 'Telefon ya da e-posta gerekli.']);
        }
        $lead = $this->leads->quickAdd($data);
        $known = $lead->wasRecentlyCreated === false;
        $lead->forceFill(['origin' => array_merge((array) $lead->origin, $origin, ['at' => now()->toIso8601String()]), 'last_incoming_at' => now()]);
        if ($known && ! $lead->isOpen() && $lead->stage === 'lost') {
            // A closed card that fills a form again is open again.
            $lead->forceFill(['stage' => 'new', 'lost_at' => null, 'lost_reason' => null]);
        }
        if ($lead->isOpen()) {
            // A form is answered now, whatever the card's follow-up rhythm said.
            [$lead->next_step, $lead->next_step_at] = ['Formu yanıtla', CarbonImmutable::now('Europe/Istanbul')];
        }
        $lead->save();
        $summary = trim(implode(' · ', array_filter([$origin['service'] ?? null, $origin['page'] ?? null])));
        $this->leads->event($lead, 'message_in', $message !== '' ? $message : 'Form gönderildi.', meta: ['form' => true, 'origin' => $origin],
            fingerprint: 'form:'.substr(hash('sha256', ($data['phone'] ?: $data['email']).'|'.$message.'|'.now()->format('YmdH')), 0, 40));
        $this->announce($lead, $known, $summary);

        return $lead;
    }

    /** Day time: pushed at once. Night: left for the 08:30 summary. */
    public function announce(SalesLead $lead, bool $known, string $summary = ''): void
    {
        if (self::quiet()) {
            return;
        }
        try {
            $this->push->send('lead-form:'.$lead->id.':'.now()->format('YmdHi'), ($known ? 'Form tekrar geldi: ' : 'Yeni form: ').$lead->title(),
                trim(($summary !== '' ? $summary.'. ' : '').'Bir saat içinde dönülen aday çok daha sık görüşmeye döner.'), 'high', route('operator.sales.lead', $lead->id), 1, true);
        } catch (Throwable $error) {
            report($error);
        }
    }

    public static function quiet(?CarbonImmutable $at = null): bool
    {
        $hour = (int) ($at ?? CarbonImmutable::now('Europe/Istanbul'))->setTimezone('Europe/Istanbul')->format('G');

        return $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL;
    }

    /** 08:30: the forms that came in the night, first thing in the morning. */
    public function morningSummary(): int
    {
        $now = CarbonImmutable::now('Europe/Istanbul');
        $since = $now->subDay()->setTime(self::QUIET_FROM, 0);
        $leads = SalesLead::query()->whereIn('source', ['form', 'meta_form'])->open()
            ->whereHas('events', fn ($q) => $q->where('kind', 'message_in')->where('happened_at', '>=', $since->utc())->where('meta->form', true))
            ->get();
        if ($leads->isEmpty()) {
            return 0;
        }
        $this->push->send('lead-form-morning:'.$now->toDateString(), 'Gece '.$leads->count().' form geldi',
            $leads->take(5)->map(fn (SalesLead $l): string => $l->title())->implode(', ').'. Önce bunlara dön.', 'high', route('operator.sales'), 20, true);

        return $leads->count();
    }

    /** "Bizi nereden duydunuz" when asked, else read from the ad click ids and utm. */
    private function heardFrom(string $given, array $origin): ?string
    {
        $given = mb_strtolower($given);
        foreach (SalesLead::HEARD_FROM as $key => $label) {
            if ($given === $key || ($given !== '' && $given === mb_strtolower($label))) {
                return $key;
            }
        }
        $utm = mb_strtolower(($origin['utm_source'] ?? '').' '.($origin['utm_medium'] ?? ''));

        return match (true) {
            isset($origin['gclid']) || (str_contains($utm, 'google') && preg_match('/cpc|ads|paid/', $utm) === 1) => 'google_ads',
            isset($origin['fbclid']) && preg_match('/cpc|paid|ads/', $utm) === 1, preg_match('/(facebook|instagram|meta|fb|ig).*(cpc|paid|ads)/', $utm) === 1 => 'meta_ads',
            str_contains($utm, 'gbp') || str_contains($utm, 'maps') => 'maps',
            str_contains($utm, 'youtube') => 'youtube',
            str_contains($utm, 'instagram') || isset($origin['fbclid']) => 'instagram',
            default => $given !== '' ? 'other' : null,
        };
    }
}
