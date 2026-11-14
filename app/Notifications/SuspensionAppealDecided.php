<?php

namespace App\Notifications;

use App\Models\SuspensionAppeal;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** F29 : décision sur la contestation d'une suspension. */
class SuspensionAppealDecided extends Notification
{
    public function __construct(private SuspensionAppeal $appeal) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $accepted = $this->appeal->status === 'accepted';

        return (new MailMessage)
            ->subject(__('Décision sur votre contestation'))
            ->line($accepted ? __('Votre contestation est acceptée. Votre compte est de nouveau actif.') : __('Votre contestation est refusée. La suspension continue.'))
            ->line(__('Motif : :note', ['note' => $this->appeal->decision_note]));
    }
}
