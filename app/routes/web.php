<?php

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\SharedReportController;
use Illuminate\Support\Facades\Route;

// This is a private tool: the root address only forwards to the admin panel.
Route::redirect('/', '/admin');

// Client report links: the long random token is the only access control, and Serhan can revoke it at any time.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/rapor/{token}', [SharedReportController::class, 'show'])->name('report.show');
    Route::get('/rapor/{token}/pdf', [SharedReportController::class, 'pdf'])->name('report.pdf');
});

// Invitation links from e-mail: sign up (new people) or accept (existing members). The random token is the access control.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/davet/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/davet/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');
});
