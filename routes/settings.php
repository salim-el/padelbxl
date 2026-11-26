<?php

use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // Portabilité (RGPD, art. 20) : limité, le fichier rassemble tout l'historique du joueur.
    Route::get('settings/export', DataExportController::class)->middleware('throttle:3,1')->name('profile.export');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/two-factor', [TwoFactorController::class, 'edit'])->name('two-factor.edit');
    Route::post('settings/two-factor', [TwoFactorController::class, 'store'])->middleware('throttle:10,1')->name('two-factor.store');
    Route::delete('settings/two-factor', [TwoFactorController::class, 'destroy'])->middleware('throttle:10,1')->name('two-factor.destroy');

});
