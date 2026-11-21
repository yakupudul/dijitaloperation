<?php

namespace App\Panel\Services;

use App\Panel\Jobs\ProcessPanelChannelReceipt;
use App\Panel\Models\PanelChannel;
use App\Panel\Models\PanelConversation;
use App\Panel\Models\PanelMessage;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelWebhookReceipt;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PhoneKey;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppGraph;
use App\Services\WhatsApp\WhatsAppGraphException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A brand's own channels (WhatsApp number, Instagram account, Facebook page, Meta lead forms). What Meta sends for a
 * connected channel becomes a conversation of that brand; the first message of a stranger opens a lead. Nothing is
 * ever sent from here: the panel only reads.
 */
final class PanelChannels
{
    /** A person who writes again after this many days of silence is a new request. */
    public const int NEW_REQUEST_DAYS = 7;

    private const array TYPE_LABELS = [
        'image' => 'Fotoğraf', 'video' => 'Video', 'audio' => 'Ses kaydı', 'voice' => 'Ses kaydı', 'document' => 'Belge', 'sticker' => 'Çıkartma',
        'location' => 'Konum', 'contacts' => 'Kişi kartı', 'file' => 'Dosya', 'share' => 'Paylaşım', 'story_mention' => 'Hikâyede bahsetti', 'reel' => 'Reels', 'ig_reel' => 'Reels',
    ];

    public function __construct(
        private readonly PanelContext $context,
        private readonly PanelLeads $leads,
        private readonly PanelNotifier $notifier,
        private readonly WhatsAppGraph $graph,
        private readonly WhatsAppConnection $connection,
    ) {}

    /** Binds a channel to a brand. The same number, account or page cannot feed two brands. */
    public function connect(int $brandId, string $channel, string $externalId, ?string $accountId, ?string $label, ?string $token, ?int $operatorId): PanelChannel
    {
        if (! isset(PanelChannel::KINDS[$channel])) {
            throw ValidationException::withMessages(['channel' => 'Bilinmeyen kanal.']);
        }
        $externalId = trim($externalId);
        if (preg_match('/^[0-9]{5,32}$/', $externalId) !== 1) {
            throw ValidationException::withMessages(['external_id' => 'Kimlik yalnız rakamlardan oluşur (Meta\'daki numara).']);
        }

        return $this->context->system(function () use ($brandId, $channel, $externalId, $accountId, $label, $token, $operatorId): PanelChannel {
            $row = PanelChannel::query()->where('channel', $channel)->where('external_id', $externalId)->first();
            if ($row !== null && (int) $row->brand_id !== $brandId) {
                throw ValidationException::withMessages(['external_id' => 'Bu kimlik başka bir markaya bağlı.']);
            }
            // One connection per channel and brand: a new number replaces the old one.
            PanelChannel::query()->where('brand_id', $brandId)->where('channel', $channel)->when($row, fn ($q) => $q->whereKeyNot($row->id))->delete();
            if ($row === null) {
                $row = new PanelChannel;
                $row->brand_id = $brandId;
            }
            $row->fill([
                'channel' => $channel, 'status' => PanelChannel::CONNECTED, 'external_id' => $externalId, 'account_id' => trim((string) $accountId) ?: null, 'label' => trim((string) $label) ?: null,
                'connected_at' => now(), 'disconnected_at' => null, 'last_error' => null, 'connected_by' => $operatorId,
            ]);
            if (trim((string) $token) !== '') {
                $row->token = trim((string) $token);
            }
            $row->save();
            $this->notifier->brand($brandId, 'channel', PanelChannel::KINDS[$channel][0].' bağlandı. Yeni gelenler Gelen kutusu\'na düşecek.', route('panel.inbox', ['kanal' => $channel]), 'menu.inbox');

            return $row;
        });
    }

    /** The channel stops feeding the brand; its conversations stay. The brand's users are told. */
    public function disconnect(PanelChannel $channel, ?string $why = null): void
    {
        $this->context->system(function () use ($channel, $why): void {
            if (! $channel->isConnected()) {
                return;
            }
            $channel->forceFill(['status' => PanelChannel::DISCONNECTED, 'disconnected_at' => now(), 'last_error' => $why])->save();
            $this->notifier->brand((int) $channel->brand_id, 'channel', PanelChannel::KINDS[$channel->channel][0].' bağlantısı kesildi. Moximu ilgileniyor; yeni mesajlar şimdilik buraya düşmez.', route('panel.inbox', ['kanal' => $channel->channel]), 'menu.inbox');
        });
    }

    /** Keeps what Meta sent, when it concerns a brand's channel. Returns null when it is not for the panel. */
    public function accept(array $payload, string $raw): ?PanelWebhookReceipt
    {
        $object = (string) ($payload['object'] ?? '');
        if (! in_array($object, ['whatsapp_business_account', 'instagram', 'page'], true) || ! is_array($payload['entry'] ?? null) || ! $this->concerns($payload)) {
            return null;
        }
        $receipt = PanelWebhookReceipt::query()->firstOrCreate(['payload_hash' => hash('sha256', $raw)], ['object' => $object, 'payload' => $payload, 'status' => 'pending']);
        if ($receipt->status === 'pending') {
            try {
                ProcessPanelChannelReceipt::dispatch((int) $receipt->id);
            } catch (Throwable $exception) {
                report($exception);
                $this->process((int) $receipt->id);
            }
        }

        return $receipt;
    }

    /** Does any entry of the payload name a channel some brand has connected? */
    private function concerns(array $payload): bool
    {
        $ids = [];
        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $ids[] = (string) ($entry['id'] ?? '');
            foreach (($entry['changes'] ?? []) as $change) {
                $ids[] = (string) data_get($change, 'value.metadata.phone_number_id', '');
                $ids[] = (string) data_get($change, 'value.page_id', '');
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids !== [] && $this->context->system(fn (): bool => PanelChannel::query()->where(fn ($q) => $q->whereIn('external_id', $ids)->orWhereIn('account_id', $ids))->exists());
    }

    public function process(int $receiptId): void
    {
        $this->context->system(fn () => DB::transaction(function () use ($receiptId): void {
            $receipt = PanelWebhookReceipt::query()->lockForUpdate()->find($receiptId);
            if ($receipt === null || $receipt->status !== 'pending') {
                return;
            }
            $counts = $this->read((array) $receipt->payload);
            $receipt->update(['status' => 'completed', 'accepted_count' => $counts[0], 'ignored_count' => $counts[1], 'payload' => null, 'processed_at' => now()]);
        }, 3));
    }

    /** @return array{0: int, 1: int} accepted, ignored */
    private function read(array $payload): array
    {
        $accepted = $ignored = 0;
        $object = (string) ($payload['object'] ?? '');
        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ($object === 'whatsapp_business_account') {
                foreach (($entry['changes'] ?? []) as $change) {
                    $value = $change['value'] ?? null;
                    $field = (string) ($change['field'] ?? '');
                    $channel = is_array($value) ? $this->channel('whatsapp', (string) data_get($value, 'metadata.phone_number_id', '')) : null;
                    if ($channel === null || ! in_array($field, ['messages', 'smb_message_echoes'], true)) {
                        $ignored++;

                        continue;
                    }
                    $names = [];
                    foreach (($value['contacts'] ?? []) as $contact) {
                        if (is_array($contact) && is_string($contact['wa_id'] ?? null)) {
                            $names[$contact['wa_id']] = is_string(data_get($contact, 'profile.name')) ? mb_substr((string) data_get($contact, 'profile.name'), 0, 190) : null;
                        }
                    }
                    $echo = $field === 'smb_message_echoes';
                    foreach (($value[$echo ? 'message_echoes' : 'messages'] ?? []) as $message) {
                        if (! is_array($message)) {
                            $ignored++;

                            continue;
                        }
                        $contact = preg_replace('/\D+/', '', (string) ($echo ? ($message['to'] ?? data_get($value, 'contacts.0.wa_id', '')) : ($message['from'] ?? ''))) ?? '';
                        $type = (string) ($message['type'] ?? 'text');
                        $body = $type === 'text' ? (string) data_get($message, 'text.body', '') : (string) (data_get($message, $type.'.caption') ?: (self::TYPE_LABELS[$type] ?? ''));
                        if ($type === 'button' || $type === 'interactive') {
                            $body = (string) (data_get($message, 'button.text') ?: data_get($message, 'interactive.button_reply.title') ?: data_get($message, 'interactive.list_reply.title') ?: '');
                        }
                        $stored = $contact !== '' && $body !== '' && $this->store($channel, $contact, $names[$contact] ?? null, '+'.$contact, [
                            'id' => (string) ($message['id'] ?? ''), 'outgoing' => $echo, 'at' => (int) ($message['timestamp'] ?? 0), 'type' => $type, 'body' => $body,
                            'referral' => is_array($message['referral'] ?? null) ? $message['referral'] : null,
                        ]);
                        $stored ? $accepted++ : $ignored++;
                    }
                }

                continue;
            }
            // Instagram and Messenger speak the same shape: entry.id is the account or page, messaging[] the events.
            $kind = $object === 'instagram' ? 'instagram' : 'facebook';
            $channel = $this->channel($kind, (string) ($entry['id'] ?? ''));
            foreach (($entry['messaging'] ?? []) as $event) {
                $message = is_array($event) ? ($event['message'] ?? null) : null;
                if ($channel === null || ! is_array($message)) {
                    $ignored++;

                    continue;
                }
                $echo = (bool) ($message['is_echo'] ?? false);
                $contact = (string) data_get($event, $echo ? 'recipient.id' : 'sender.id', '');
                $attachment = (string) data_get($message, 'attachments.0.type', '');
                $body = (string) ($message['text'] ?? '') ?: (self::TYPE_LABELS[$attachment] ?? ($attachment !== '' ? 'Ek' : ''));
                $at = (int) ($event['timestamp'] ?? 0);
                $stored = $contact !== '' && $body !== '' && ! ($message['is_deleted'] ?? false) && $this->store($channel, $contact, null, null, [
                    'id' => (string) ($message['mid'] ?? ''), 'outgoing' => $echo, 'at' => $at > 9999999999 ? intdiv($at, 1000) : $at, 'type' => $attachment !== '' ? $attachment : 'text', 'body' => $body,
                    'referral' => is_array($message['referral'] ?? null) ? $message['referral'] : (is_array($event['referral'] ?? null) ? $event['referral'] : null),
                ]);
                $stored ? $accepted++ : $ignored++;
            }
            if ($object === 'page') {
                foreach (($entry['changes'] ?? []) as $change) {
                    if (($change['field'] ?? '') !== 'leadgen' || ! is_array($change['value'] ?? null)) {
                        continue;
                    }
                    $form = $this->channel('meta_form', (string) ($change['value']['page_id'] ?? $entry['id'] ?? ''));
                    $form !== null && $this->lead($form, $change['value']) ? $accepted++ : $ignored++;
                }
            }
        }

        return [$accepted, $ignored];
    }

    private function channel(string $kind, string $externalId): ?PanelChannel
    {
        return $externalId === '' ? null : PanelChannel::query()->where('channel', $kind)->where('external_id', $externalId)->where('status', PanelChannel::CONNECTED)->first();
    }

    /**
     * One message of a connected channel. Returns false when it was already stored.
     *
     * @param  array{id: string, outgoing: bool, at: int, type: string, body: string, referral: ?array<string, mixed>}  $message
     */
    private function store(PanelChannel $channel, string $contact, ?string $name, ?string $phone, array $message): bool
    {
        if ($message['id'] === '' || $message['at'] <= 0) {
            return false;
        }
        $brandId = (int) $channel->brand_id;
        $key = $phone !== null ? (PhoneKey::of($contact) ?? $contact) : mb_substr($contact, 0, 64);
        $conversation = PanelConversation::query()->where('brand_id', $brandId)->where('channel', $channel->channel)->where('contact_key', $key)->lockForUpdate()->first();
        if ($conversation === null) {
            $conversation = new PanelConversation;
            $conversation->brand_id = $brandId;
            $conversation->fill(['channel' => $channel->channel, 'contact_key' => $key, 'contact_phone' => $phone, 'origin' => 'live', 'message_count' => 0]);
        } elseif (PanelMessage::query()->where('conversation_id', $conversation->id)->where('external_id', $message['id'])->exists()) {
            return false;
        }
        if ($name !== null && $name !== '' && ! $conversation->contact_name) {
            $conversation->contact_name = $name;
        }
        if (! $conversation->contact_name && $phone === null && ! $message['outgoing']) {
            $conversation->contact_name = $this->profileName($channel, $contact);
        }
        $at = CarbonImmutable::createFromTimestamp($message['at'])->utc();
        $previousIncoming = $conversation->last_incoming_at;
        $conversation->origin = 'live';
        $conversation->message_count = (int) $conversation->message_count + 1;
        if ($conversation->last_message_at === null || $at->gte($conversation->last_message_at)) {
            $conversation->fill(['last_message_at' => $at, 'last_outgoing' => $message['outgoing'], 'last_preview' => mb_substr($message['body'], 0, 140)]);
        }
        if ($message['outgoing']) {
            // The brand answered from its own phone: nothing waits here any more.
            $conversation->handled_at = now();
        } else {
            $conversation->handled_at = null;
            $conversation->last_incoming_at = $previousIncoming !== null && $previousIncoming->gt($at) ? $previousIncoming : $at;
        }
        $conversation->save();

        $row = new PanelMessage;
        $row->brand_id = $brandId;
        $row->fill(['conversation_id' => $conversation->id, 'external_id' => mb_substr($message['id'], 0, 255), 'outgoing' => $message['outgoing'], 'type' => mb_substr($message['type'], 0, 24), 'body' => $message['body'], 'sent_at' => $at])->save();
        $channel->forceFill(['last_event_at' => now()])->save();

        if (! $message['outgoing']) {
            $this->incoming($channel, $conversation, $message, $previousIncoming);
        }

        return true;
    }

    /** A stranger's first message, or a known person writing after a long silence, is a request; otherwise a bell line. */
    private function incoming(PanelChannel $channel, PanelConversation $conversation, array $message, ?\DateTimeInterface $previousIncoming): void
    {
        $brandId = (int) $channel->brand_id;
        $person = $conversation->person_id ? PanelPerson::query()->where('brand_id', $brandId)->find($conversation->person_id) : null;
        $returning = $previousIncoming === null || CarbonImmutable::instance($previousIncoming)->lt(now()->subDays(self::NEW_REQUEST_DAYS));
        if ($person === null || $returning) {
            $ad = $this->referral($message['referral']);
            $person = $this->leads->record($brandId, [
                'name' => $person?->name ?? $conversation->contact_name ?? ($conversation->contact_phone ?: PanelChannel::KINDS[$channel->channel][0].' kullanıcısı'),
                'phone' => $conversation->contact_phone, 'person_id' => $person?->id, 'message' => $message['body'], 'meta' => array_filter(['channel' => $channel->channel, 'conversation' => $conversation->id, 'ad' => $ad ?: null]),
            ], $ad !== [] ? 'meta_ads' : $channel->channel);
            if ((int) $conversation->person_id !== (int) $person->id) {
                $conversation->forceFill(['person_id' => $person->id])->save();
            }

            return;
        }
        $person->forceFill(['last_activity_at' => now()])->save();
        // The bell rings once per burst of messages, not for every line.
        if ($previousIncoming === null || CarbonImmutable::instance($previousIncoming)->lt(now()->subMinutes(30))) {
            $this->notifier->brand($brandId, 'message', 'Yeni mesaj: '.$person->name.', '.PanelChannel::KINDS[$channel->channel][0], route('panel.inbox', ['kanal' => $channel->channel, 'kayit' => $conversation->id]), 'inbox.'.($channel->channel === 'facebook' ? 'instagram' : $channel->channel));
        }
    }

    /**
     * The ad a message came from, when Meta says so (click-to-WhatsApp, click-to-Instagram ads).
     *
     * @param  ?array<string, mixed>  $referral
     * @return array<string, string>
     */
    private function referral(?array $referral): array
    {
        if ($referral === null) {
            return [];
        }
        $isAd = in_array(strtolower((string) ($referral['source_type'] ?? $referral['source'] ?? '')), ['ad', 'ads'], true) || isset($referral['ad_id']) || isset($referral['ctwa_clid']);
        if (! $isAd) {
            return [];
        }
        $out = [];
        foreach (['source_id' => 'id', 'ad_id' => 'id', 'headline' => 'title', 'source_url' => 'url'] as $from => $to) {
            if (is_scalar($referral[$from] ?? null) && trim((string) $referral[$from]) !== '') {
                $out[$to] ??= mb_substr(trim((string) $referral[$from]), 0, 255);
            }
        }
        $title = data_get($referral, 'ads_context_data.ad_title');
        if (is_string($title) && $title !== '') {
            $out['title'] ??= mb_substr($title, 0, 255);
        }

        return $out + ['id' => ''];
    }

    /** Who is writing on Instagram or Messenger, when the channel's token lets us ask. Never fails the message. */
    private function profileName(PanelChannel $channel, string $contact): ?string
    {
        if (! $channel->token) {
            return null;
        }
        try {
            $body = $this->graph->request('GET', $contact, ['fields' => $channel->channel === 'instagram' ? 'name,username' : 'name'], (string) $channel->token, $this->appSecret());
            $name = (string) ($body['name'] ?? '') ?: (isset($body['username']) ? '@'.$body['username'] : '');

            return $name !== '' ? mb_substr($name, 0, 190) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** A filled Meta lead form: the answers are fetched with the page's token and become a lead. */
    private function lead(PanelChannel $channel, array $value): bool
    {
        $leadId = (string) ($value['leadgen_id'] ?? '');
        if ($leadId === '' || ! $channel->token) {
            return false;
        }
        try {
            $body = $this->graph->request('GET', $leadId, ['fields' => 'field_data,created_time,ad_id,ad_name,form_id,campaign_name'], (string) $channel->token, $this->appSecret());
        } catch (WhatsAppGraphException $exception) {
            // An expired or withdrawn token: the channel is cut, the brand and the agency see it.
            if (in_array((int) ($exception->details['code'] ?? 0), [190, 200, 10], true)) {
                $this->disconnect($channel, 'Meta bu sayfanın formlarını okutmuyor: yetki süresi dolmuş ya da geri alınmış.');
            }

            return false;
        }
        $data = ['name' => '', 'phone' => '', 'email' => '', 'city' => '', 'interest' => '', 'answers' => []];
        foreach ((array) ($body['field_data'] ?? []) as $field) {
            $name = strtolower((string) ($field['name'] ?? ''));
            $answer = trim((string) (is_array($field['values'] ?? null) ? implode(', ', $field['values']) : ''));
            if ($answer === '') {
                continue;
            }
            match (true) {
                in_array($name, ['full_name', 'ad_soyad', 'name', 'first_name'], true) => $data['name'] = trim($data['name'].' '.$answer),
                $name === 'last_name' => $data['name'] = trim($data['name'].' '.$answer),
                in_array($name, ['phone_number', 'phone', 'telefon'], true) => $data['phone'] = $answer,
                $name === 'email' => $data['email'] = $answer,
                in_array($name, ['city', 'sehir', 'şehir'], true) => $data['city'] = $answer,
                default => $data['answers'][] = str_replace('_', ' ', (string) ($field['name'] ?? '')).': '.$answer,
            };
        }
        if ($data['name'] === '' && $data['phone'] === '') {
            return false;
        }
        $this->leads->record((int) $channel->brand_id, [
            'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?: null, 'city' => $data['city'] ?: null,
            'message' => $data['answers'] !== [] ? implode("\n", $data['answers']) : null,
            'meta' => array_filter(['channel' => 'meta_form', 'lead' => $leadId, 'form' => (string) ($body['form_id'] ?? ''), 'ad' => array_filter(['id' => (string) ($body['ad_id'] ?? ''), 'title' => (string) ($body['ad_name'] ?? ''), 'campaign' => (string) ($body['campaign_name'] ?? '')])]),
        ], 'meta_form');
        $channel->forceFill(['last_event_at' => now()])->save();

        return true;
    }

    private function appSecret(): string
    {
        $integration = $this->connection->integration();

        return $integration ? (string) ($this->connection->secrets($integration)['app_secret'] ?? '') : '';
    }
}
