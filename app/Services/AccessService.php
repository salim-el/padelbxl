<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\AccessPass;
use App\Models\Court;
use Illuminate\Support\Facades\DB;

class AccessService
{
    /** Valide un QR code à la borne. Chaque passage, autorisé ou refusé, est journalisé. */
    public function validate(string $token, Court $court): array
    {
        $pass = AccessPass::with('bookingPlayer.booking', 'bookingPlayer.user')->where('token_hash', hash('sha256', $token))->first();
        $booking = $pass?->bookingPlayer->booking;

        $reason = match (true) {
            $pass === null => 'unknown',
            $pass->revoked_at !== null || ! in_array($booking->status, ['confirmed', 'used'], true) => 'revoked',
            $booking->court_id !== $court->id => 'wrong_court',
            now()->lt($pass->valid_from) => 'too_early',
            now()->gt($pass->valid_until) => 'expired',
            default => null,
        };

        if ($reason === null) {
            // Usage unique posé de façon atomique : deux scans simultanés ne passent pas tous les deux.
            $updated = DB::table('access_passes')->where('id', $pass->id)->whereNull('used_at')->update(['used_at' => now()]);
            $reason = $updated === 1 ? null : 'already_used';
            if ($reason === null && $booking->status === 'confirmed') {
                $booking->update(['status' => 'used']);
                // Même trace que les autres changements d'état, pour que l'historique d'une partie soit complet.
                DB::table('booking_status_histories')->insert([
                    'booking_id' => $booking->id, 'from_status' => 'confirmed', 'to_status' => 'used', 'reason' => 'Accès validé à la borne.', 'changed_by' => null, 'created_at' => now(),
                ]);
            }
        }

        AccessLog::create([
            'court_id' => $court->id,
            'access_pass_id' => $pass?->id,
            'result' => $reason === null ? 'granted' : 'refused',
            'refusal_reason' => $reason,
            'scanned_at' => now(),
        ]);

        return [
            'result' => $reason === null ? 'granted' : 'refused',
            'reason' => $reason,
            'first_name' => $reason === null ? strtok((string) $pass->bookingPlayer->user?->name, ' ') : null,
        ];
    }
}
