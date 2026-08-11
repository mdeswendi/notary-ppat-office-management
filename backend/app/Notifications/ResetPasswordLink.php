<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password-reset link.
 *
 * Replaces Laravel's built-in `ResetPassword`, whose URL targets a
 * `password.reset` **web** route this application does not have — the interface
 * is a separate Next.js app. The link therefore points at the frontend, which
 * collects the new password and posts it back to the API.
 *
 * This mail is the only place the raw token ever appears. It is not returned by
 * any endpoint, not shown to the administrator who triggered the reset, and not
 * logged (D-071). The account owner's mailbox is the single channel.
 *
 * The wording deliberately does not say who requested it. An administrator
 * triggering a reset and a user requesting one look identical from here, and
 * naming an administrator in the mail would train people to trust a message that
 * claims to come from one.
 */
class ResetPasswordLink extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

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
            '%s/%s/reset-password?token=%s&email=%s',
            config('app.frontend_url'),
            app()->getLocale(),
            urlencode($this->token),
            urlencode($notifiable->getEmailForPasswordReset()),
        );

        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('Reset your password'))
            ->greeting(__('Password reset'))
            ->line(__('A password reset was requested for your account.'))
            ->action(__('Choose a new password'), $url)
            ->line(__('This link expires in :minutes minutes.', ['minutes' => $minutes]))
            ->line(__('If you did not request this, no action is needed and your current password will remain unchanged.'));
    }
}
