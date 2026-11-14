<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * F35 : accès à l'espace du personnel. Un nouveau compte reçoit un lien pour choisir son mot de passe ;
 * un joueur promu garde le sien et reçoit le lien de l'espace.
 */
class StaffInvitation extends Notification
{
    public function __construct(private string $role, private ?string $resetUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Votre accès au personnel de PadelBxl'))
            ->line(__('Vous avez maintenant le rôle « :role » au club.', ['role' => __($this->role === 'admin' ? 'Administrateur' : 'Gérant')]))
            ->line(__('À la première connexion, vous activerez la double authentification.'));

        return $this->resetUrl
            ? $mail->action(__('Choisir mon mot de passe'), $this->resetUrl)
                ->line(__('Ce lien expire dans :count minutes. L’administrateur peut en renvoyer un.', ['count' => config('auth.passwords.users.expire')]))
            : $mail->action(__('Ouvrir l’espace du personnel'), url('/admin'));
    }
}
