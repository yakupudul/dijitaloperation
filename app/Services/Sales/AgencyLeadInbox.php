<?php

namespace App\Services\Sales;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Models\AgencySetting;
use App\Models\Prospect;
use App\Models\User;
use App\Services\Assistant\PushNotifier;
use App\Services\Assistant\WhatsAppContactLinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ajans lead kutusu (Faz 8g): requests from the agency's own website form arrive through a secret-token webhook,
 * are de-duplicated by phone within a day, pushed to the owner's phone and turned into prospects with one click.
 * The token is stored hashed; only the last four characters are shown after it is generated.
 */
final class AgencyLeadInbox
{
    public const array STATUSES = ['new' => 'Yeni', 'contacted' => 'Arandı / yazıldı', 'converted' => 'Adaya dönüştü', 'lost' => 'Olmadı', 'spam' => 'Spam'];

    public function __construct(private readonly PushNotifier $push) {}

    /** Generate (or rotate) the webhook token; returns the plain token once. */
    public function rotateToken(): string
    {
        $token = Str::random(40);
        $settings = AgencySetting::query()->firstOrCreate([]);
        $settings->forceFill(['lead_inbox_token_hash' => hash('sha256', $token), 'lead_inbox_token_hint' => substr($token, -4)])->save();

        return $token;
    }

    public function tokenMatches(string $token): bool
    {
        $hash = AgencySetting::query()->value('lead_inbox_token_hash');

        return is_string($hash) && $hash !== '' && hash_equals($hash, hash('sha256', $token));
    }

    /**
     * @param  array<string, mixed>  $input  form or JSON fields (Turkish or English names)
     * @return array{id: int, duplicate: bool, spam: bool}
     */
    public function receive(array $input, string $source = 'web_form', ?string $externalId = null): array
    {
        // Idempotency for at-least-once sources (Meta Lead Ads): a repeat of the same external id returns the stored row.
        if ($externalId !== null) {
            $seen = DB::table('agency_leads')->where('external_id', $externalId)->first();
            if ($seen !== null) {
                return ['id' => (int) $seen->id, 'duplicate' => true, 'spam' => $seen->status === 'spam'];
            }
        }
        $pick = static function (array $keys) use ($input): ?string {
            foreach ($keys as $key) {
                $value = $input[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim(strip_tags((string) $value));
                }
            }

            return null;
        };
        $name = $pick(['name', 'ad', 'ad_soyad', 'adsoyad', 'full_name']);
        $phone = $pick(['phone', 'telefon', 'tel', 'gsm']);
        $email = $pick(['email', 'e_posta', 'eposta', 'mail']);
        $message = $pick(['message', 'mesaj', 'not', 'aciklama']);
        $company = $pick(['company', 'firma', 'sirket', 'isletme']);
        // Only a syntactically valid e-mail counts as a way to reach the lead; a malformed one is treated as "no e-mail".
        $validEmail = $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 190) : null;
        $spam = filled($input['website_hp'] ?? null) || ($phone === null && $validEmail === null);
        $utm = array_filter(array_intersect_key($input, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'])), static fn ($v): bool => is_scalar($v) && $v !== '');
        $key = WhatsAppContactLinker::key($phone);

        // A spam row keeps its phone_key too, so exclude spam rows here: a genuine same-day inquiry must never merge into (and hide inside) a spam record.
        $existing = $key !== null ? DB::table('agency_leads')->where('phone_key', $key)->where('status', '!=', 'spam')->where('received_at', '>=', now()->subDay())->orderByDesc('id')->first() : null;
        if ($existing !== null && ! $spam) {
            // Merge: append the new message and backfill any field the earlier submission was missing (a phone-only first touch, then a fuller one).
            $update = [
                'message' => mb_substr(trim(((string) $existing->message)."\n---\n".($message ?? '')), 0, 5000),
                'updated_at' => now(),
            ];
            foreach (['name' => $name !== null ? mb_substr($name, 0, 160) : null, 'company' => $company !== null ? mb_substr($company, 0, 160) : null,
                'email' => $validEmail, 'utm' => $utm !== [] ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null] as $column => $value) {
                if ($value !== null && blank($existing->{$column} ?? null)) {
                    $update[$column] = $value;
                }
            }
            DB::table('agency_leads')->where('id', $existing->id)->update($update);
            // The UI promises a phone notification for every new inquiry, so a same-day follow-up must notify too (deduped per day).
            $this->push->send('lead:'.$existing->id.':'.now()->format('Ymd'), 'Aynı talep tekrar geldi: '.($company ?? $name ?? $phone ?? 'isimsiz'),
                mb_substr(trim(($phone ?? $validEmail ?? '').' '.($message ?? '')), 0, 200), 'high', route('operator.leads'), 1);

            return ['id' => (int) $existing->id, 'duplicate' => true, 'spam' => false];
        }

        $id = (int) DB::table('agency_leads')->insertGetId([
            'name' => $name !== null ? mb_substr($name, 0, 160) : null,
            'company' => $company !== null ? mb_substr($company, 0, 160) : null,
            'phone' => $phone !== null ? mb_substr($phone, 0, 40) : null,
            'phone_key' => $key,
            'email' => $validEmail,
            'message' => $message !== null ? mb_substr($message, 0, 5000) : null,
            'source' => $source,
            'external_id' => $externalId,
            'page_url' => ($page = $pick(['page', 'page_url', 'sayfa'])) !== null ? mb_substr($page, 0, 500) : null,
            'utm' => $utm !== [] ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null,
            'status' => $spam ? 'spam' : 'new',
            'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $spam) {
            $this->push->send('lead:'.$id, 'Yeni talep: '.($company ?? $name ?? $phone ?? 'isimsiz'), mb_substr(trim(($phone ?? $validEmail ?? '').' '.($message ?? '')), 0, 200), 'high', route('operator.leads'), 1);
        }

        return ['id' => $id, 'duplicate' => false, 'spam' => $spam];
    }

    public function convert(int $leadId, ?User $actor = null): Prospect
    {
        $lead = DB::table('agency_leads')->find($leadId) ?? abort(404);
        if ($lead->prospect_id !== null && ($prospect = Prospect::query()->find($lead->prospect_id)) !== null) {
            return $prospect;
        }
        // Reuse an open prospect for the same contact instead of spawning a duplicate (same person re-inquiring).
        $existing = $this->openProspectFor($lead);
        if ($existing !== null) {
            DB::table('agency_leads')->where('id', $leadId)->update(['status' => 'converted', 'prospect_id' => $existing->id, 'handled_by' => $actor?->id, 'updated_at' => now()]);

            return $existing;
        }
        $source = match ($lead->source) {
            'whatsapp' => ProspectSource::WhatsApp,
            'phone' => ProspectSource::Phone,
            'manual' => ProspectSource::Manual,
            default => ProspectSource::Website,
        };
        $prospect = Prospect::query()->create([
            'company_name' => $lead->company ?: ($lead->name ?: ($lead->phone ?: 'Web talebi')),
            'contact_name' => $lead->name,
            'contact_phone' => $lead->phone,
            'contact_email' => $lead->email,
            'inquiry' => $lead->message,
            'source' => $source,
            'status' => ProspectStatus::New,
            'identity_status' => ProspectIdentityStatus::Unknown,
            'owner_user_id' => $actor?->id,
            'next_follow_up_on' => now()->addDay()->toDateString(),
            'next_step' => 'Talebe dönüş yap',
        ]);
        DB::table('agency_leads')->where('id', $leadId)->update(['status' => 'converted', 'prospect_id' => $prospect->id, 'handled_by' => $actor?->id, 'updated_at' => now()]);

        return $prospect;
    }

    /** An open (not won/lost) prospect that matches this lead's phone or e-mail, so a re-inquiry links instead of duplicating. */
    private function openProspectFor(object $lead): ?Prospect
    {
        $key = WhatsAppContactLinker::key($lead->phone);
        $email = is_string($lead->email) && $lead->email !== '' ? mb_strtolower($lead->email) : null;
        if ($key === null && $email === null) {
            return null;
        }

        // Phone is matched in PHP (normalized key) to stay portable across SQLite/PostgreSQL; the agency's own pipeline is small.
        return Prospect::query()
            ->whereNotIn('status', [ProspectStatus::Won, ProspectStatus::Lost])
            ->where(fn ($q) => $q->when($email !== null, fn ($q) => $q->orWhereRaw('lower(contact_email) = ?', [$email]))
                ->when($key !== null, fn ($q) => $q->orWhereNotNull('contact_phone')))
            ->orderByDesc('id')->get(['id', 'contact_phone', 'contact_email'])
            ->first(fn (Prospect $p): bool => ($email !== null && mb_strtolower((string) $p->contact_email) === $email)
                || ($key !== null && WhatsAppContactLinker::key($p->contact_phone) === $key));
    }
}
