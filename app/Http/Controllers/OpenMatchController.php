<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\WaitlistEntry;
use App\Services\BookingService;
use App\Services\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Parties où l'organisateur cherche des joueurs, et liste d'attente quand elles sont complètes. */
class OpenMatchController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $matches = BookingService::openMatches()->with('court', 'players')->where('organizer_id', '!=', $user->id)
            ->orderBy('starts_at')->limit(50)->get();
        $waiting = WaitlistEntry::where('user_id', $user->id)->whereIn('booking_id', $matches->pluck('id'))->pluck('booking_id')->all();

        return Inertia::render('open-matches/index', [
            // Ni nom ni adresse des joueurs : la liste est visible par tout le club.
            'matches' => $matches->map(function (Booking $b) use ($user, $waiting) {
                $start = Clock::local($b->starts_at);
                $open = $b->players->filter(fn ($p) => ! $p->is_organizer && $p->guest_email === null);

                return [
                    'id' => $b->public_id,
                    'court' => $b->court->name,
                    'date' => $start->locale(app()->getLocale())->isoFormat(__('dddd D MMMM YYYY')),
                    'time' => $start->format('H:i').' – '.Clock::local($b->ends_at)->format('H:i'),
                    'share_cents' => $open->first()->share_cents,
                    'free' => $open->whereNull('user_id')->where('status', 'invited')->count(),
                    'joined' => $b->players->contains('user_id', $user->id),
                    'waiting' => in_array($b->id, $waiting, true),
                ];
            })->all(),
        ]);
    }

    public function join(Request $request, Booking $booking): RedirectResponse
    {
        $request->validate(['accept_terms' => ['accepted']]);
        try {
            $this->bookings->joinOpenMatch($booking, $request->user());
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return to_route('bookings.share', $booking)->with('success', __('Place réservée et part réglée. Votre QR code est prêt.'));
    }

    public function wait(Request $request, Booking $booking): RedirectResponse
    {
        try {
            $this->bookings->joinWaitlist($booking, $request->user());
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('success', __('Vous êtes sur la liste d’attente. Un e-mail vous prévient si une place se libère.'));
    }

    public function leave(Request $request, Booking $booking): RedirectResponse
    {
        $this->bookings->leaveWaitlist($booking, $request->user());

        return back()->with('success', __('Vous avez quitté la liste d’attente.'));
    }
}
