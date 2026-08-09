<?php

namespace App\Support\BrandIntelligence;

/**
 * Business conversion goal types — not GA4 event mappings.
 */
final class ConversionGoalTypes
{
    public const string QUALIFIED_LEAD = 'qualified_lead';

    public const string FORM_SUBMISSION = 'form_submission';

    public const string WHATSAPP_CONVERSATION = 'whatsapp_conversation';

    public const string PHONE_CALL = 'phone_call';

    public const string PURCHASE = 'purchase';

    public const string BOOKING = 'booking';

    public const string APPOINTMENT_REQUEST = 'appointment_request';

    public const string CUSTOM = 'custom';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::QUALIFIED_LEAD => 'Qualified lead',
            self::FORM_SUBMISSION => 'Form submission',
            self::WHATSAPP_CONVERSATION => 'WhatsApp conversation',
            self::PHONE_CALL => 'Phone call',
            self::PURCHASE => 'Purchase',
            self::BOOKING => 'Booking',
            self::APPOINTMENT_REQUEST => 'Appointment request',
            self::CUSTOM => 'Custom goal',
        ];
    }

    public static function label(string $type): string
    {
        return self::options()[$type] ?? $type;
    }
}
