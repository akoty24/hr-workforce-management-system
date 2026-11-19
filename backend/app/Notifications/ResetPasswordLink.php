<?php

namespace App\Notifications;

use App\Models\PasswordResetToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Forgot password" email with a one-time link back to the app. */
class ResetPasswordLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Hello'.(isset($notifiable->name) ? " {$notifiable->name}" : '').',')
            ->line('We received a request to reset the password of your account.')
            ->action('Choose a new password', $this->url)
            ->line('This link expires in '.PasswordResetToken::LIFETIME_MINUTES.' minutes and works once.')
            ->line('If you did not ask for this, ignore this email. Your password stays the same.');
    }
}
