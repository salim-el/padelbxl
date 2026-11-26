<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\MatchCall;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;

/**
 * F11 : l'organisateur publie un appel avec le niveau demandé. Les joueurs de ce niveau, sans match sur le créneau,
 * reçoivent un e-mail. La place revient au premier qui paie (BookingService::joinOpenMatch).
 */
class MatchCallService
{
    /** Au-delà, l'appel deviendrait du courrier de masse ; les autres voient la partie dans la liste. */
    public const MAX_RECIPIENTS = 20;

    public function publish(Booking $booking, int $levelMin, int $levelMax): int
    {
        $booking->update(['level_min' => $levelMin, 'level_max' => $levelMax]);
        $free = $booking->players()->where('is_organizer', false)->whereNull('user_id')->whereNull('guest_email')->where('status', 'invited')->count();
        if ($free === 0) {
            return 0;
        }
        $recipients = $this->candidates($booking);
        foreach ($recipients as $user) {
            $user->notify((new MatchCall($booking->loadMissing('court'), $free))->locale($user->locale ?? app()->getLocale()));
        }
        activity('system')->performedOn($booking)->event('match-call-sent')
            ->withProperties(['level_min' => $levelMin, 'level_max' => $levelMax, 'recipients' => $recipients->count()])->log('Appel à joueurs envoyé');

        return $recipients->count();
    }

    /** @return Collection<int, User> les plus proches du milieu de la fourchette d'abord */
    public function candidates(Booking $booking): Collection
    {
        $middle = ($booking->level_min + $booking->level_max) / 2;

        return User::where('role', 'player')->where('status', 'active')->whereNotNull('email_verified_at')
            ->whereBetween('level', [$booking->level_min, $booking->level_max])
            ->where('id', '!=', $booking->organizer_id)
            // Ni déjà dans cette partie, ni pris par un autre match qui chevauche le créneau.
            ->whereNotExists(fn (Builder $q) => $q->from('booking_players')->join('bookings', 'bookings.id', '=', 'booking_players.booking_id')
                ->whereColumn('booking_players.user_id', 'users.id')
                ->where(fn (Builder $w) => $w->where('bookings.id', $booking->id)
                    ->orWhere(fn (Builder $o) => $o->whereIn('bookings.status', Booking::ACTIVE)
                        ->where('bookings.starts_at', '<', $booking->ends_at)->where('bookings.ends_at', '>', $booking->starts_at))))
            ->orderByRaw('ABS(level - ?)', [$middle])->orderBy('id')
            ->limit(self::MAX_RECIPIENTS)->get();
    }
}
