<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $encryptedToken) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('platform.frontend_url'), '/').'/set-password?reset='
            .rawurlencode(Crypt::decryptString($this->encryptedToken)).'&email='.rawurlencode((string) $notifiable->email);

        return (new MailMessage)
            ->subject('Reset your ZavSync password')
            ->line('A password reset was requested for your ZavSync account.')
            ->action('Reset password', $url)
            ->line('If you did not request this reset, no action is required.');
    }
}
