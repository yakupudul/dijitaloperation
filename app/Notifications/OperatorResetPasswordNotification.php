<?php

namespace App\Notifications;

use App\Support\Operator\AgencySettingCatalog;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperatorResetPasswordNotification extends Notification
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = self::mailLocale($notifiable);
        $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject(__('operator.mail.reset_subject', [], $locale))
            ->line(__('operator.mail.reset_line', [], $locale))
            ->action(__('operator.mail.reset_action', [], $locale), $this->resetUrl($notifiable))
            ->line(__('operator.mail.reset_expire', ['count' => $expire], $locale));
    }

    public static function mailLocale(object $notifiable): string
    {
        if (isset($notifiable->locale) && AgencySettingCatalog::isLocale((string) $notifiable->locale)) {
            return (string) $notifiable->locale;
        }

        return 'en';
    }

    private function resetUrl(object $notifiable): string
    {
        return url(route('app.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
