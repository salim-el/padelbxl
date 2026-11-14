<?php

namespace App\Notifications;

use App\Models\BookingPlayer;
use App\Services\Clock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * F18 et F25 : un joueur sans compte (client du comptoir, invité) reçoit un lien personnel vers son QR code.
 * Le QR lui-même ne part jamais par e-mail : il s'affiche sur la page du lien, valable jusqu'à la fin du créneau.
 */
class AccessPassLink extends Notification
{
    public function __construct(private BookingPlayer $player) {}

    public static function url(BookingPlayer $player): string
    {
        return URL::temporarySignedRoute('pass.show', $player->booking->ends_at, ['player' => $player->id]);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->player->booking;
        $start = Clock::local($booking->starts_at);

        return (new MailMessage)
            ->subject(__('Votre accès au terrain'))
            ->line(__(':court le :date à :time.', ['court' => $booking->court->name, 'date' => $start->format('d/m/Y'), 'time' => $start->format('H:i')]))
            ->line(__('Le lien ci-dessous affiche votre QR code personnel. Présentez-le à la borne, à partir de quinze minutes avant le début.'))
            ->action(__('Afficher mon QR code'), self::url($this->player))
            ->line(__('Ce lien est personnel. Ne le transférez pas : le QR code ne sert qu’une fois.'));
    }
}
