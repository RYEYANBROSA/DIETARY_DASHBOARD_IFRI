<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Every email the dashboard sends goes out in the same green template
 * (resources/views/emails/branded.blade.php): a heading, a few lines, one
 * button, and the raw link underneath for clients that strip buttons.
 */
trait BrandedMail
{
    protected function brandedMail(
        string $subject,
        string $heading,
        string $greeting,
        array $lines,
        string $actionText,
        string $actionUrl,
        array $outro = [],
    ): MailMessage {
        return (new MailMessage)
            ->subject($subject)
            ->view('emails.branded', [
                'subject' => $subject,
                'heading' => $heading,
                'greeting' => $greeting,
                'lines' => $lines,
                'actionText' => $actionText,
                'actionUrl' => $actionUrl,
                'outro' => $outro,
            ]);
    }
}
