<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Invitation à payer sa part d'une partie réservée par un autre joueur. */
class ShareInvitation extends Notification
{
    private string $organizer;

    public function __construct(private Booking $booking, string $organizer, private int $shareCents)
    {
        // Le nom vient d'un formulaire d'inscription et part chez un inconnu : seuls lettres, espaces, tirets
        // et apostrophes passent, sur 60 caractères. Ni lien, ni nom de domaine, ni numéro de téléphone.
        $propre = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{M} \'’-]+/u', ' ', $organizer) ?? '') ?? '');
        $this->organizer = mb_substr($propre, 0, 60) ?: 'PadelBxl';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = Clock::local($this->booking->starts_at)->locale($this->locale ?? app()->getLocale());

        return (new MailMessage)
            ->subject(__(':name vous invite à une partie de padel', ['name' => $this->organizer]))
            ->line(__(':name a réservé le :court le :date à :time et partage le prix avec vous.', [
                'name' => $this->organizer,
                'court' => $this->booking->court->name,
                'date' => $start->isoFormat(__('dddd D MMMM YYYY')),
                'time' => $start->format('H:i'),
            ]))
            ->line(__('Votre part : :amount.', ['amount' => number_format($this->shareCents / 100, 2, ',', ' ').' €']))
            ->action(__('Payer ma part'), route('bookings.share', $this->booking))
            ->line(__('Sans paiement 24 h avant le match, l’organisateur règle votre part.'));
    }
}
