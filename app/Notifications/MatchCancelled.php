<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Un joueur apprend que sa partie est annulée sans l'avoir décidé, et ce qui lui est rendu. */
class MatchCancelled extends Notification
{
    public function __construct(private Booking $booking, private int $refundedCents, private string $reason) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = Clock::local($this->booking->starts_at)->locale($this->locale ?? app()->getLocale());
        $mail = (new MailMessage)
            ->subject(__('Votre partie est annulée'))
            ->line(__('La partie du :date à :time (:court) est annulée.', ['date' => $start->isoFormat(__('dddd D MMMM YYYY')), 'time' => $start->format('H:i'), 'court' => $this->booking->court->name]))
            // Le motif tapé par le gérant reste tel quel ; un motif de l'application est traduit.
            ->line(__('Motif : :note', ['note' => __($this->reason)]));
        if ($this->refundedCents > 0) {
            $mail->line(__('Vous êtes remboursé de :amount.', ['amount' => number_format($this->refundedCents / 100, 2, ',', ' ').' €']));
        }

        return $mail->action(__('Voir mes réservations'), route('bookings.index'));
    }
}
