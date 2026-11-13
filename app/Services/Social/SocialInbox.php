<?php

namespace App\Services\Social;

use App\Jobs\Social\DraftSocialReply;
use App\Models\SalesLead;
use App\Models\SocialAccount;
use App\Models\SocialMessage;
use App\Models\SocialThread;
use App\Services\Inbox\InboxAlerts;
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
    /** Conversations asked from Meta per page (bigger pages with 20 messages each get "reduce the amount of data"). */
    public const int DM_PAGE = 10;

    /** At most this many pages per run (1.000 conversations); the next run goes on from the newest again. */
    public const int DM_PAGES = 100;

    /** A conversation first read whose last word is older than this is history: it owes no answer (as WhatsApp's). */
    public const int HISTORY_DAYS = 3;

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
                $errors = [];
                foreach (['dms', 'comments'] as $part) {
                    try {
                        if ($part === 'dms') {
                            $seen = $this->syncDms($account, $stats);
                        } else {
                            $this->syncComments($account, $stats);
                        }
                    } catch (SocialGraphException $e) {
                        $errors[] = $account->platformLabel().' '.($part === 'dms' ? 'mesajlar' : 'yorumlar').': '.$e->getMessage();
                    } catch (Throwable $e) {
                        Log::warning('Social inbox sync failed.', ['account' => $account->id, 'part' => $part, 'error' => $e->getMessage()]);
                        $errors[] = $account->platformLabel().': okunamadı.';
                    }
                }
                $stats['errors'] = array_merge($stats['errors'], $errors);
                $meta = (array) ($account->fresh()?->meta ?? $account->meta ?? []);
                $meta['inbox_synced_at'] = now()->toIso8601String();
                // How many DM conversations Meta returned: 0 on Instagram usually means the account's
                // "Allow access to messages" (Connected tools) is off, which Meta does not report as an error.
                $meta['inbox_dm_seen'] = $seen;
                $meta['inbox_token_scopes'] = $seen === 0 ? $this->tokenScopes((string) $account->access_token) : null;
                // Only this account's errors (a shared list showed every Facebook page's error on each page).
                $meta['inbox_errors'] = $errors;
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
        // Every conversation Meta gives, page by page (yakup 2026-11-13: "yeni eski ne kadar mesaj varsa"). The first walk
        // of an account reads them all; later runs stop at the first page whose conversations are all unchanged, since
        // Meta lists the conversation updated last first. Meta gives the text of only the 20 newest messages of a
        // conversation (older ones answer "deleted"), so 20 is the whole readable history.
        $backfilled = data_get($account->meta, 'inbox_dm_backfilled_at') !== null;
        $base = ['fields' => 'id,updated_time,participants,messages.limit(20){id,message,from,created_time}', 'limit' => self::DM_PAGE];
        if ($account->platform === SocialAccount::INSTAGRAM) {
            $base['platform'] = 'instagram';
        }
        $seen = 0;
        $after = null;
        $complete = false;
        for ($page = 0; $page < self::DM_PAGES; $page++) {
            $query = $base + array_filter(['after' => $after]);
            try {
                $data = $this->graph->get($pageId.'/conversations', (string) $account->access_token, $query);
            } catch (SocialGraphException $e) {
                // "Please reduce the amount of data you're asking for": the same page in smaller steps.
                if (! str_contains(mb_strtolower($e->getMessage()), 'reduce the amount')) {
                    throw $e;
                }
                $data = $this->graph->get($pageId.'/conversations', (string) $account->access_token, ['limit' => 3] + $query);
            }
            $conversations = (array) ($data['data'] ?? []);
            $seen += count($conversations);
            $changed = false;
            foreach ($conversations as $conversation) {
                if ($this->storeConversation($account, (array) $conversation, $stats)) {
                    $changed = true;
                }
            }
            $after = data_get($data, 'paging.next') !== null ? data_get($data, 'paging.cursors.after') : null;
            if ($conversations === [] || ! is_string($after) || $after === '') {
                $complete = true;
                break;
            }
            if ($backfilled && ! $changed) {
                break;
            }
        }
        if ($complete && ! $backfilled) {
            $meta = (array) ($account->meta ?? []);
            $meta['inbox_dm_backfilled_at'] = now()->toIso8601String();
            $account->forceFill(['meta' => $meta])->save();
        }

        return $seen;
    }

    /**
     * One conversation from Meta into its thread. True when the conversation is new or changed since it was stored.
     *
     * @param  array<string, mixed>  $conversation
     * @param  array{threads: int, messages: int, errors: list<string>}  $stats
     */
    private function storeConversation(SocialAccount $account, array $conversation, array &$stats): bool
    {
        $externalId = (string) ($conversation['id'] ?? '');
        if ($externalId === '') {
            return false;
        }
        $updated = isset($conversation['updated_time']) ? Carbon::parse((string) $conversation['updated_time']) : null;
        $stored = SocialThread::query()->where('social_account_id', $account->id)->where('kind', SocialThread::DM)->where('external_id', $externalId)->first();
        if ($stored !== null && $updated !== null && $stored->last_at !== null && $stored->last_at->gte($updated)) {
            return false;
        }
        $other = collect((array) data_get($conversation, 'participants.data', []))
            ->first(fn ($p): bool => ! $this->isOwn($account, (array) $p));
        $messages = [];
        foreach ((array) data_get($conversation, 'messages.data', []) as $m) {
            $from = (array) ($m['from'] ?? []);
            $text = trim((string) ($m['message'] ?? ''));
            $messages[] = [
                // A photo, a voice note, a shared post, a story reply or a like comes with no text.
                'id' => (string) ($m['id'] ?? ''), 'text' => $text !== '' ? $text : '(fotoğraf, ses, paylaşım veya beğeni)',
                'dir' => $this->isOwn($account, $from) ? 'out' : 'in',
                'author' => (string) ($from['username'] ?? $from['name'] ?? ''), 'at' => (string) ($m['created_time'] ?? ''),
            ];
        }
        $this->store($account, SocialThread::DM, $externalId, [
            'author_id' => isset($other['id']) ? (string) $other['id'] : null,
            'author_name' => isset($other) ? (string) ($other['username'] ?? $other['name'] ?? '') : null,
        ], $messages, $stats);

        return true;
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
            // A conversation first read with an old last word was dealt with on the phone or never will be: history.
            $thread->status = Carbon::parse(end($messages)['at'])->lt(now()->subDays(self::HISTORY_DAYS)) ? SocialThread::ANSWERED : SocialThread::OPEN;
            $thread->last_at = now();
            $thread->save();
            $stats['threads']++;
        }
        $added = false;
        $freshIn = null;
        foreach ($messages as $m) {
            $row = SocialMessage::query()->firstOrCreate(
                ['social_thread_id' => $thread->id, 'external_id' => mb_substr($m['id'], 0, 191)],
                ['direction' => $m['dir'], 'author_name' => $m['author'] !== '' ? mb_substr($m['author'], 0, 250) : null,
                    'body' => $m['text'], 'sent_at' => Carbon::parse($m['at'])],
            );
            if ($row->wasRecentlyCreated) {
                $added = true;
                $stats['messages']++;
                $freshIn = $m['dir'] === 'in' ? $row->id : $freshIn;
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
        $history = $isNew && $thread->status === SocialThread::ANSWERED;
        if ($added && ! $history) {
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
        // The phone push for a new direct message (comments stay in the inbox); InboxAlerts skips a first read's history.
        if ($freshIn !== null && $kind === SocialThread::DM) {
            try {
                app(InboxAlerts::class)->social($freshIn);
            } catch (Throwable $exception) {
                report($exception);
            }
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
