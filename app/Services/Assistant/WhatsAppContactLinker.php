<?php

namespace App\Services\Assistant;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\WhatsAppConversation;
use Throwable;

/**
 * Links a WhatsApp conversation to the customer with the same phone number (customer primary phone, customer
 * contacts; last 10 digits, Turkish formats normalised). New conversations are linked when created; an operator's
 * choice (link_source = operator) is never changed.
 */
final class WhatsAppContactLinker
{
    public static function boot(): void
    {
        WhatsAppConversation::created(static function (WhatsAppConversation $conversation): void {
            try {
                app(self::class)->link($conversation);
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
        if ($customerId === null) {
            return false;
        }
        $conversation->forceFill(['customer_id' => $customerId, 'link_source' => 'phone'])->save();

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

    public function setManual(WhatsAppConversation $conversation, ?int $customerId): void
    {
        $conversation->forceFill(['customer_id' => $customerId, 'link_source' => 'operator'])->save();
    }

    /** @var array<string, int>|null customer phone key → customer id, built once per instance */
    private ?array $index = null;

    private function customerFor(string $key): ?int
    {
        return $this->index()[$key] ?? null;
    }

    /** @return array<string, int> */
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

        return $this->index = $customers;
    }
}
