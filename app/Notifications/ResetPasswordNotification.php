<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    use BrandedMail;

    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60);

        return $this->brandedMail(
            subject: 'Reset your password',
            heading: 'Reset your password',
            greeting: 'Hello ' . $notifiable->first_name . ',',
            lines: [
                'We received a request to reset the password for your Dietary Dashboard account.',
            ],
            actionText: 'Choose a new password',
            actionUrl: $this->resetUrl($notifiable),
            outro: [
                "This link expires in {$minutes} minutes.",
                'If you did not ask for a password reset, no action is needed - your password stays the same.',
            ],
        );
    }
}
