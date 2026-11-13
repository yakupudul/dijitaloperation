<?php

namespace App\Services\Finance;

use App\Models\FinanceDocument;
use App\Services\Integrations\Google\GoogleWorkspace;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gmail'den faturalar (yakup 2026-11-12: "e-posta adresime bağlayayım, gelen tüm faturaları sisteme çekebileyim"). The
 * mailbox is only read: mails that carry a PDF, XML or ZIP and speak of an invoice, a receipt or a payment. Each
 * attachment goes to FinanceDocuments, which keeps what is a finance document and only marks the rest. The first runs
 * walk the whole mailbox back to its first mail a page at a time (yakup 2026-11-13: "adıma olan tüm faturaları
 * istiyorum"); after that each run looks at the last days. Nothing is read
 * twice: a message is known by its id.
 */
final class GmailInvoices
{
    private const string API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /** Mails that are about money and carry a file. */
    private const string QUERY = 'has:attachment (filename:pdf OR filename:xml OR filename:zip) '
        .'(fatura OR "e-arşiv" OR "e-fatura" OR "e-arsiv" OR invoice OR receipt OR makbuz OR dekont OR ödeme OR payment OR abonelik OR subscription OR billing OR sipariş)';

    /** Messages read in one run. */
    public const int BATCH = 12;

    public function __construct(private readonly GoogleWorkspace $workspace, private readonly FinanceDocuments $documents,
        private readonly FinanceSettings $settings, private readonly FinanceReader $reader) {}

    public function linked(): bool
    {
        return rescue(fn (): bool => $this->workspace->has(GoogleWorkspace::GMAIL), false, false);
    }

    public function connectUrl(): ?string
    {
        return rescue(fn (): ?string => $this->workspace->gmailConnectUrl(), null, false);
    }

    /** @return array{linked: bool, synced_at: ?string, backfill_done: bool, last_error: ?string, found: int, not_mine: int, waiting: int} */
    public function state(): array
    {
        $kept = (array) $this->settings->get('gmail');

        return ['linked' => $this->linked(), 'synced_at' => $kept['synced_at'] ?? null, 'backfill_done' => (bool) ($kept['backfill_done'] ?? false),
            'last_error' => $kept['last_error'] ?? null, 'found' => FinanceDocument::query()->where('source', 'gmail')->where('status', '!=', 'ignored')->count(),
            'not_mine' => FinanceDocument::query()->where('source', 'gmail')->where('meta->reason', FinanceDocuments::NOT_MINE)->count(),
            'waiting' => FinanceDocument::query()->where('source', 'gmail')->where('meta->reason', FinanceDocuments::UNVERIFIED)->count()];
    }

    /**
     * One run. Returns what it did, for the screen and the log.
     *
     * @return array{ok: bool, messages: int, documents: int, more: bool, error: ?string}
     */
    public function sync(int $batch = self::BATCH): array
    {
        if (! $this->linked()) {
            return ['ok' => false, 'messages' => 0, 'documents' => 0, 'more' => false, 'error' => 'Gmail bağlı değil.'];
        }
        $lock = Cache::lock('finance-gmail-sync', 600);
        if (! $lock->get()) {
            return ['ok' => false, 'messages' => 0, 'documents' => 0, 'more' => true, 'error' => 'Şu an zaten okunuyor.'];
        }
        $kept = (array) $this->settings->get('gmail');
        $messages = 0;
        $found = 0;
        $more = false;
        $waiting = false;
        try {
            // Papers taken before the rule "only in our own name" are looked at first.
            $old = $this->documents->recheckMail();
            $more = $old['left'] > 0;
            $held = $this->reader->held();
            if (! ($kept['all_history'] ?? false)) {
                // The first walk went back only to 1 January; the whole mailbox is walked once more (known mails are skipped).
                $kept = ['from' => 'all', 'backfill_done' => false, 'backfill_token' => null, 'all_history' => true] + $kept;
            }
            $backfill = ! ($kept['backfill_done'] ?? false);
            $from = (string) ($kept['from'] ?? 'all');
            $query = self::QUERY.($backfill ? ($from !== 'all' ? ' after:'.$from : '') : ' newer_than:5d');
            $response = $this->request()->get(self::API.'/messages', array_filter(['q' => $query, 'maxResults' => $batch, 'pageToken' => $backfill ? ($kept['backfill_token'] ?? null) : null]));
            if (! $response->successful()) {
                throw new \RuntimeException($response->status() === 403 ? 'Gmail izni yok ya da Gmail API kapalı (HTTP 403).' : 'Gmail okunamadı (HTTP '.$response->status().').');
            }
            foreach ((array) $response->json('messages', []) as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '' || $this->seen($id)) {
                    continue;
                }
                $kept_ = $this->message($id);
                if ($kept_ === null) {
                    $more = $waiting = true; // a PDF waits for the Claude API; the message is read again later

                    continue;
                }
                $messages++;
                $found += $kept_;
                Cache::put('finance.gmail.seen.'.$id, 1, now()->addDays(40));
            }
            $next = $response->json('nextPageToken');
            if ($backfill) {
                $kept['from'] = $from;
                // A page whose PDFs wait for the API is walked again; else on to the next page, and done when there is none.
                if (! $waiting) {
                    $kept['backfill_token'] = is_string($next) && $next !== '' ? $next : null;
                    $kept['backfill_done'] = $kept['backfill_token'] === null;
                }
                $more = $more || ! $kept['backfill_done'];
            }
            $kept['synced_at'] = now()->toIso8601String();
            // A paper the rule could not read waits out of sight for a Claude subscription round; that is no error.
            $kept['last_error'] = null;
            $this->settings->put(['gmail' => $kept]);

            return ['ok' => true, 'messages' => $messages, 'documents' => $found, 'more' => $more, 'error' => $kept['last_error']];
        } catch (Throwable $error) {
            Log::warning('Finance: Gmail sync failed.', ['error' => $error->getMessage()]);
            $kept['last_error'] = mb_substr($error->getMessage(), 0, 200);
            $this->settings->put(['gmail' => $kept]);

            return ['ok' => false, 'messages' => $messages, 'documents' => $found, 'more' => false, 'error' => $kept['last_error']];
        } finally {
            $lock->release();
        }
    }

    /** Reads the mailbox again from its first mail (or the given day): for "baştan oku". Known messages are still skipped. */
    public function restart(?string $from = null): void
    {
        $day = $from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? str_replace('-', '/', $from) : 'all';
        $this->settings->put(['gmail' => ['from' => $day, 'backfill_done' => false, 'backfill_token' => null, 'all_history' => true] + (array) $this->settings->get('gmail')]);
    }

    private function seen(string $id): bool
    {
        return Cache::has('finance.gmail.seen.'.$id) || FinanceDocument::query()->where('external_id', 'like', 'gmail:'.$id.':%')->exists();
    }

    /** @return ?int documents kept from the message; null when it must be read again later */
    private function message(string $id): ?int
    {
        $response = $this->request()->get(self::API.'/messages/'.rawurlencode($id), ['format' => 'full']);
        if (! $response->successful()) {
            throw new \RuntimeException('Gmail mesajı okunamadı (HTTP '.$response->status().').');
        }
        $headers = collect((array) $response->json('payload.headers', []))->mapWithKeys(fn ($h): array => [strtolower((string) ($h['name'] ?? '')) => (string) ($h['value'] ?? '')]);
        $parts = [];
        $walk = function (array $part) use (&$walk, &$parts): void {
            $name = (string) ($part['filename'] ?? '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name !== '' && in_array($ext, ['pdf', 'xml', 'zip'], true) && filled($part['body']['attachmentId'] ?? null) && (int) ($part['body']['size'] ?? 0) <= FinanceDocuments::MAX_BYTES) {
                $parts[] = ['name' => $name, 'mime' => (string) ($part['mimeType'] ?? ''), 'attachment' => (string) $part['body']['attachmentId']];
            }
            foreach ((array) ($part['parts'] ?? []) as $child) {
                is_array($child) && $walk($child);
            }
        };
        $walk((array) $response->json('payload', []));
        $parts = array_slice($parts, 0, 8);
        if ($parts === []) {
            return 0;
        }
        $files = [];
        foreach ($parts as $part) {
            $data = $this->request()->get(self::API.'/messages/'.rawurlencode($id).'/attachments/'.rawurlencode($part['attachment']))->json('data');
            $bytes = is_string($data) ? base64_decode(strtr($data, '-_', '+/'), true) : false;
            if (is_string($bytes) && $bytes !== '') {
                $files[] = ['bytes' => $bytes, 'name' => $part['name'], 'mime' => $part['mime']];
            }
        }
        $count = 0;
        // Files that share a name are one document (fatura.xml + fatura.pdf); the rest are read one by one.
        foreach (collect($files)->groupBy(fn (array $f): string => pathinfo($f['name'], PATHINFO_FILENAME)) as $stem => $set) {
            try {
                $docs = $this->documents->keepSet($set->all(), [
                    'source' => 'gmail', 'external_id' => 'gmail:'.$id.':'.Str::limit(Str::slug((string) $stem), 60, ''),
                    'from' => $headers->get('from'), 'subject' => $headers->get('subject'),
                ]);
                $count += collect($docs)->where('status', '!=', 'ignored')->count();
            } catch (UnreadDocument) {
                // Three tries; after that the mail is left behind so the walk does not stand on one file.
                $tries = (int) Cache::get('finance.gmail.unread.'.$id, 0) + 1;
                Cache::put('finance.gmail.unread.'.$id, $tries, now()->addDays(40));
                if ($tries < 3) {
                    return null;
                }
            } catch (Throwable $error) {
                Log::info('Finance: a Gmail attachment was skipped.', ['message' => $id, 'file' => $stem, 'error' => $error->getMessage()]);
            }
        }
        if ($count === 0 && ! FinanceDocument::query()->where('external_id', 'like', 'gmail:'.$id.':%')->exists()) {
            Cache::put('finance.gmail.seen.'.$id, 1, now()->addDays(40));
        }

        return $count;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->workspace->token())->acceptJson()->connectTimeout(8)->timeout(30);
    }
}
