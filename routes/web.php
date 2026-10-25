<?php

use App\Http\Controllers\SharedReportController;
use Illuminate\Support\Facades\Route;

// This is a private tool: the root address only forwards to the admin panel.
Route::redirect('/', '/admin');

// Client report links: the long random token is the only access control, and Serhan can revoke it at any time.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/rapor/{token}', [SharedReportController::class, 'show'])->name('report.show');
    Route::get('/rapor/{token}/pdf', [SharedReportController::class, 'pdf'])->name('report.pdf');
});
