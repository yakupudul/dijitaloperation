<?php

namespace App\Services\Finance;

use App\Models\Agreement;
use App\Models\FinanceDocument;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Assistant\PushNotifier;
use App\Services\Sales\SalesLeads;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppGraph;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dekont yakalama (yakup 2026-11-12): payers send the bank receipt over WhatsApp. A photo or a document from someone
 * who has a running job is read; when it is a dekont it is kept, tied to the payer's oldest open row, and the phone
 * is told, so the payment is ticked with one tap on Finans. A file that is not a dekont is not kept, and files from
 * people who are not payers are never opened.
 */
final class ReceiptCapture
{
    public function __construct(private readonly WhatsAppConnection $connection, private readonly WhatsAppGraph $graph,
        private readonly FinanceDocuments $documents, private readonly FinanceReader $reader, private readonly PushNotifier $push) {}

    public function handle(int $messageId): ?FinanceDocument
    {
        $message = WhatsAppMessage::query()->find($messageId);
        if ($message === null || $message->direction !== 'incoming' || blank($message->media_id) || ! in_array($message->message_type, ['image', 'document'], true)
            || FinanceDocument::query()->where('external_id', 'wa:'.$message->id)->exists() || $this->reader->held() !== null) {
            return null;
        }
        $conversation = WhatsAppConversation::query()->find($message->conversation_id);
        $agreement = $conversation !== null ? $this->payer($conversation) : null;
        if ($conversation === null || $agreement === null) {
            return null;
        }
        try {
            $integration = $this->connection->integration();
            $secrets = $integration !== null ? $this->connection->secrets($integration) : [];
            $token = (string) ($secrets['access_token'] ?? '');
            if ($token === '') {
                return null;
            }
            $media = $this->graph->request('GET', rawurlencode((string) $message->media_id), [], $token, (string) ($secrets['app_secret'] ?? ''));
            $url = (string) ($media['url'] ?? '');
            if (! str_starts_with($url, 'https://') || (int) ($media['file_size'] ?? 0) > FinanceReader::AI_MAX_BYTES) {
                return null;
            }
            $file = Http::withToken($token)->connectTimeout(5)->timeout(30)->get($url);
            if (! $file->successful() || $file->body() === '') {
                return null;
            }
            $mime = (string) ($media['mime_type'] ?? $message->media_mime ?? '');
            $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            if ($ext === null) {
                return null;
            }
            $docs = $this->documents->keep($file->body(), 'dekont-'.$message->id.'.'.$ext, $mime, [
                'source' => 'whatsapp', 'external_id' => 'wa:'.$message->id, 'only_receipts' => true, 'agreement_id' => null, 'customer_id' => $agreement->customer_id,
                'context' => 'Bu dosyayı bir müşteri WhatsApp\'tan gönderdi; banka dekontu olabilir.',
            ]);
        } catch (Throwable $error) {
            Log::info('Finance: a WhatsApp file could not be read as a receipt.', ['message' => $messageId, 'error' => $error->getMessage()]);

            return null;
        }
        $doc = $docs[0] ?? null;
        if ($doc === null || $doc->direction !== FinanceDocument::RECEIPT) {
            return null;
        }
        if ($doc->agreement_id === null) {
            $doc->forceFill(['agreement_id' => $agreement->id, 'brand_id' => $agreement->brand_id,
                'payment_id' => $agreement->payments()->whereNull('paid_on')->orderBy('due_on')->orderBy('id')->first()?->id])->save();
        }
        $this->push->send('finance-receipt:'.$doc->id, 'Dekont geldi: '.$agreement->payer(), $doc->amountLabel().' · Finans\'ta tek dokunuşla işaretle.', 'info', route('operator.finance'), 24, true);

        return $doc;
    }

    /** The running job of whoever writes: the chat's customer, else a job whose own phone is the chat's. */
    private function payer(WhatsAppConversation $conversation): ?Agreement
    {
        $running = fn () => Agreement::query()->whereIn('status', ['active', 'paused'])->orderByRaw("case when kind in ('monthly', 'term') then 0 else 1 end")->orderByDesc('id');
        if ($conversation->customer_id !== null) {
            $agreement = $running()->where('customer_id', $conversation->customer_id)->first();
            if ($agreement !== null) {
                return $agreement;
            }
        }
        $key = SalesLeads::phoneKey((string) $conversation->contact_id);

        return blank($key) ? null : $running()->whereNotNull('party_phone')->get()->first(fn (Agreement $a): bool => SalesLeads::phoneKey((string) $a->party_phone) === $key);
    }
}
