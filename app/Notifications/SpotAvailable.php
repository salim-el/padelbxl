<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Une place s'est libérée sur une partie ouverte : le premier joueur de la liste d'attente qui paie la prend. */
class SpotAvailable extends Notification
{
    public function __construct(private Booking $booking) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = Clock::local($this->booking->starts_at)->locale($this->locale ?? app()->getLocale());

        return (new MailMessage)
            ->subject(__('Une place s’est libérée dans une partie de padel'))
            ->line(__('Une place est libre sur le :court le :date à :time.', [
                'court' => $this->booking->court->name,
                'date' => $start->isoFormat(__('dddd D MMMM YYYY')),
                'time' => $start->format('H:i'),
            ]))
            ->action(__('Voir les parties ouvertes'), route('open-matches.index'))
            ->line(__('La place revient au premier joueur qui paie sa part.'));
    }
}
