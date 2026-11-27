<?php

namespace App\Services\Finance;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Müşterilere e-posta (yakup 2026-11-27: "şu an sistem tarafından herhangi bir müşteriye mail vs. gitmesini
 * istemiyorum, admin panelinden yönetebilir yap"). Customer e-mail addresses are kept for invoices, but while the
 * switch on Finans › Fatura bilgileri is off (the default) every outgoing mail with a customer's address among its
 * recipients is stopped before it leaves, whatever screen or job sent it. Mail to anyone else is untouched.
 */
final class CustomerMailGuard
{
    public const string CACHE = 'finance.customer-mail-addresses';

    public function allowed(): bool
    {
        return (bool) app(FinanceSettings::class)->get('customer_mail');
    }

    public function set(bool $on): void
    {
        app(FinanceSettings::class)->put(['customer_mail' => $on]);
    }

    /** @return array<string, true> every customer address the system knows, lower-cased */
    public function addresses(): array
    {
        return Cache::remember(self::CACHE, 300, function (): array {
            $emails = Customer::withTrashed()->get(['primary_email', 'billing_email'])
                ->flatMap(fn (Customer $c): array => [$c->primary_email, $c->billing_email])
                ->merge(CustomerContact::query()->pluck('email'));

            return $emails->filter()->map(fn ($e): string => mb_strtolower(trim((string) $e)))->unique()->mapWithKeys(fn (string $e): array => [$e => true])->all();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** MessageSending listener: false stops the mail. */
    public function handle(MessageSending $event): ?bool
    {
        try {
            if ($this->allowed()) {
                return null;
            }
            $known = $this->addresses();
            $message = $event->message;
            $to = array_merge($message->getTo(), $message->getCc(), $message->getBcc());
            foreach ($to as $address) {
                if (isset($known[mb_strtolower($address->getAddress())])) {
                    Log::info('Customer mail stopped (Finans › müşterilere e-posta kapalı).', ['subject' => $message->getSubject()]);

                    return false;
                }
            }
        } catch (Throwable $error) {
            report($error);
        }

        return null;
    }
}
