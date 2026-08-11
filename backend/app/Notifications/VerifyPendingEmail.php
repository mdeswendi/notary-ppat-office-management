<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Confirm this is your address" — sent to the **new** address only.
 *
 * Delivered on-demand (`Notification::route`) rather than to the User model,
 * because the model still carries the old address and that is exactly the one
 * this message must not reach. Mailing the old address would let anybody who
 * briefly borrowed a signed-in session watch the change from the mailbox they
 * already control.
 *
 * The link points at the frontend, which then calls the API. A person clicking a
 * link in their mail client should land on a page, not on a JSON response.
 *
 * The raw token appears in the URL and nowhere else — not in the subject, not in
 * the body text, and never in a log line (D-073).
 */
class VerifyPendingEmail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $rawToken,
        private readonly string $newEmail,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf(
            '%s/%s/security/confirm-email?token=%s&email=%s',
            config('app.frontend_url'),
            app()->getLocale(),
            urlencode($this->rawToken),
            urlencode($this->newEmail),
        );

        return (new MailMessage)
            ->subject(__('Confirm your new email address'))
            ->greeting(__('Email address change'))
            ->line(__('A request was made to change the email address on your account to this one.'))
            ->action(__('Confirm this address'), $url)
            ->line(__('This link expires in :minutes minutes.', ['minutes' => 60]))
            // The instruction that matters: doing nothing is a safe outcome,
            // because the current address stays in force until this is confirmed.
            ->line(__('If you did not request this, no action is needed and your current address will remain unchanged.'));
    }
}
