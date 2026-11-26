<?php

namespace App\Notifications;

use App\Models\AccountStatusHistory;
use App\Services\AccountStatusService;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** F28 et F29 : le motif est communiqué au joueur, avec un lien pour contester sans pouvoir se connecter. */
class AccountSuspended extends Notification
{
    public function __construct(private AccountStatusHistory $suspension) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('account.appeal', now()->addDays(AccountStatusService::APPEAL_DAYS), ['suspension' => $this->suspension->id]);
        $mail = (new MailMessage)
            ->subject(__('Votre compte ArenaBxl est suspendu'))
            ->line(__('Le club a suspendu votre compte. Vous ne pouvez plus vous connecter ni réserver.'))
            ->line(__('Motif : :reason', ['reason' => $this->suspension->reason]));
        $mail = $this->suspension->until
            ? $mail->line(__('La suspension prend fin le :date.', ['date' => Clock::local($this->suspension->until)->format('d/m/Y H:i')]))
            : $mail->line(__('La suspension dure jusqu’à une nouvelle décision du club.'));

        return $mail->action(__('Contester la suspension'), $url)
            ->line(__('Une autre personne que celle qui a suspendu votre compte examinera votre réponse.'));
    }
}
