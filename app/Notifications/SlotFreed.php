<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** F13 : la partie attendue est annulée, son créneau est libre ; le premier joueur qui le réserve le prend. */
class SlotFreed extends Notification
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
            ->subject(__('Un créneau s’est libéré au club'))
            ->line(__('La partie que vous attendiez sur le :court le :date à :time est annulée. Le créneau est libre.', [
                'court' => $this->booking->court->name,
                'date' => $start->isoFormat(__('dddd D MMMM YYYY')),
                'time' => $start->format('H:i'),
            ]))
            ->action(__('Réserver ce créneau'), route('planning', ['date' => $start->toDateString()]))
            ->line(__('Le créneau revient au premier joueur qui le réserve.'));
    }
}
