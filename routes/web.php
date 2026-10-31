<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PlanningController;
use App\Models\PricingRule;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('home', [
    'prices' => PricingRule::query()
        ->whereDate('valid_from', '<=', today())
        ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
        ->orderBy('is_arena')->orderBy('start_time')
        ->get()
        ->map(fn (PricingRule $r) => [
            'name' => $r->name,
            'start' => substr($r->start_time, 0, 5),
            'end' => substr($r->end_time, 0, 5),
            'price_cents' => $r->price_cents,
            'is_arena' => $r->is_arena,
        ]),
]))->name('home');

// Le tableau de bord du kit mène au planning, cœur du produit.

Route::get('planning', PlanningController::class)->name('planning');
Route::post('locale', LocaleController::class)->middleware('throttle:20,1')->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('bookings.store');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::get('bookings/{booking}/checkout', [BookingController::class, 'checkout'])->name('bookings.checkout');
    Route::post('bookings/{booking}/pay', [BookingController::class, 'pay'])->name('bookings.pay');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::post('bookings/{booking}/rematch', [BookingController::class, 'rematch'])->middleware('throttle:10,1')->name('bookings.rematch');
    Route::get('kiosk', [KioskController::class, 'show'])->name('kiosk');
    Route::post('kiosk', [KioskController::class, 'validateToken'])->middleware('throttle:30,1')->name('kiosk.validate');
    Route::get('admin', [AdminController::class, 'index'])->name('admin');
    Route::post('admin/users/{user}/suspend', [AdminController::class, 'suspend'])->name('admin.users.suspend');
    Route::post('admin/users/{user}/reactivate', [AdminController::class, 'reactivate'])->name('admin.users.reactivate');
    Route::patch('admin/pricing-rules/{rule}', [AdminController::class, 'updatePrice'])->name('admin.pricing-rules.update');
    Route::post('admin/closures', [AdminController::class, 'storeClosure'])->name('admin.closures.store');
    Route::delete('admin/closures/{closure}', [AdminController::class, 'destroyClosure'])->name('admin.closures.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/planning')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
