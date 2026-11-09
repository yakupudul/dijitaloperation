<?php

namespace App\Services\Social;

use App\Jobs\Social\DraftSocialReply;
use App\Models\SalesLead;
use App\Models\SocialAccount;
use App\Models\SocialMessage;
use App\Models\SocialThread;
use App\Services\Sales\SalesLeads;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gelen kutusu (ADR-083, phase 2): reads the direct messages and the comments of the agency's own Instagram and Facebook
 * accounts every few minutes (no webhook to set up), keeps one thread per conversation / top-level comment, asks the AI
 * for a reply draft when the last word is the other person's, and sends a reply only when the Admin clicks.
 */
final class SocialInbox
{
    /** How far back a first read goes. */
    public const int FIRST_READ_DAYS = 14;

    public function __construct(private SocialGraph $graph) {}

    /** @return array{threads: int, messages: int, errors: list<string>} */
    public function sync(): array
    {
        $lock = Cache::lock('social-inbox-sync', 600);
        if (! $lock->get()) {
            return ['threads' => 0, 'messages' => 0, 'errors' => []];
        }
        $stats = ['threads' => 0, 'messages' => 0, 'errors' => []];
        try {
            $accounts = SocialAccount::query()->where('enabled', true)
                ->whereIn('platform', [SocialAccount::INSTAGRAM, SocialAccount::FACEBOOK])->get();
            foreach ($accounts as $account) {
                if ($account->isManual()) {
                    continue;
                }
                $seen = null;
                foreach (['dms', 'comments'] as $part) {
                    try {
                        if ($part === 'dms') {
                            $seen = $this->syncDms($account, $stats);
                        } else {
                            $this->syncComments($account, $stats);
                        }
                    } catch (SocialGraphException $e) {
                        $stats['errors'][] = $account->platformLabel().' '.($part === 'dms' ? 'mesajlar' : 'yorumlar').': '.$e->getMessage();
                    } catch (Throwable $e) {
                        Log::warning('Social inbox sync failed.', ['account' => $account->id, 'part' => $part, 'error' => $e->getMessage()]);
                        $stats['errors'][] = $account->platformLabel().': okunamadı.';
                    }
                }
                $meta = (array) ($account->meta ?? []);
                $meta['inbox_synced_at'] = now()->toIso8601String();
                // How many DM conversations Meta returned: 0 on Instagram usually means the account's
                // "Allow access to messages" (Connected tools) is off, which Meta does not report as an error.
                $meta['inbox_dm_seen'] = $seen;
                $meta['inbox_token_scopes'] = $seen === 0 ? $this->tokenScopes((string) $account->access_token) : null;
                $meta['inbox_errors'] = array_values(array_filter($stats['errors'], fn (string $e): bool => str_starts_with($e, $account->platformLabel())));
                $account->forceFill(['meta' => $meta])->save();
            }
        } finally {
            $lock->release();
        }

        return $stats;
    }

    /** @param  array{threads: int, messages: int, errors: list<string>}  $stats */
    private function syncDms(SocialAccount $account, array &$stats): ?int
    {
        $pageId = $account->platform === SocialAccount::INSTAGRAM ? (string) $account->page_id : (string) $account->external_id;
        if ($pageId === '') {
            return null;
        }
        $query = ['fields' => 'id,updated_time,participants,messages.limit(25){id,message,from,created_time}', 'limit' => 25];
        if ($account->platform === SocialAccount::INSTAGRAM) {
            $query['platform'] = 'instagram';
        }
        $data = $this->graph->get($pageId.'/conversations', (string) $account->access_token, $query);
        $since = now()->subDays(self::FIRST_READ_DAYS);
        $conversations = (array) ($data['data'] ?? []);
        foreach ($conversations as $conversation) {
            $updated = isset($conversation['updated_time']) ? Carbon::parse($conversation['updated_time']) : null;
            if ($updated !== null && $updated->lt($since) && ! SocialThread::query()->where('social_account_id', $account->id)->where('external_id', (string) $conversation['id'])->exists()) {
                continue;
            }
            $other = collect((array) data_get($conversation, 'participants.data', []))
                ->first(fn ($p): bool => ! $this->isOwn($account, (array) $p));
            $messages = [];
            foreach ((array) data_get($conversation, 'messages.data', []) as $m) {
                $from = (array) ($m['from'] ?? []);
                $messages[] = [
                    'id' => (string) ($m['id'] ?? ''), 'text' => (string) ($m['message'] ?? ''),
                    'dir' => $this->isOwn($account, $from) ? 'out' : 'in',
                    'author' => (string) ($from['username'] ?? $from['name'] ?? ''), 'at' => (string) ($m['created_time'] ?? ''),
                ];
            }
            $this->store($account, SocialThread::DM, (string) $conversation['id'], [
                'author_id' => isset($other['id']) ? (string) $other['id'] : null,
                'author_name' => isset($other) ? (string) ($other['username'] ?? $other['name'] ?? '') : null,
            ], $messages, $stats);
        }

        return count($conversations);
    }

    /**
     * The permissions Meta really gave the account's token (debug_token with the app token), read only when no DM
     * conversation came back, so the operator sees whether the messaging permission is missing. Null when unknown.
     *
     * @return list<string>|null
     */
    private function tokenScopes(string $token): ?array
    {
        $appId = (string) $this->graph->appId();
        $secret = (string) $this->graph->appSecret();
        if ($token === '' || $appId === '' || $secret === '') {
            return null;
        }
        try {
            $data = (array) ($this->graph->get('debug_token', $appId.'|'.$secret, ['input_token' => $token])['data'] ?? []);
        } catch (Throwable) {
            return null;
        }
        $scopes = array_values(array_filter((array) ($data['scopes'] ?? []), 'is_string'));

        return $scopes === [] ? null : $scopes;
    }

    /** @param  array{threads: int, messages: int, errors: list<string>}  $stats */
    private function syncComments(SocialAccount $account, array &$stats): void
    {
        $token = (string) $account->access_token;
        if ($account->platform === SocialAccount::INSTAGRAM) {
            $data = $this->graph->get($account->external_id.'/media', $token, [
                'fields' => 'id,permalink,caption,timestamp,comments.limit(50){id,text,username,timestamp,from,replies{id,text,username,timestamp,from}}',
                'limit' => 15,
            ]);
            foreach ((array) ($data['data'] ?? []) as $media) {
                foreach ((array) data_get($media, 'comments.data', []) as $comment) {
                    $messages = [$this->igComment($account, (array) $comment)];
                    foreach ((array) data_get($comment, 'replies.data', []) as $reply) {
                        $messages[] = $this->igComment($account, (array) $reply);
                    }
                    if ($messages[0]['dir'] === 'out') {
                        continue; // our own top-level comment
                    }
                    $this->store($account, SocialThread::COMMENT, (string) $comment['id'], [
                        'author_id' => (string) data_get($comment, 'from.id', ''), 'author_name' => (string) ($comment['username'] ?? ''),
                        'post_external_id' => (string) $media['id'], 'post_url' => $media['permalink'] ?? null,
                        'post_caption' => mb_substr((string) ($media['caption'] ?? ''), 0, 480),
                    ], $messages, $stats);
                }
            }

            return;
        }
        $data = $this->graph->get($account->external_id.'/posts', $token, [
            'fields' => 'id,permalink_url,message,created_time,comments.limit(50){id,message,from,created_time,comments{id,message,from,created_time}}',
            'limit' => 15,
        ]);
        foreach ((array) ($data['data'] ?? []) as $post) {
            foreach ((array) data_get($post, 'comments.data', []) as $comment) {
                $messages = [$this->fbComment($account, (array) $comment)];
                foreach ((array) data_get($comment, 'comments.data', []) as $reply) {
                    $messages[] = $this->fbComment($account, (array) $reply);
                }
                if ($messages[0]['dir'] === 'out') {
                    continue;
                }
                $this->store($account, SocialThread::COMMENT, (string) $comment['id'], [
                    'author_id' => (string) data_get($comment, 'from.id', ''), 'author_name' => (string) data_get($comment, 'from.name', ''),
                    'post_external_id' => (string) $post['id'], 'post_url' => $post['permalink_url'] ?? null,
                    'post_caption' => mb_substr((string) ($post['message'] ?? ''), 0, 480),
                ], $messages, $stats);
            }
        }
    }

    /** @return array{id: string, text: string, dir: string, author: string, at: string} */
    private function igComment(SocialAccount $account, array $c): array
    {
        $own = strcasecmp((string) ($c['username'] ?? ''), (string) $account->username) === 0
            || (string) data_get($c, 'from.id', '') === (string) $account->external_id;

        return ['id' => (string) ($c['id'] ?? ''), 'text' => (string) ($c['text'] ?? ''), 'dir' => $own ? 'out' : 'in',
            'author' => (string) ($c['username'] ?? ''), 'at' => (string) ($c['timestamp'] ?? '')];
    }

    /** @return array{id: string, text: string, dir: string, author: string, at: string} */
    private function fbComment(SocialAccount $account, array $c): array
    {
        $own = (string) data_get($c, 'from.id', '') === (string) $account->external_id;

        return ['id' => (string) ($c['id'] ?? ''), 'text' => (string) ($c['message'] ?? ''), 'dir' => $own ? 'out' : 'in',
            'author' => (string) data_get($c, 'from.name', ''), 'at' => (string) ($c['created_time'] ?? '')];
    }

    /** @param  array<string, mixed>  $p */
    private function isOwn(SocialAccount $account, array $p): bool
    {
        $id = (string) ($p['id'] ?? '');
        if ($id !== '' && in_array($id, array_filter([(string) $account->external_id, (string) $account->page_id]), true)) {
            return true;
        }
        $username = (string) ($p['username'] ?? '');

        return $username !== '' && $account->username !== null && strcasecmp($username, (string) $account->username) === 0;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{id: string, text: string, dir: string, author: string, at: string}>  $messages
     * @param  array{threads: int, messages: int, errors: list<string>}  $stats
     */
    private function store(SocialAccount $account, string $kind, string $externalId, array $attributes, array $messages, array &$stats): void
    {
        // A sync that loaded its accounts before one was switched off (the 2026-11-08 clean-up ran mid-sync) must not
        // write that account's threads back.
        if ($externalId === '' || ! SocialAccount::query()->whereKey($account->id)->where('enabled', true)->exists()) {
            return;
        }
        $messages = array_values(array_filter($messages, fn (array $m): bool => $m['id'] !== '' && $m['at'] !== ''));
        if ($messages === []) {
            return;
        }
        usort($messages, fn (array $a, array $b): int => strcmp(Carbon::parse($a['at'])->toIso8601String(), Carbon::parse($b['at'])->toIso8601String()));
        $thread = SocialThread::query()->firstOrNew(['social_account_id' => $account->id, 'kind' => $kind, 'external_id' => $externalId]);
        $isNew = ! $thread->exists;
        $thread->fill(array_filter($attributes, fn ($v): bool => $v !== null && $v !== ''));
        if ($isNew) {
            $thread->status = SocialThread::OPEN;
            $thread->last_at = now();
            $thread->save();
            $stats['threads']++;
        }
        $added = false;
        foreach ($messages as $m) {
            $row = SocialMessage::query()->firstOrCreate(
                ['social_thread_id' => $thread->id, 'external_id' => mb_substr($m['id'], 0, 191)],
                ['direction' => $m['dir'], 'author_name' => $m['author'] !== '' ? mb_substr($m['author'], 0, 250) : null,
                    'body' => $m['text'], 'sent_at' => Carbon::parse($m['at'])],
            );
            if ($row->wasRecentlyCreated) {
                $added = true;
                $stats['messages']++;
            }
        }
        $last = end($messages);
        $lastIn = collect($messages)->where('dir', 'in')->last();
        $thread->last_text = mb_substr($last['text'], 0, 2000);
        $thread->last_direction = $last['dir'];
        $thread->last_at = Carbon::parse($last['at']);
        if ($lastIn !== null) {
            $thread->last_in_at = Carbon::parse($lastIn['at']);
        }
        if ($added) {
            if ($last['dir'] === 'in' && $thread->status !== SocialThread::IGNORED) {
                $thread->status = SocialThread::OPEN;
                $thread->send_error = null;
                $thread->snoozed_until = null;
            } elseif ($last['dir'] === 'out') {
                $thread->status = SocialThread::ANSWERED; // answered on the phone or from here
                $thread->draft_status = null;
            }
        }
        $thread->save();
        // A new word from the other person: a fresh draft. An old thread first read is not drafted (it is history).
        if ($added && $last['dir'] === 'in' && $thread->status === SocialThread::OPEN
            && $thread->last_in_at !== null && $thread->last_in_at->gt(now()->subDays(3))) {
            self::queueDraft($thread);
        }
    }

    public static function queueDraft(SocialThread $thread): void
    {
        $thread->forceFill(['draft_status' => 'queued', 'draft_note' => null])->save();
        DraftSocialReply::dispatch((int) $thread->id);
    }

    /**
     * Sends the thread's draft (the Admin's click). DM: Instagram / Messenger send API inside the 24-hour window;
     * comment: a public reply under the person's comment.
     */
    public function send(SocialThread $thread, ?string $text = null): void
    {
        $text = trim((string) ($text ?? $thread->draft));
        if ($text === '') {
            throw new SocialGraphException('Gönderilecek metin boş.', 'provider');
        }
        if ($thread->account === null || ! $thread->account->enabled) {
            throw new SocialGraphException('Bu hesap kapalı; Moximu adına cevap gönderilmez.', 'provider');
        }
        if (! $thread->canReply()) {
            throw new SocialGraphException('24 saat geçti; Instagram bu kişiye ancak uygulamadan yazmanıza izin veriyor.', 'provider');
        }
        $account = $thread->account;
        $token = (string) $account->access_token;
        if ($thread->kind === SocialThread::DM) {
            $pageId = $account->platform === SocialAccount::INSTAGRAM ? (string) $account->page_id : (string) $account->external_id;
            $result = $this->graph->post($pageId.'/messages', $token, [
                'recipient' => json_encode(['id' => (string) $thread->author_id]),
                'message' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
                'messaging_type' => 'RESPONSE',
            ]);
            $externalId = (string) ($result['message_id'] ?? 'sent-'.now()->timestamp);
        } else {
            $path = $account->platform === SocialAccount::INSTAGRAM ? $thread->external_id.'/replies' : $thread->external_id.'/comments';
            $result = $this->graph->post($path, $token, ['message' => $text]);
            $externalId = (string) ($result['id'] ?? 'sent-'.now()->timestamp);
        }
        SocialMessage::query()->firstOrCreate(['social_thread_id' => $thread->id, 'external_id' => mb_substr($externalId, 0, 191)],
            ['direction' => 'out', 'author_name' => $account->username ?: $account->name, 'body' => $text, 'sent_at' => now()]);
        $thread->forceFill([
            'status' => SocialThread::ANSWERED, 'last_direction' => 'out', 'last_text' => mb_substr($text, 0, 2000), 'last_at' => now(),
            'draft' => null, 'draft_status' => null, 'draft_note' => null, 'send_error' => null,
        ])->save();
        if ($thread->sales_lead_id !== null && ($lead = SalesLead::query()->find($thread->sales_lead_id)) !== null) {
            app(SalesLeads::class)->event($lead, 'message_out', $text, meta: ['channel' => $account->platform]);
        }
    }

    /** "Hepsini gönder": every ready draft that may still be sent. @return array{sent: int, failed: int} */
    public function sendAllReady(): array
    {
        $sent = 0;
        $failed = 0;
        SocialThread::query()->onEnabledAccount()->with('account')->where('status', SocialThread::OPEN)->where('draft_status', 'ready')
            ->whereNotNull('draft')->orderBy('last_in_at')->each(function (SocialThread $thread) use (&$sent, &$failed): void {
                try {
                    $this->send($thread);
                    $sent++;
                } catch (Throwable $e) {
                    $thread->forceFill(['send_error' => mb_substr($e->getMessage(), 0, 480)])->save();
                    $failed++;
                }
            });

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** The person asked about the service: a card in Satış › Adaylar (once per thread). */
    public function toLead(SocialThread $thread): SalesLead
    {
        if ($thread->sales_lead_id !== null && ($lead = SalesLead::query()->find($thread->sales_lead_id)) !== null) {
            return $lead;
        }
        $platform = $thread->account?->platform ?? SocialAccount::INSTAGRAM;
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd([
            'name' => $thread->author_name ?: 'Sosyal medya',
            'source' => $platform,
            'note' => $thread->account?->platformLabel().' '.mb_strtolower($thread->kindLabel()).': '.mb_substr((string) $thread->last_text, 0, 1500),
        ]);
        foreach ($thread->messages()->orderBy('sent_at')->limit(40)->get() as $m) {
            $leads->event($lead, $m->direction === 'in' ? 'message_in' : 'message_out', (string) $m->body, Carbon::instance($m->sent_at),
                ['channel' => $platform], 'social:'.$m->id);
        }
        $thread->forceFill(['sales_lead_id' => $lead->id])->save();

        return $lead;
    }
}
