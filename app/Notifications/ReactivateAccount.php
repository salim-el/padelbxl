<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** F4 : lien qui rend au joueur le compte qu'il a désactivé, avec son historique. */
class ReactivateAccount extends Notification
{
    public const MINUTES = 60;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Lien signé : sans la clé du serveur, personne ne peut fabriquer le lien d'un autre compte.
        $url = URL::temporarySignedRoute('account.reactivate', now()->addMinutes(self::MINUTES), ['user' => $notifiable->getKey()]);

        return (new MailMessage)
            ->subject(__('Réactiver votre compte ArenaBxl'))
            ->line(__('Vous avez demandé à réactiver votre compte ArenaBxl.'))
            ->action(__('Réactiver mon compte'), $url)
            ->line(__('Ce lien vaut soixante minutes.'))
            ->line(__('Si vous n’avez rien demandé, ignorez cet e-mail. Votre compte reste désactivé.'));
    }
}
