<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, when an administrator creates an account from System Users.
 * The account has no usable password yet - the link lets the new user set
 * their own, so no password ever travels by email.
 */
class AccountCreatedNotification extends Notification
{
    use BrandedMail, Queueable;

    public function __construct(
        #[\SensitiveParameter] public string $token,
        public string $createdBy,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60);

        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return $this->brandedMail(
            subject: 'Your Dietary Dashboard account is ready',
            heading: 'Welcome to the Dietary Dashboard',
            greeting: 'Hello ' . $notifiable->first_name . ',',
            lines: [
                "{$this->createdBy} created a DOST-FNRI Dietary Dashboard account for you with the level {$notifiable->level->label()}.",
                "Your username is {$notifiable->email}. Set a password to sign in for the first time.",
            ],
            actionText: 'Set your password',
            actionUrl: $url,
            outro: [
                "This link expires in {$minutes} minutes. After that, use \"Forgot password?\" on the sign-in page with your email to get a new one.",
            ],
        );
    }
}
