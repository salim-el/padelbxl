<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** F27 : le joueur est prévenu de la décision sur sa demande, avec la motivation. */
class DisputeDecided extends Notification
{
    public function __construct(private Dispute $dispute) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = Clock::local($this->dispute->booking->starts_at)->locale($this->locale ?? app()->getLocale());
        $mail = (new MailMessage)
            ->subject(__('Décision sur votre demande exceptionnelle'))
            ->line(__('Votre demande pour la réservation du :date à :time a reçu une décision.', ['date' => $start->isoFormat(__('dddd D MMMM YYYY')), 'time' => $start->format('H:i')]));
        $mail = match ($this->dispute->status) {
            'accepted_full' => $mail->line(__('Elle est acceptée. Vous êtes remboursé de :amount.', ['amount' => self::euros($this->dispute->refunded_cents)])),
            'accepted_partial' => $mail->line(__('Elle est acceptée en partie. Vous êtes remboursé de :amount.', ['amount' => self::euros($this->dispute->refunded_cents)])),
            'accepted_credit' => $mail->line(__('Elle est acceptée. Vous recevez un avoir de :amount, valable un an et déduit de votre prochain paiement.', ['amount' => self::euros($this->dispute->refunded_cents)])),
            default => $mail->line(__('Elle est refusée.')),
        };

        return $mail->line(__('Motif : :note', ['note' => $this->dispute->decision_note]))
            ->action(__('Voir mes demandes'), route('disputes.index'));
    }

    private static function euros(?int $cents): string
    {
        return number_format(($cents ?? 0) / 100, 2, ',', ' ').' €';
    }
}
