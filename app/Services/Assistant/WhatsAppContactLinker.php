<?php

namespace App\Services\Assistant;

use App\Enums\ProspectSource;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Prospect;
use App\Models\WhatsAppConversation;
use App\Services\Sales\AgencyLeadInbox;
use Throwable;

/**
 * Links a WhatsApp conversation to the customer or prospect with the same phone number (customer primary
 * phone, customer contacts, prospect contact phone; last 10 digits, Turkish formats normalised). New
 * conversations are linked when created; an operator's choice (link_source = operator) is never changed.
 */
final class WhatsAppContactLinker
{
    public static function boot(): void
    {
        WhatsAppConversation::created(static function (WhatsAppConversation $conversation): void {
            try {
                // Faz 14: a new contact that matches no customer or prospect is a possible lead for the agency.
                if (! app(self::class)->link($conversation) && config('moxdop-leads.whatsapp_unknown_contacts', true) && self::key((string) $conversation->contact_id) !== null) {
                    app(AgencyLeadInbox::class)->receive([
                        'name' => (string) ($conversation->contact_name ?? ''), 'phone' => '+'.ltrim((string) $conversation->contact_id, '+'),
                        'message' => 'WhatsApp üzerinden yazdı.',
                    ], 'whatsapp');
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public static function key(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    public function link(WhatsAppConversation $conversation): bool
    {
        if ($conversation->link_source === 'operator') {
            return false;
        }
        $key = self::key((string) $conversation->contact_id);
        if ($key === null) {
            return false;
        }
        $customerId = $this->customerFor($key);
        $prospectId = $customerId === null ? $this->prospectFor($key) : null;
        if ($customerId === null && $prospectId === null) {
            return false;
        }
        $conversation->forceFill(['customer_id' => $customerId, 'prospect_id' => $prospectId, 'link_source' => 'phone'])->save();

        return true;
    }

    /** Re-link unlinked / phone-linked conversations (e.g. after a customer phone was added). */
    public function linkAll(): int
    {
        $linked = 0;
        WhatsAppConversation::query()->where(fn ($q) => $q->whereNull('link_source')->orWhere('link_source', 'phone'))
            ->orderBy('id')->chunkById(200, function ($conversations) use (&$linked): void {
                foreach ($conversations as $conversation) {
                    $linked += $this->link($conversation) ? 1 : 0;
                }
            });

        return $linked;
    }

    public function setManual(WhatsAppConversation $conversation, ?int $customerId, ?int $prospectId): void
    {
        $conversation->forceFill(['customer_id' => $customerId, 'prospect_id' => $customerId === null ? $prospectId : null, 'link_source' => 'operator'])->save();
    }

    /** New prospect from an unknown WhatsApp contact (sales lead). */
    public function createProspect(WhatsAppConversation $conversation, ?int $ownerId): Prospect
    {
        $prospect = Prospect::query()->create([
            'company_name' => (string) ($conversation->contact_name ?: '+'.$conversation->contact_id),
            'source' => ProspectSource::WhatsApp->value, 'contact_name' => $conversation->contact_name,
            'contact_phone' => '+'.ltrim((string) $conversation->contact_id, '+'), 'status' => 'new', 'owner_user_id' => $ownerId,
            'next_follow_up_on' => now()->addDay()->toDateString(), 'next_step' => 'WhatsApp talebine dönüş yap',
        ]);
        $this->setManual($conversation, null, $prospect->id);
        $this->index = null;

        return $prospect;
    }

    /** @var array{customers: array<string, int>, prospects: array<string, int>}|null phone key → id, built once per instance */
    private ?array $index = null;

    private function customerFor(string $key): ?int
    {
        return $this->index()['customers'][$key] ?? null;
    }

    private function prospectFor(string $key): ?int
    {
        return $this->index()['prospects'][$key] ?? null;
    }

    /** @return array{customers: array<string, int>, prospects: array<string, int>} */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $customers = [];
        foreach (Customer::query()->whereNotNull('primary_phone')->get(['id', 'primary_phone']) as $customer) {
            if (($key = self::key($customer->primary_phone)) !== null) {
                $customers[$key] ??= (int) $customer->id;
            }
        }
        foreach (CustomerContact::query()->whereNotNull('phone')->get(['customer_id', 'phone']) as $contact) {
            if (($key = self::key($contact->phone)) !== null) {
                $customers[$key] ??= (int) $contact->customer_id;
            }
        }
        $prospects = [];
        foreach (Prospect::query()->whereNotNull('contact_phone')->orderByDesc('id')->get(['id', 'contact_phone']) as $prospect) {
            if (($key = self::key($prospect->contact_phone)) !== null) {
                $prospects[$key] ??= (int) $prospect->id;
            }
        }

        return $this->index = ['customers' => $customers, 'prospects' => $prospects];
    }
}
