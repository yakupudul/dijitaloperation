<?php

namespace App\Services\Sales;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\ReferralReward;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\SocialThread;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Carbon\CarbonImmutable;

/**
 * "Kim?" (yakup 2026-11-08): what the system already knows about the person behind a line, across channels, in plain
 * sentences: where they wrote, the record's status and who sent them, whether they are a customer (agreement, last
 * payment), Claude's research and analysis, open reminders, the last things that happened. Rule-based, no AI call:
 * it opens instantly and never costs.
 */
final class PersonMemory
{
    private const string TZ = 'Europe/Istanbul';

    /**
     * @return array{title: string, known: bool, facts: list<array{label: string, text: string}>, channels: list<string>, recent: list<array{at: string, text: string}>, summary: ?string}
     */
    public function of(?SalesLead $lead, ?WhatsAppConversation $conversation = null, ?SocialThread $thread = null, ?Customer $customer = null): array
    {
        $lead ??= $thread?->lead;
        $customer ??= $lead?->customer;
        $conversation ??= $lead?->conversation;
        $facts = [];
        $channels = [];

        if ($conversation !== null) {
            $messages = WhatsAppMessage::query()->where('conversation_id', $conversation->id);
            $count = (clone $messages)->count();
            $first = (clone $messages)->min('sent_at');
            $last = (clone $messages)->max('sent_at');
            if ($count > 0) {
                $channels[] = 'WhatsApp: '.$count.' mesaj, ilk '.$this->day($first).', son '.$this->day($last);
            }
        }
        if ($lead !== null) {
            $threads = SocialThread::query()->where('sales_lead_id', $lead->id)->with('account')->get();
            foreach ($threads->groupBy(fn (SocialThread $t): string => ($t->account?->platform === 'facebook' ? 'Facebook' : 'Instagram').' '.mb_strtolower($t->kindLabel())) as $label => $group) {
                $channels[] = $label.': '.$group->count().' konuşma, son '.$this->day($group->max('last_at'));
            }
            if (in_array($lead->source, ['form', 'meta_form'], true)) {
                $channels[] = ($lead->source === 'form' ? 'Web site formu' : 'Meta formu').' doldurdu: '.$this->day($lead->created_at);
            }
            $calls = SalesLeadEvent::query()->where('lead_id', $lead->id)->whereIn('kind', ['call', 'meeting'])->count();
            if ($calls > 0) {
                $channels[] = 'Telefon / görüşme: '.$calls.' kayıt';
            }

            $facts[] = ['label' => 'Durum', 'text' => $lead->statusLabel().($lead->isOpen() ? ' · '.$lead->stageLabel() : '').($lead->is_strong ? '' : (filled($lead->strong_hint) ? ' · Claude güçlü aday diyor: '.$lead->strong_hint : ''))];
            $via = $lead->cameViaLabel();
            $facts[] = ['label' => 'Nereden', 'text' => $lead->sourceLabel().($lead->heard_from && $lead->heard_from !== 'referral' ? ' · '.(SalesLead::HEARD_FROM[$lead->heard_from] ?? $lead->heard_from) : '').($via !== '' ? ' · Kimden: '.$via : '')];
            $business = collect([$lead->company, $lead->kindLabel(), $lead->city, $lead->website_host])->filter()->implode(' · ');
            if ($business !== '') {
                $facts[] = ['label' => 'İşletme', 'text' => $business];
            }
            if ($lead->isOpen() && $lead->next_step) {
                $facts[] = ['label' => 'Yapılacak', 'text' => $lead->next_step.($lead->next_step_at ? ' · '.$lead->next_step_at->timezone(self::TZ)->format('d.m.Y') : '')];
            }
            if ($lead->offer_amount) {
                $facts[] = ['label' => 'Teklif', 'text' => number_format((int) $lead->offer_amount, 0, ',', '.').' ₺/ay'.($lead->serviceLabel() ? ' · '.$lead->serviceLabel() : '').($lead->offer_confirmed ? '' : ' (mesajdan okundu)')];
            }
            if (is_array($lead->research) && filled($lead->research['business_name'] ?? null)) {
                $found = array_keys(array_filter(['site' => $lead->research['website'] ?? null, 'Instagram' => $lead->research['instagram'] ?? null, 'harita kaydı' => $lead->research['google_maps'] ?? null]));
                $facts[] = ['label' => 'Araştırma', 'text' => $lead->research['business_name'].($found !== [] ? ' ('.implode(', ', $found).' bulundu)' : '').(($lead->research['confidence'] ?? '') === 'low' ? ' · emin değil' : '')];
            }
            if (filled($lead->analysis_headline)) {
                $facts[] = ['label' => 'Analiz', 'text' => (string) $lead->analysis_headline];
            }
            if (filled($lead->notes)) {
                $facts[] = ['label' => 'Not', 'text' => mb_substr(trim((string) $lead->notes), 0, 240)];
            }
            $sent = ReferralReward::query()->where('referrer_customer_id', $lead->customer_id ?: -1)->count();
            if ($sent > 0) {
                $facts[] = ['label' => 'Referansları', 'text' => $sent.' kayıt getirdi'];
            }
        }
        if ($customer !== null) {
            $brand = Brand::query()->where('customer_id', $customer->id)->orderBy('id')->first();
            $agreement = Agreement::query()->where('customer_id', $customer->id)->active()->orderByDesc('id')->first();
            $lastPaid = AgreementPayment::query()->whereNotNull('paid_on')->whereHas('agreement', fn ($q) => $q->where('customer_id', $customer->id))->max('paid_on');
            $facts[] = ['label' => 'Müşterimiz', 'text' => ($brand?->name ?? $customer->name).($agreement ? ' · '.$agreement->feeLabel() : ' · anlaşma girilmedi').($lastPaid ? ' · son ödeme '.$this->day($lastPaid) : '')];
        }
        $reminders = Reminder::query()->whereNull('done_at')->where('remind_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where('sales_lead_id', $lead?->id ?? -1)->orWhere('customer_id', $customer?->id ?? -1))->orderBy('remind_at')->limit(3)->get();
        foreach ($reminders as $reminder) {
            $facts[] = ['label' => $reminder->isMeeting() ? 'Toplantı' : 'Hatırlatma', 'text' => $reminder->title.' · '.$reminder->remind_at->timezone(self::TZ)->format('d.m.Y H:i')];
        }

        $recent = [];
        if ($lead !== null) {
            foreach (app(SalesLeads::class)->timeline($lead, 6) as $row) {
                $who = ['message_in' => 'Yazdı', 'message_out' => 'Yazdın', 'call' => 'Arama', 'meeting' => 'Görüşme', 'note' => 'Not', 'offer' => 'Teklif', 'stage' => 'Durum', 'finding' => 'Bulgu'][$row['kind']] ?? 'Kayıt';
                $recent[] = ['at' => $row['at']->timezone(self::TZ)->format('d.m.Y'), 'text' => $who.': '.mb_substr(trim(preg_replace('/\s+/u', ' ', $row['body']) ?? ''), 0, 140)];
            }
        }

        return [
            'title' => $lead?->title() ?? $customer?->name ?? ($conversation?->contact_name ?: ($thread?->author_name ?: 'Bilinmeyen kişi')),
            'known' => $lead !== null || $customer !== null,
            'facts' => $facts,
            'channels' => $channels,
            'recent' => $recent,
            'summary' => filled($lead?->draft_summary) ? (string) $lead->draft_summary : null,
        ];
    }

    private function day(mixed $at): string
    {
        return $at === null ? '–' : CarbonImmutable::parse($at)->timezone(self::TZ)->format('d.m.Y');
    }
}
