<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** F11 : appel à joueurs envoyé aux membres d'un niveau proche, libres sur ce créneau. */
class MatchCall extends Notification
{
    public function __construct(private Booking $booking, private int $free) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = Clock::local($this->booking->starts_at)->locale($this->locale ?? app()->getLocale());
        $level = $this->booking->level_min === $this->booking->level_max
            ? (string) $this->booking->level_min
            : $this->booking->level_min.'–'.$this->booking->level_max;

        return (new MailMessage)
            ->subject(__('Il manque :count joueur(s) pour une partie de padel', ['count' => $this->free]))
            ->line(__('Une partie de niveau :level cherche :count joueur(s) sur le :court le :date à :time.', [
                'level' => $level,
                'count' => $this->free,
                'court' => $this->booking->court->name,
                'date' => $start->isoFormat(__('dddd D MMMM YYYY')),
                'time' => $start->format('H:i'),
            ]))
            ->action(__('Voir les parties ouvertes'), route('open-matches.index'))
            ->line(__('La place revient au premier joueur qui paie sa part.'));
    }
}
