<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    use BrandedMail;

    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.verification.expire', 60);

        return $this->brandedMail(
            subject: 'Verify your email address',
            heading: 'Confirm your email',
            greeting: 'Hello ' . $notifiable->first_name . ',',
            lines: [
                'Thanks for registering with the DOST-FNRI Dietary Dashboard. Your email address is also your username.',
                'Confirm that this address belongs to you to activate your account.',
            ],
            actionText: 'Verify email address',
            actionUrl: $this->verificationUrl($notifiable),
            outro: [
                "This link expires in {$minutes} minutes. You can request a new one from the sign-in page.",
                'If you did not create an account, you can ignore this email.',
            ],
        );
    }
}
