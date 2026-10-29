<?php

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MonthlyReportController;
use App\Http\Controllers\SharedReportController;
use App\Http\Controllers\WordPressController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// Public pages: a short home page with a sign-in button, plus the privacy policy and terms that Google's OAuth consent screen links to.
Route::view('/', 'legal.home')->name('home');
Route::view('/gizlilik', 'legal.privacy')->name('legal.privacy');
Route::view('/kosullar', 'legal.terms')->name('legal.terms');

// Client report links: the long random token is the only access control, and Serhan can revoke it at any time.
Route::middleware('throttle:60,1')->group(function () {
    // Monthly reports and the archive come before /rapor/{token} so their fixed words are not taken for a token.
    Route::get('/rapor/aylik/{token}', [MonthlyReportController::class, 'show'])->name('monthly-report.show');
    Route::get('/rapor/aylik/{token}/pdf', [MonthlyReportController::class, 'pdf'])->name('monthly-report.pdf');
    Route::get('/rapor/arsiv/{token}', [MonthlyReportController::class, 'archive'])->name('report.archive');
    Route::get('/rapor/{token}', [SharedReportController::class, 'show'])->name('report.show');
    Route::get('/rapor/{token}/pdf', [SharedReportController::class, 'pdf'])->name('report.pdf');
});

// Invitation links from e-mail: sign up (new people) or accept (existing members). The random token is the access control.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/davet/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/davet/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');
});

// The WordPress Connector plugin pairs itself with the one-time code from the project's WordPress page. The code is
// the access control, so this call has no session and no CSRF token.
Route::post('/wp-baglanti/eslestir', [WordPressController::class, 'pair'])
    ->middleware('throttle:10,1')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('wordpress.pair');
Route::get('/wp-baglanti/eklenti.zip', [WordPressController::class, 'plugin'])->name('wordpress.plugin');
// Installed plugins look for new versions here and download them with a link signed by their own secret.
Route::get('/wp-baglanti/surum', [WordPressController::class, 'version'])->middleware('throttle:60,1')->name('wordpress.version');
Route::get('/wp-baglanti/paket.zip', [WordPressController::class, 'package'])->middleware('throttle:20,1')->name('wordpress.package');
