<?php

namespace App\Services\Moxi;

use App\Mcp\Tools\SaveBrandFact;
use App\Models\Agreement;
use App\Models\Brand;
use App\Models\MoxiMessage;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\Finance\FinanceDesk;
use App\Services\Sales\Agreements;
use App\Services\Sales\ReferralRewards;
use App\Services\Sales\SalesLeads;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Moxi's hands (yakup 2026-11-09): every panel change Moxi offers is a card, and only the operator's Yap runs it here,
 * through the same services the panel's own buttons call (SalesLeads, Agreements, the epikriz). Arguments are checked when
 * the card is made (so the card shows what will really happen) and again when it runs. Nothing here writes outside the
 * panel: no site, Google, Meta or WhatsApp.
 */
final class MoxiActions
{
    /** The actions a card can hold, with the line the card shows as its title. */
    public const array ACTIONS = [
        'lead_add' => 'Yeni kayıt',
        'lead_status' => 'Durum değiştir',
        'lead_note' => 'Not ekle',
        'lead_next_step' => 'Sonraki adım',
        'lead_plan' => 'Hatırlatma / toplantı',
        'lead_offer' => 'Teklif',
        'brand_fact' => 'Marka bilgisi',
        'brand_agreement' => 'Anlaşma',
    ] + FinanceDesk::ACTIONS;

    public function __construct(private readonly SalesLeads $leads) {}

    /**
     * Checks the arguments and returns what the card shows: its title and lines in plain Turkish.
     *
     * @param  array<string, mixed>  $args
     * @return array{title: string, lines: list<string>, args: array<string, mixed>}
     *
     * @throws InvalidArgumentException when the action cannot be done as asked (the message says why)
     */
    public function preview(string $action, array $args): array
    {
        $title = self::ACTIONS[$action] ?? throw new InvalidArgumentException('Bilinmeyen işlem.');

        return match ($action) {
            'lead_add' => $this->previewAdd($title, $args),
            'brand_fact' => $this->previewFact($title, $args),
            'brand_agreement' => $this->previewAgreement($title, $args),
            'finance_job', 'finance_payment', 'finance_unpay', 'finance_job_update', 'finance_expense' => app(FinanceDesk::class)->preview($action, $args),
            default => $this->previewLead($action, $title, $args),
        };
    }

    /**
     * Runs a card the operator confirmed; returns the result line (with a link when there is one).
     *
     * @return array{text: string, url: ?string}
     */
    public function run(MoxiMessage $card, User $user): array
    {
        $action = (string) data_get($card->meta, 'action');
        $args = (array) data_get($card->meta, 'args', []);
        $this->preview($action, $args); // checked again: the record may have changed since the card was made

        return DB::transaction(fn (): array => match ($action) {
            'lead_add' => $this->add($args, $user),
            'brand_fact' => $this->fact($args),
            'brand_agreement' => $this->agreement($args, $user),
            'finance_job', 'finance_payment', 'finance_unpay', 'finance_job_update', 'finance_expense' => app(FinanceDesk::class)->run($action, $args, $user),
            default => $this->onLead($action, $args, $user),
        });
    }

    /** @param array<string, mixed> $args */
    private function previewAdd(string $title, array $args): array
    {
        $clean = array_filter([
            'name' => self::text($args['name'] ?? null, 255), 'company' => self::text($args['company'] ?? null, 255),
            'phone' => self::text($args['phone'] ?? null, 40), 'website' => self::text($args['website'] ?? null, 255),
            'email' => self::text($args['email'] ?? null, 255), 'city' => self::text($args['city'] ?? null, 120),
            'note' => self::text($args['note'] ?? null, 2000),
        ], fn ($value) => $value !== null);
        if (($clean['name'] ?? null) === null && ($clean['company'] ?? null) === null && ($clean['phone'] ?? null) === null && ($clean['website'] ?? null) === null) {
            throw new InvalidArgumentException('Kayıt için en az bir ad, işletme, telefon ya da site gerekli.');
        }
        $source = (string) ($args['source'] ?? 'other');
        $clean['source'] = isset(SalesLead::SOURCES[$source]) ? $source : 'other';
        $existing = $this->leads->match(SalesLeads::phoneKey((string) ($clean['phone'] ?? '')), SalesLeads::host((string) ($clean['website'] ?? '')), (string) ($clean['email'] ?? ''), (string) ($clean['name'] ?? $clean['company'] ?? ''));
        $lines = array_values(array_filter([
            isset($clean['company']) ? 'İşletme: '.$clean['company'] : null,
            isset($clean['name']) ? 'Kişi: '.$clean['name'] : null,
            isset($clean['phone']) ? 'Telefon: '.$clean['phone'] : null,
            isset($clean['website']) ? 'Site: '.$clean['website'] : null,
            isset($clean['city']) ? 'Şehir: '.$clean['city'] : null,
            'Kaynak: '.SalesLead::SOURCES[$clean['source']],
            isset($clean['note']) ? 'Not: '.$clean['note'] : null,
            $existing !== null ? 'Bu kişi zaten kayıtlı ('.$existing->title().'): kart güncellenir, yenisi açılmaz.' : null,
        ]));

        return ['title' => $title, 'lines' => $lines, 'args' => $clean];
    }

    /** @param array<string, mixed> $args */
    private function previewLead(string $action, string $title, array $args): array
    {
        $lead = SalesLead::query()->find((int) ($args['lead_id'] ?? 0)) ?? throw new InvalidArgumentException('Satış kaydı bulunamadı; önce satis_ara ile doğru kaydı bul.');
        $clean = ['lead_id' => $lead->id];
        $lines = ['Kayıt: '.$lead->title().($lead->company && $lead->company !== $lead->title() ? ' · '.$lead->company : '')];
        switch ($action) {
            case 'lead_status':
                $status = (string) ($args['status'] ?? '');
                if (! isset(SalesLead::STATUSES[$status])) {
                    throw new InvalidArgumentException('Durum şunlardan biri olmalı: '.implode(', ', array_keys(SalesLead::STATUSES)).'.');
                }
                if ($lead->isCustomer() && in_array($status, ['olumsuz', 'aday_degil'], true)) {
                    throw new InvalidArgumentException('Bir müşteri olumsuz ya da aday olmayan yapılamaz.');
                }
                $clean['status'] = $status;
                $lines[] = 'Durum: '.(SalesLead::STATUSES[$lead->status()] ?? $lead->status()).' → '.SalesLead::STATUSES[$status];
                if ($status === 'olumsuz') {
                    $reason = (string) ($args['reason'] ?? '');
                    if (! isset(SalesLead::LOST_REASONS[$reason])) {
                        throw new InvalidArgumentException('Olumsuz için neden gerekli: '.implode(', ', array_keys(SalesLead::LOST_REASONS)).'.');
                    }
                    $clean['reason'] = $reason;
                    $lines[] = 'Neden: '.SalesLead::LOST_REASONS[$reason];
                }
                if ($status === 'musteri' && is_array($args['agreement'] ?? null) && (int) preg_replace('/\D+/', '', (string) ($args['agreement']['fee'] ?? '')) > 0) {
                    $agreement = $args['agreement'];
                    $kind = isset(Agreement::KINDS[(string) ($agreement['kind'] ?? '')]) ? (string) $agreement['kind'] : 'monthly';
                    $fee = (int) preg_replace('/\D+/', '', (string) $agreement['fee']);
                    $term = isset($agreement['term_months']) ? max(1, min(36, (int) $agreement['term_months'])) : null;
                    $due = isset($agreement['due_day']) ? max(1, min(28, (int) $agreement['due_day'])) : 1;
                    if ($kind === 'term' && $term === null) {
                        throw new InvalidArgumentException('Dönemlik anlaşma için kaç ay olduğunu yaz.');
                    }
                    $clean['agreement'] = array_filter(['kind' => $kind, 'fee' => $fee, 'term_months' => $kind === 'term' ? $term : null, 'due_day' => $due,
                        'starts_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($agreement['starts_on'] ?? '')) === 1 ? (string) $agreement['starts_on'] : now('Europe/Istanbul')->toDateString(),
                        'note' => self::text($agreement['note'] ?? null, 500)], fn ($value) => $value !== null);
                    $lines[] = 'Anlaşma: '.number_format($fee, 0, ',', '.').' ₺ + KDV · '.Agreement::KINDS[$kind].($term ? ' · '.$term.' ay' : '').' · ödeme günü '.$due;
                } elseif ($status === 'musteri') {
                    $lines[] = 'Anlaşma girilmedi; müşteri ve marka açılır, ücreti sonra kartından girersin.';
                }
                if (($note = self::text($args['note'] ?? null, 2000)) !== null) {
                    $clean['note'] = $note;
                    $lines[] = 'Not: '.$note;
                }
                break;
            case 'lead_note':
                $clean['text'] = self::text($args['text'] ?? null, 4000) ?? throw new InvalidArgumentException('Not boş olamaz.');
                $lines[] = 'Not: '.$clean['text'];
                break;
            case 'lead_next_step':
                $clean['text'] = self::text($args['text'] ?? null, 255) ?? 'Takip et';
                $clean['date'] = self::date($args['date'] ?? null);
                $lines[] = 'Yapılacak: '.$clean['text'].' · '.self::trDate($clean['date']);
                break;
            case 'lead_plan':
                $clean['kind'] = ($args['kind'] ?? 'task') === 'meeting' ? 'meeting' : 'task';
                $clean['title'] = self::text($args['title'] ?? null, 200) ?? ($clean['kind'] === 'meeting' ? 'Toplantı' : 'Hatırlat');
                $clean['date'] = self::date($args['date'] ?? null);
                $clean['time'] = preg_match('/^\d{2}:\d{2}$/', (string) ($args['time'] ?? '')) === 1 ? (string) $args['time'] : '10:00';
                $lines[] = ($clean['kind'] === 'meeting' ? 'Toplantı: ' : 'Hatırlatma: ').$clean['title'].' · '.self::trDate($clean['date']).' '.$clean['time'];
                break;
            case 'lead_offer':
                $amount = (int) preg_replace('/\D+/', '', (string) ($args['amount'] ?? ''));
                $service = (string) ($args['service'] ?? '');
                $clean['amount'] = $amount > 0 ? $amount : null;
                $clean['service'] = isset(SalesLead::SERVICES[$service]) ? $service : null;
                $lines[] = $clean['amount'] !== null ? 'Teklif: '.number_format($amount, 0, ',', '.').' ₺/ay'.($clean['service'] ? ' · '.SalesLead::SERVICES[$clean['service']] : '') : 'Teklif silinir.';
                break;
        }

        return ['title' => $title, 'lines' => $lines, 'args' => $clean];
    }

    /** @param array<string, mixed> $args */
    private function previewFact(string $title, array $args): array
    {
        $brand = Brand::query()->find((int) ($args['brand_id'] ?? 0)) ?? throw new InvalidArgumentException('Marka bulunamadı.');
        $value = self::text($args['value'] ?? null, 2000) ?? throw new InvalidArgumentException('Bilgi boş olamaz.');
        $fields = [...array_keys((array) config('moxdop-epicrisis.fields')), ...array_keys((array) config('moxdop-epicrisis.synthesis'))];
        $field = (string) ($args['field'] ?? '');
        $question = isset($args['question_id']) ? (int) $args['question_id'] : null;
        if ($question === null && ! in_array($field, $fields, true)) {
            throw new InvalidArgumentException('Alan şunlardan biri olmalı: '.implode(', ', $fields).'.');
        }
        $clean = array_filter(['brand_id' => $brand->id, 'field' => $field !== '' ? $field : null, 'value' => $value,
            'scope' => self::text($args['scope'] ?? null, 160), 'question_id' => $question], fn ($v) => $v !== null);

        return ['title' => $title, 'lines' => ['Marka: '.$brand->name, ($field !== '' ? $field.': ' : 'Cevap: ').$value], 'args' => $clean];
    }

    /**
     * Anlaşma (yakup 2026-11-11: "bota söyleyeyim, markalara işlesin"): a served brand's fee, kind and payment day,
     * said in one sentence. The card shows what will be written; a running monthly agreement is replaced by the new one.
     *
     * @param  array<string, mixed>  $args
     */
    private function previewAgreement(string $title, array $args): array
    {
        $brand = Brand::query()->find((int) ($args['brand_id'] ?? 0)) ?? throw new InvalidArgumentException('Marka bulunamadı.');
        $kind = (string) ($args['kind'] ?? 'monthly');
        if (! isset(Agreement::KINDS[$kind])) {
            throw new InvalidArgumentException('Anlaşma türü şunlardan biri olmalı: '.implode(', ', array_keys(Agreement::KINDS)).'.');
        }
        $fee = (int) ($args['fee'] ?? 0);
        if (($fee <= 0 && $kind !== 'partnership') || $fee > 99_999_999) {
            throw new InvalidArgumentException('Ücret yazılmalı (TL, KDV hariç).');
        }
        $due = (int) ($args['due_day'] ?? 1);
        if ($due < 1 || $due > 28) {
            throw new InvalidArgumentException('Ödeme günü 1-28 arasında olmalı.');
        }
        $term = isset($args['term_months']) ? (int) $args['term_months'] : null;
        if ($kind === 'term' && ($term === null || $term < 1 || $term > 36)) {
            throw new InvalidArgumentException('Dönemlik anlaşmada ay sayısı gerekir (1-36).');
        }
        $starts = isset($args['starts_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['starts_on']) === 1 ? (string) $args['starts_on'] : now('Europe/Istanbul')->startOfMonth()->toDateString();
        $clean = array_filter(['brand_id' => $brand->id, 'kind' => $kind, 'fee' => $fee, 'due_day' => $due, 'term_months' => $kind === 'term' ? $term : null,
            'starts_on' => $starts, 'note' => self::text($args['note'] ?? null, 500)], fn ($v) => $v !== null);
        $running = Agreement::query()->where('brand_id', $brand->id)->active()->where('kind', '!=', 'one_time')->first();
        $lines = ['Marka: '.$brand->name, Agreement::KINDS[$kind].': '.number_format($fee, 0, ',', '.').' ₺ + KDV'.($kind === 'term' ? ' · '.$term.' ay' : ''),
            'Başlangıç '.self::trDate($starts).($kind !== 'one_time' ? ' · ödeme günü '.$due : '')];
        if ($running !== null && $kind !== 'one_time') {
            $lines[] = 'Yürüyen anlaşma ('.$running->feeLabel().') bunun başladığı gün biter.';
        }

        return ['title' => $title, 'lines' => $lines, 'args' => $clean];
    }

    /** @param array<string, mixed> $args */
    private function agreement(array $args, User $user): array
    {
        $brand = Brand::query()->findOrFail((int) $args['brand_id']);
        $agreement = app(Agreements::class)->start($brand, $args, $user);

        return ['text' => $brand->name.': anlaşma yazıldı ('.$agreement->feeLabel().').', 'url' => route('operator.brand', ['brand' => $brand->id, 'tab' => 'ayarlar'])];
    }

    /** @param array<string, mixed> $args */
    private function add(array $args, User $user): array
    {
        $lead = $this->leads->quickAdd($args, $user);
        $this->leads->event($lead, 'system', 'Moxi ekledi (operatör onayıyla).', meta: ['by' => $user->id, 'via' => 'moxi']);

        return ['text' => $lead->title().' kaydı hazır.', 'url' => route('operator.sales.lead', ['lead' => $lead->id])];
    }

    /** @param array<string, mixed> $args */
    private function onLead(string $action, array $args, User $user): array
    {
        $lead = SalesLead::query()->findOrFail((int) $args['lead_id']);
        $url = route('operator.sales.lead', ['lead' => $lead->id]);
        switch ($action) {
            case 'lead_status':
                if ($args['status'] === 'musteri') {
                    $brand = $this->leads->win($lead, $user, $args['agreement'] ?? null);
                    app(ReferralRewards::class)->onWon($lead->fresh());
                    if (isset($args['note'])) {
                        $this->leads->event($lead, 'note', (string) $args['note'], meta: ['by' => $user->id, 'via' => 'moxi']);
                    }
                    $this->leads->event($lead, 'system', 'Moxi müşteriye çevirdi (operatör onayıyla).', meta: ['by' => $user->id, 'via' => 'moxi']);

                    return ['text' => $lead->title().' artık müşteri; '.$brand->name.' markası açık.', 'url' => route('operator.brand', ['brand' => $brand->id])];
                }
                $this->leads->setStatus($lead, (string) $args['status'], $user, $args['reason'] ?? null, $args['note'] ?? null);
                $this->leads->event($lead, 'system', 'Moxi durumu değiştirdi: '.SalesLead::STATUSES[$args['status']].' (operatör onayıyla).', meta: ['by' => $user->id, 'via' => 'moxi']);

                return ['text' => $lead->title().' → '.SalesLead::STATUSES[$args['status']].'.', 'url' => $url];
            case 'lead_note':
                $this->leads->event($lead, 'note', (string) $args['text'], meta: ['by' => $user->id, 'via' => 'moxi']);

                return ['text' => 'Not '.$lead->title().' kartına eklendi.', 'url' => $url];
            case 'lead_next_step':
                $this->leads->setNextStep($lead, (string) $args['text'], (string) $args['date'], $user);

                return ['text' => $lead->title().' için yapılacak yazıldı.', 'url' => $url];
            case 'lead_plan':
                $this->leads->plan($lead, $args, $user);

                return ['text' => $lead->title().' için '.($args['kind'] === 'meeting' ? 'toplantı' : 'hatırlatma').' Takvim\'e eklendi.', 'url' => $url];
            case 'lead_offer':
                $this->leads->setOffer($lead, $args['amount'] ?? null, $args['service'] ?? null, $user);

                return ['text' => $lead->title().' teklifi güncellendi.', 'url' => $url];
        }

        throw new InvalidArgumentException('Bilinmeyen işlem.');
    }

    /** @param array<string, mixed> $args */
    private function fact(array $args): array
    {
        $text = MoxiTools::callMcp(SaveBrandFact::class, $args);
        if (str_starts_with($text, 'HATA:')) {
            throw new InvalidArgumentException(trim(substr($text, 5)));
        }

        return ['text' => $text, 'url' => route('operator.brand', ['brand' => (int) $args['brand_id']])];
    }

    /** A user-facing reason from what a run threw. */
    public static function reason(Throwable $error): string
    {
        if ($error instanceof ValidationException) {
            return implode(' ', array_map('strval', array_merge(...array_values($error->errors()))));
        }

        return $error instanceof InvalidArgumentException ? $error->getMessage() : 'Yapılamadı: beklenmeyen bir hata oldu.';
    }

    private static function text(mixed $value, int $max): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function date(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Tarihi YYYY-AA-GG olarak ver.');
        }

        return $value;
    }

    private static function trDate(string $date): string
    {
        return implode('.', array_reverse(explode('-', $date)));
    }
}
