<?php

namespace App\Mcp\Tools;

use App\Models\FinanceDocument;
use App\Services\Finance\FinanceDesk;
use App\Services\Finance\FinanceDocuments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('finance')]
#[Description('Finans: payment tracking of every paid job (who agreed what, who paid, what remains), the operator\'s expenses and invoices. Use it when the operator tells you in words to add, change or record something ("Ahmet Usta web sitesi 30.000, üç taksit", "Panorama 12.000 yatırdı", "Dentocare ücreti ocaktan beri 16.000"). action=summary (a month: rows, late, spend, upcoming), jobs (find a job and its open rows by name), add_job (brand_id or party; kind monthly/term/one_time/yearly; fee or installments), record_payment (money that came in fills the oldest open rows; vat auto decides KDV dahil/hariç), unpay, update_job (fee with fee_since, due_day, title, note, installments, status), add_expense, unread_papers (Gmail papers no rule could read: mail, text if any, a day-long file_url; read each — open the file_url when there is no text — and answer with read_paper), read_paper (data: id, mine = the paper is issued by or to Moximu / Yakup Udül, then kind invoice|receipt|other, doc_no, doc_date, seller, seller_tax_no, buyer, buyer_tax_no, amount (with taxes), vat, currency, summary; write only what the paper says). Amounts are TL without KDV for jobs, with KDV for expenses. Never guess a fee the operator did not say. Every write answers with what was done; preview=true only shows what would happen.')]
class Finance extends Tool
{
    private const array WRITES = ['add_job' => 'finance_job', 'record_payment' => 'finance_payment', 'unpay' => 'finance_unpay', 'update_job' => 'finance_job_update', 'add_expense' => 'finance_expense'];

    public function handle(Request $request, FinanceDesk $desk): Response
    {
        $validated = $request->validate([
            'action' => ['required', 'in:summary,jobs,add_job,record_payment,unpay,update_job,add_expense,unread_papers,read_paper'],
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'q' => ['nullable', 'string', 'max:120'],
            'ended' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'boolean'],
            'data' => ['nullable', 'array'],
        ]);
        $action = $validated['action'];
        $json = fn (array $value): string => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($action === 'summary') {
            return Response::text($json($desk->summary($validated['month'] ?? null)));
        }
        if ($action === 'jobs') {
            return Response::text($json($desk->jobs((string) ($validated['q'] ?? ''), (bool) ($validated['ended'] ?? false))));
        }
        if ($action === 'unread_papers') {
            $papers = app(FinanceDocuments::class)->unread();

            return Response::text($papers === [] ? 'Okunmayı bekleyen belge yok.' : $json(['papers' => $papers, 'next' => 'Her belge için read_paper çağır: data.id ve data.mine (Moximu ya da Yakup Udül adına mı), sonra belgede yazanlar.']));
        }
        if ($action === 'read_paper') {
            return $this->readPaper((array) ($validated['data'] ?? []));
        }
        try {
            $preview = $desk->preview(self::WRITES[$action], (array) ($validated['data'] ?? []));
            if ($validated['preview'] ?? false) {
                return Response::text($preview['title']."\n".implode("\n", $preview['lines'])."\n\n(Önizleme: bir şey yazılmadı.)");
            }
            $result = $desk->run(self::WRITES[$action], $preview['args'], null);
        } catch (InvalidArgumentException $error) {
            return Response::error($error->getMessage());
        }

        return Response::text($result['text']."\n".implode("\n", $preview['lines'])."\n".$result['url']);
    }

    /** What a subscription round read on a paper no rule could read: ours comes into sight, another's stays a mark. */
    private function readPaper(array $data): Response
    {
        $doc = FinanceDocument::query()->where('source', 'gmail')->where('meta->reason', FinanceDocuments::UNVERIFIED)->find((int) ($data['id'] ?? 0));
        if ($doc === null) {
            return Response::error('Bu numarada okunmayı bekleyen belge yok (unread_papers ile bak).');
        }
        $text = fn (string $key, int $max): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? mb_substr(trim($data[$key]), 0, $max) : null;
        $tax = fn (string $key): ?string => in_array(strlen($d = (string) preg_replace('/\D+/', '', (string) ($data[$key] ?? ''))), [10, 11], true) ? $d : null;
        $money = fn (string $key): ?float => is_numeric($data[$key] ?? null) && (float) $data[$key] >= 0 && (float) $data[$key] < 1e11 ? round((float) $data[$key], 2) : null;
        $date = $text('doc_date', 10);
        $currency = strtoupper((string) ($text('currency', 3) ?? 'TRY'));
        $read = ['kind' => in_array($data['kind'] ?? '', ['invoice', 'receipt', 'other'], true) ? (string) $data['kind'] : 'invoice', 'doc_no' => $text('doc_no', 80),
            'doc_date' => $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null, 'seller' => $text('seller', 200), 'seller_tax_no' => $tax('seller_tax_no'),
            'buyer' => $text('buyer', 200), 'buyer_tax_no' => $tax('buyer_tax_no'), 'amount' => $money('amount'), 'vat' => $money('vat'),
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'TRY', 'summary' => $text('summary', 160), 'cost_usd' => 0.0, 'by' => 'claude_mcp'];
        $mine = filter_var($data['mine'] ?? false, FILTER_VALIDATE_BOOLEAN) && $read['kind'] !== 'other';
        app(FinanceDocuments::class)->settle($doc, $mine, $read);

        return Response::text($mine ? 'Belge alındı: '.($read['seller'] ?? $doc->original_name).($read['amount'] !== null ? ' · '.number_format($read['amount'], 2, ',', '.').' '.$read['currency'] : '').'.'
            : 'Belge adına değil: yalnız işareti kaldı, dosyası silindi.');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['summary', 'jobs', 'add_job', 'record_payment', 'unpay', 'update_job', 'add_expense', 'unread_papers', 'read_paper'])->required(),
            'month' => $schema->string()->description('summary: YYYY-MM (empty = this month).'),
            'q' => $schema->string()->description('jobs: part of the payer, brand or job name (empty = every running job).'),
            'ended' => $schema->boolean()->description('jobs: include ended jobs.'),
            'preview' => $schema->boolean()->description('Writes: true = only say what would happen.'),
            'data' => $schema->object()->description('The write\'s fields. add_job: brand_id | party (+ party_phone), title, kind, fee, term_months, due_day, starts_on, installments [{due_on, amount, label}], tax_no, note, services [seo_gbp|seo|gbp|google_ads|meta_ads|web|other], no_invoice (true: the payer takes no invoice, the fee is the money as it comes, no KDV), next_fee + next_fee_when (a raise agreed for later and what it waits on, e.g. 30000 / "Google Ads başlayınca"). '
                .'record_payment: agreement_id, amount, date, vat (auto|yes|no), note. unpay: payment_id. update_job: agreement_id + any of fee, fee_since, due_day, title, note, party, party_phone, tax_no, no_invoice, next_fee, next_fee_when, installments, status (active|paused|ended). '
                .'add_expense: counterparty, amount, currency (TRY|USD|EUR|GBP), doc_date, category (yazilim|sunucu|reklam|yapay_zeka|dis_hizmet|iletisim|ofis|vergi|diger), brand_id, rebill, note. Dates YYYY-MM-DD.'),
        ];
    }
}
