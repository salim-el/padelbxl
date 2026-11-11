<?php

use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CourtController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OpenDataController;
use App\Http\Controllers\Api\V1\TerminalController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

// API v1 : la porte d'entrée des logiciels partenaires (borne d'accès, écran de terrain, application mobile).
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('courts', [CourtController::class, 'index'])->name('courts.index');
        Route::get('availability', [CourtController::class, 'availability'])->name('availability');
        Route::get('opendata/slots.jsonld', [OpenDataController::class, 'slots'])->name('opendata.slots');
    });
    Route::post('tokens', [TokenController::class, 'store'])->middleware('throttle:5,1')->name('tokens.store');

    Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, 'throttle:60,1'])->group(function () {
        Route::delete('tokens/current', [TokenController::class, 'destroy'])->name('tokens.destroy');

        // Jeton d'un joueur : il ne voit et ne modifie que ses propres données.
        Route::middleware('abilities:bookings')->group(function () {
            Route::get('me', [MeController::class, 'show'])->name('me.show');
            Route::put('me', [MeController::class, 'update'])->name('me.update');
            Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
            Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('bookings.store');
            Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
            Route::delete('bookings/{booking}', [BookingController::class, 'destroy'])->name('bookings.destroy');
        });

        // Jeton d'un appareil du club, créé par le gérant avec la commande api:partner-token.
        Route::middleware('abilities:terminal')->group(function () {
            Route::get('terminal/courts/{court}/current', [TerminalController::class, 'current'])->name('terminal.current');
            Route::post('terminal/access', [TerminalController::class, 'access'])->middleware('throttle:30,1')->name('terminal.access');
        });
    });
});
