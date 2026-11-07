<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $items = Booking::with('court')->where('organizer_id', $request->user()->id)->whereNull('walk_in_name')->orderByDesc('starts_at')->limit(50)->get();

        return response()->json(['data' => $items->map(fn ($b) => $this->present($b))->all()]);
    }

    /** Bloque le créneau dix minutes. Le paiement se fait ensuite sur la page web renvoyée. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['court_id' => ['required', 'integer', 'exists:courts,id'], 'starts_at' => ['required', 'date']]);
        try {
            $booking = $this->bookings->hold($request->user(), Court::findOrFail($data['court_id']), CarbonImmutable::parse($data['starts_at'])->utc());
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($booking->load('court')), 'payment_url' => route('bookings.checkout', $booking)], 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->ensureOwner($request, $booking);

        return response()->json(['data' => $this->present($booking->load('court'))]);
    }

    /** Annulation : les règles du site s'appliquent, remboursement seulement 24 h avant le match. */
    public function destroy(Request $request, Booking $booking): JsonResponse
    {
        $this->ensureOwner($request, $booking);
        try {
            $booking = $this->bookings->cancel($booking, $request->user());
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($booking->load('court'))]);
    }

    // Comme sur le site, la réservation d'un autre joueur répond 404 : un 403 confirmerait qu'elle existe.
    private function ensureOwner(Request $request, Booking $booking): void
    {
        abort_unless($booking->organizer_id === $request->user()->id, 404);
    }

    private function present(Booking $b): array
    {
        return [
            'id' => $b->public_id,
            'court' => ['id' => $b->court->id, 'name' => $b->court->name],
            'starts_at' => $b->starts_at->toIso8601String(),
            'ends_at' => $b->ends_at->toIso8601String(),
            'status' => $b->status,
            'price_cents' => $b->price_cents,
            'vat_rate' => (float) $b->vat_rate,
            'is_arena' => $b->is_arena,
            'game_mode' => $b->game_mode,
            'hold_expires_at' => $b->hold_expires_at?->toIso8601String(),
            'refundable' => now()->lte($b->starts_at->subHours(BookingService::FREE_CANCELLATION_HOURS)),
            'cancellable' => $b->status === 'confirmed' && $b->starts_at->isFuture(),
        ];
    }
}
