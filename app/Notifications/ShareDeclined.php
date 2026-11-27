<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Foot à 5 : un invité refuse, sa part reste à l'organisateur. Il l'apprend tant qu'il peut encore annuler sans frais. */
class ShareDeclined extends Notification
{
    /** @param string $guest adresse que l'organisateur a tapée : il n'apprend pas le nom du compte */
    public function __construct(private Booking $booking, private string $guest, private int $shareCents) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->locale ?? app()->getLocale();
        $start = Clock::local($this->booking->starts_at)->locale($locale);
        $until = Clock::local($this->booking->starts_at->subHours(BookingService::FREE_CANCELLATION_HOURS))->locale($locale);

        return (new MailMessage)
            ->subject(__('Une invitation à votre match de foot à 5 est refusée'))
            ->line(__(':email a refusé votre invitation au match du :date à :time sur le :court.', [
                'email' => $this->guest,
                'date' => $start->isoFormat(__('dddd D MMMM YYYY')),
                'time' => $start->format('H:i'),
                'court' => $this->booking->court->name,
            ]))
            ->line(__('Sa part de :amount reste à votre charge. Votre garantie la paiera 24 h avant le match.', [
                'amount' => number_format($this->shareCents / 100, 2, ',', ' ').' €',
            ]))
            ->line(__('Vous pouvez encore annuler sans frais jusqu’au :date.', ['date' => $until->isoFormat(__('dddd D MMMM [à] HH:mm'))]))
            ->action(__('Voir ma réservation'), route('bookings.show', $this->booking));
    }
}
