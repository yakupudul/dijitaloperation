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
    public function receive(array $input, string $source = 'web_form'): array
    {
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
        $spam = filled($input['website_hp'] ?? null) || ($phone === null && $email === null);
        $utm = array_filter(array_intersect_key($input, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'])), static fn ($v): bool => is_scalar($v) && $v !== '');
        $key = WhatsAppContactLinker::key($phone);

        $existing = $key !== null ? DB::table('agency_leads')->where('phone_key', $key)->where('received_at', '>=', now()->subDay())->orderByDesc('id')->first() : null;
        if ($existing !== null && ! $spam) {
            DB::table('agency_leads')->where('id', $existing->id)->update([
                'message' => mb_substr(trim(((string) $existing->message)."\n---\n".($message ?? '')), 0, 5000),
                'updated_at' => now(),
            ]);

            return ['id' => (int) $existing->id, 'duplicate' => true, 'spam' => false];
        }

        $id = (int) DB::table('agency_leads')->insertGetId([
            'name' => $name !== null ? mb_substr($name, 0, 160) : null,
            'company' => $company !== null ? mb_substr($company, 0, 160) : null,
            'phone' => $phone !== null ? mb_substr($phone, 0, 40) : null,
            'phone_key' => $key,
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 190) : null,
            'message' => $message !== null ? mb_substr($message, 0, 5000) : null,
            'source' => $source,
            'page_url' => ($page = $pick(['page', 'page_url', 'sayfa'])) !== null ? mb_substr($page, 0, 500) : null,
            'utm' => $utm !== [] ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null,
            'status' => $spam ? 'spam' : 'new',
            'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $spam) {
            $this->push->send('lead:'.$id, 'Yeni talep: '.($company ?? $name ?? $phone ?? 'isimsiz'), mb_substr(trim(($phone ?? $email ?? '').' '.($message ?? '')), 0, 200), 'high', route('operator.leads'), 1);
        }

        return ['id' => $id, 'duplicate' => false, 'spam' => $spam];
    }

    public function convert(int $leadId, ?User $actor = null): Prospect
    {
        $lead = DB::table('agency_leads')->find($leadId) ?? abort(404);
        if ($lead->prospect_id !== null && ($prospect = Prospect::query()->find($lead->prospect_id)) !== null) {
            return $prospect;
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
}
