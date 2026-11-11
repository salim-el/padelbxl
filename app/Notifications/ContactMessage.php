<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Message du formulaire de contact. « Répondre » dans la messagerie du club écrit directement à l'expéditeur.
 * Vue simple plutôt que Markdown : un lien ou un titre tapé par le visiteur reste du texte.
 */
class ContactMessage extends Notification
{
    public function __construct(public string $name, public string $email, public string $message) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Message du site : '.preg_replace('/[\r\n]+/', ' ', $this->name))
            ->replyTo($this->email, $this->name)
            ->view(['mail.contact', 'mail.contact-text'], ['name' => $this->name, 'email' => $this->email, 'body' => $this->message]);
    }
}
