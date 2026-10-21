<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\SurveyExportController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\VerifyNotice;
use App\Livewire\Dashboard;
use App\Livewire\F11Encoding;
use App\Livewire\F72Data;
use App\Livewire\Profile;
use App\Livewire\SwsData;
use App\Livewire\SyncData;
use App\Livewire\SystemUsers;
use App\Support\F11Store;
use App\Support\SwsStore;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

// Guests only - a signed-in user is sent to their first module instead
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    // Also the "set your password" link in the account-created email
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

// The link in the verification email - works whether or not the user is signed in
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::get('/email/verify', VerifyNotice::class)->name('verification.notice');
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::middleware(['active', 'verified'])->group(function () {
        Route::get('/dashboard', Dashboard::class)->middleware('module:dashboard')->name('dashboard');
        Route::get('/f11', F11Encoding::class)->middleware('module:f11')->name('f11');
        Route::get('/f11/template', fn () => response(F11Store::templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="f11_template.csv"',
        ]))->middleware('module:f11')->name('f11.template');

        Route::middleware('module:sws')->group(function () {
            Route::get('/sws', SwsData::class)->name('sws');
            Route::get('/sws/template', fn () => response(SwsStore::templateCsv(), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="sws_template.csv"',
            ]))->name('sws.template');
            // Everything matching the data view's filters, as CSV with the template's headers
            Route::get('/sws/export', fn (\Illuminate\Http\Request $request) => response()->streamDownload(
                fn () => SwsStore::streamCsv(SwsData::filtered($request->only('city', 'district', 'barangay', 'search', 'by', 'upload', 'coords', 'sent'))),
                'sws_data_' . now()->format('Y-m-d_H-i-s') . '.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Accel-Buffering' => 'no'],
            ))->name('sws.export');
        });

        Route::get('/f72', F72Data::class)->middleware('module:f72')->name('f72');
        Route::middleware('module:f72')->prefix('f72/export')->name('f72.export.')->controller(SurveyExportController::class)->group(function () {
            Route::get('/merged', 'merged')->name('merged');
            Route::get('/data', 'data')->name('data');
            Route::get('/households', 'households')->name('households');
        });
        Route::get('/profile', Profile::class)->middleware('module:profile')->name('profile');
        Route::get('/users', SystemUsers::class)->middleware('module:users')->name('users');
        // Administrators only (Modules::ADMIN_ONLY): live server -> local copy of the survey tables
        Route::get('/sync', SyncData::class)->middleware('module:sync')->name('sync');

        // Where an account with every module unticked lands
        Route::view('/no-access', 'no-access')->name('no-access');
    });
});
