<?php

namespace App\Notifications;

use App\Support\Operator\AgencySettingCatalog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class OperatorResetPasswordNotification extends ResetPassword
{
    public function toMail(object $notifiable): MailMessage
    {
        $locale = 'en';
        if (isset($notifiable->locale) && AgencySettingCatalog::isLocale((string) $notifiable->locale)) {
            $locale = (string) $notifiable->locale;
        }

        $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->locale($locale)
            ->subject(__('operator.mail.reset_subject', [], $locale))
            ->line(__('operator.mail.reset_line', [], $locale))
            ->action(__('operator.mail.reset_action', [], $locale), $this->resetUrl($notifiable))
            ->line(__('operator.mail.reset_expire', ['count' => $expire], $locale));
    }

    protected function resetUrl(mixed $notifiable): string
    {
        return url(route('app.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
