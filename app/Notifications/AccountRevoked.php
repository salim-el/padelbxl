<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** F28 : révocation définitive, motif communiqué. */
class AccountRevoked extends Notification
{
    public function __construct(private string $reason) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Votre compte PadelBxl est révoqué'))
            ->line(__('Le club a révoqué votre compte de façon définitive.'))
            ->line(__('Motif : :reason', ['reason' => $this->reason]))
            ->line(__('Vos factures restent conservées comme la loi l’impose. Pour toute question, répondez à cet e-mail.'));
    }
}
