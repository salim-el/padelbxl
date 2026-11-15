<?php

use App\Http\Controllers\AdminAppealController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDisputeController;
use App\Http\Controllers\AdminStaffController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CounterController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\LegalAcceptanceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OpenMatchController;
use App\Http\Controllers\PassController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\SuspensionAppealController;
use App\Models\PricingRule;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('home', [
    'prices' => PricingRule::query()
        ->whereDate('valid_from', '<=', today())
        ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
        ->orderBy('start_time')
        ->get()
        ->map(fn (PricingRule $r) => [
            'name' => $r->name,
            'start' => substr($r->start_time, 0, 5),
            'end' => substr($r->end_time, 0, 5),
            'price_cents' => $r->price_cents,
        ]),
]))->name('home');

// Le tableau de bord du kit mène au planning, cœur du produit.

Route::get('planning', PlanningController::class)->name('planning');
// Pages publiques : le joueur lit les conditions avant de les accepter, sans devoir créer un compte.
Route::get('conditions-generales', fn () => Inertia::render('legal/terms', ['club' => config('invoicing.seller')]))->name('legal.terms');
Route::get('confidentialite', fn () => Inertia::render('legal/privacy', ['club' => config('invoicing.seller')]))->name('legal.privacy');
Route::get('contact', [ContactController::class, 'create'])->name('contact');
Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:3,1')->name('contact.store');
Route::post('locale', LocaleController::class)->middleware('throttle:20,1')->name('locale.update');
// F18 et F25 : QR code d'un joueur sans compte, par son lien signé valable jusqu'à la fin du créneau.
Route::get('pass/{player}', [PassController::class, 'show'])->middleware(['signed', 'throttle:30,1'])->name('pass.show');
// F29 : le joueur suspendu ne se connecte plus ; il conteste par le lien signé reçu avec le motif.
Route::get('account/appeal/{suspension}', [SuspensionAppealController::class, 'create'])->middleware('signed')->name('account.appeal');
Route::post('account/appeal/{suspension}', [SuspensionAppealController::class, 'store'])->middleware(['signed', 'throttle:5,1'])->name('account.appeal.store');

// F7 : la nouvelle version des documents est acceptée avant toute réservation.
Route::middleware('auth')->group(function () {
    Route::get('legal/accept', [LegalAcceptanceController::class, 'show'])->name('legal.accept');
    Route::post('legal/accept', [LegalAcceptanceController::class, 'store'])->middleware('throttle:10,1')->name('legal.accept.store');
});

Route::middleware(['auth', 'verified', 'consented'])->group(function () {
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('bookings.store');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::get('bookings/{booking}/checkout', [BookingController::class, 'checkout'])->name('bookings.checkout');
    // Limité : le paiement peut envoyer jusqu'à trois invitations par e-mail.
    Route::post('bookings/{booking}/pay', [BookingController::class, 'pay'])->middleware('throttle:10,1')->name('bookings.pay');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::get('bookings/{booking}/share', [BookingController::class, 'share'])->name('bookings.share');
    Route::post('bookings/{booking}/share', [BookingController::class, 'payShare'])->middleware('throttle:10,1')->name('bookings.share.pay');
    Route::post('bookings/{booking}/share/decline', [BookingController::class, 'declineShare'])->middleware('throttle:10,1')->name('bookings.share.decline');
    Route::get('open-matches', [OpenMatchController::class, 'index'])->name('open-matches.index');
    Route::post('open-matches/{booking}/join', [OpenMatchController::class, 'join'])->middleware('throttle:10,1')->name('open-matches.join');
    Route::post('open-matches/{booking}/waitlist', [OpenMatchController::class, 'wait'])->middleware('throttle:10,1')->name('open-matches.wait');
    Route::delete('open-matches/{booking}/waitlist', [OpenMatchController::class, 'leave'])->name('open-matches.leave');
    Route::get('bookings/{booking}/invoices/{invoice:number}/ubl', [BookingController::class, 'ubl'])->scopeBindings()->name('bookings.invoices.ubl');
    Route::get('bookings/{booking}/invoices/{invoice:number}/pdf', [BookingController::class, 'pdf'])->scopeBindings()->name('bookings.invoices.pdf');
    Route::get('bookings/{booking}/invoices/{invoice:number}/credit-notes/{creditNote:number}/pdf', [BookingController::class, 'creditNotePdf'])->scopeBindings()->name('bookings.invoices.credit-notes.pdf');
    Route::post('bookings/{booking}/rematch', [BookingController::class, 'rematch'])->middleware('throttle:10,1')->name('bookings.rematch');
    Route::get('kiosk', [KioskController::class, 'show'])->name('kiosk');
    Route::post('kiosk', [KioskController::class, 'validateToken'])->middleware('throttle:30,1')->name('kiosk.validate');
    Route::get('admin', [AdminController::class, 'index'])->name('admin');
    Route::get('admin/journal', [AdminController::class, 'journal'])->name('admin.journal');
    Route::get('admin/staff', [AdminStaffController::class, 'index'])->name('admin.staff.index');
    Route::post('admin/staff', [AdminStaffController::class, 'store'])->middleware('throttle:10,1')->name('admin.staff.store');
    Route::patch('admin/staff/{user}', [AdminStaffController::class, 'update'])->name('admin.staff.update');
    Route::post('admin/staff/{user}/resend', [AdminStaffController::class, 'resend'])->middleware('throttle:6,1')->name('admin.staff.resend');
    Route::get('admin/counter', [CounterController::class, 'create'])->name('admin.counter');
    Route::post('admin/counter', [CounterController::class, 'store'])->middleware('throttle:30,1')->name('admin.counter.store');
    Route::get('admin/counter/{booking}', [CounterController::class, 'show'])->name('admin.counter.show');
    Route::post('admin/counter/{booking}/cancel', [CounterController::class, 'cancel'])->name('admin.counter.cancel');
    Route::post('admin/bookings/{booking}/cancel', [AdminController::class, 'cancelBooking'])->middleware('throttle:20,1')->name('admin.bookings.cancel');
    Route::post('admin/users/{user}/suspend', [AdminController::class, 'suspend'])->name('admin.users.suspend');
    Route::post('admin/users/{user}/reactivate', [AdminController::class, 'reactivate'])->name('admin.users.reactivate');
    Route::post('admin/users/{user}/revoke', [AdminController::class, 'revoke'])->name('admin.users.revoke');
    Route::get('admin/appeals', [AdminAppealController::class, 'index'])->name('admin.appeals.index');
    Route::post('admin/appeals/{appeal}/decision', [AdminAppealController::class, 'decide'])->name('admin.appeals.decide');
    Route::patch('admin/pricing-rules/{rule}', [AdminController::class, 'updatePrice'])->name('admin.pricing-rules.update');
    Route::post('admin/pricing-rules', [AdminController::class, 'storePricingRule'])->name('admin.pricing-rules.store');
    Route::put('admin/settings', [AdminController::class, 'updateSettings'])->name('admin.settings.update');
    Route::post('admin/pricing-rules/{rule}/close', [AdminController::class, 'closePricingRule'])->name('admin.pricing-rules.close');
    Route::post('admin/closures', [AdminController::class, 'storeClosure'])->name('admin.closures.store');
    // F17 et F27 : demandes exceptionnelles et décisions.
    Route::get('disputes', [DisputeController::class, 'index'])->name('disputes.index');
    Route::get('bookings/{booking}/dispute', [DisputeController::class, 'create'])->name('disputes.create');
    Route::post('bookings/{booking}/dispute', [DisputeController::class, 'store'])->middleware('throttle:5,1')->name('disputes.store');
    Route::get('admin/disputes', [AdminDisputeController::class, 'index'])->name('admin.disputes.index');
    Route::get('admin/disputes/{dispute}', [AdminDisputeController::class, 'show'])->name('admin.disputes.show');
    Route::post('admin/disputes/{dispute}/decision', [AdminDisputeController::class, 'decide'])->name('admin.disputes.decide');
    Route::get('admin/disputes/{dispute}/attachment', [AdminDisputeController::class, 'attachment'])->name('admin.disputes.attachment');
    Route::delete('admin/closures/{closure}', [AdminController::class, 'destroyClosure'])->name('admin.closures.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/planning')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
