<?php

namespace App\Services\Inbox;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\SalesLead;
use App\Models\SocialThread;
use App\Models\WhatsAppConversation;
use Carbon\CarbonInterface;

/**
 * One line of the Gelen kutusu: a WhatsApp chat, a social thread or a sales step, with the record behind it. Built from
 * the existing tables on every read (Aşama 2: "kutu mevcut tabloları okur, veri taşınmaz").
 */
final class InboxItem
{
    public const string WHATSAPP = 'whatsapp';

    public const string SOCIAL = 'social';

    public const string STEP = 'step';

    /** A customer-period risk signal (Aşama 3): a line of work on a served brand. */
    public const string RISK = 'risk';

    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly int $id,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $channel,
        public readonly string $preview,
        public readonly ?CarbonInterface $at,
        /** They wrote last (or a form waits for its first answer): an answer is owed. */
        public readonly bool $owed,
        /** The record's next step is today or overdue. */
        public readonly bool $due,
        public readonly bool $late,
        public readonly bool $fromCustomer,
        public readonly ?SalesLead $lead,
        public readonly ?Customer $customer,
        public readonly ?WhatsAppConversation $conversation = null,
        public readonly ?SocialThread $thread = null,
        public readonly ?Brand $brand = null,
        /** @var array{key: string, period: string, label: string, detail: string, done: string}|null */
        public readonly ?array $signal = null,
    ) {}

    public function in(string $view): bool
    {
        return match ($view) {
            'borclu' => $this->owed,
            'adimlar' => $this->due,
            'musteriler' => $this->fromCustomer,
            default => true,
        };
    }

    /** The record's name for lists and messages ("kart" when it is a prospect, the customer's name when a customer). */
    public function label(): string
    {
        return $this->title;
    }

    /**
     * The type and id parsed back from a key ("wa-12", "sosyal-5", "adim-9", "risk-3-rapor_yok-2026-10"); a risk key
     * carries the signal and its period as well. Null for anything else.
     *
     * @return array{0: string, 1: int, 2?: string, 3?: string}|null
     */
    public static function parse(string $key): ?array
    {
        if (preg_match('/^risk-(\d{1,18})-([a-z_]{1,30})-(\d{4}-\d{2})$/', $key, $m) === 1) {
            return [self::RISK, (int) $m[1], $m[2], $m[3]];
        }
        if (preg_match('/^(wa|sosyal|adim)-(\d{1,18})$/', $key, $m) !== 1) {
            return null;
        }

        return [['wa' => self::WHATSAPP, 'sosyal' => self::SOCIAL, 'adim' => self::STEP][$m[1]], (int) $m[2]];
    }

    public static function riskKey(int $brandId, string $signal, string $period): string
    {
        return 'risk-'.$brandId.'-'.$signal.'-'.$period;
    }
}
