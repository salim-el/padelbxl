<?php

namespace App\Http\Controllers;

use App\Models\BookingPlayer;
use App\Services\BookingService;
use App\Services\Clock;
use chillerlan\QRCode\QRCode;
use Inertia\Inertia;
use Inertia\Response;

/** F18 et F25 : page du QR code d'un joueur sans compte, ouverte depuis son lien signé. */
class PassController extends Controller
{
    public function show(BookingPlayer $player): Response
    {
        $player->load('booking.court', 'accessPass');
        $pass = $player->accessPass;
        // Réservation annulée ou QR révoqué : le lien ne montre plus rien d'utilisable.
        $valid = $pass && $pass->revoked_at === null && in_array($player->booking->status, ['confirmed', 'used'], true);
        $start = Clock::local($player->booking->starts_at);

        return Inertia::render('pass', [
            'court' => $player->booking->court->name,
            'date' => $start->format('d/m/Y'),
            'time' => $start->format('H:i'),
            'window' => $pass ? Clock::local($pass->valid_from)->format('H:i').' – '.Clock::local($pass->valid_until)->format('H:i') : null,
            'qr' => $valid ? (new QRCode)->render(BookingService::passToken($player)) : null,
            'used' => $pass?->used_at !== null,
        ]);
    }
}
