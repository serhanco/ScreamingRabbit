<?php

namespace App\Http\Controllers;

use App\Filament\Pages\GoogleSettings;
use App\Google\GoogleClient;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleAuthController
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(GoogleClient::configured(), 404);
        abort_unless(GoogleClient::canConnect($request->user()), 403);

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away(GoogleClient::authUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(GoogleClient::canConnect($request->user()), 403);

        $expected = $request->session()->pull('google_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state')) || ! $request->filled('code')) {
            Notification::make()->title('Google bağlantısı tamamlanmadı')->body($request->query('error') ? 'Google izin vermedi: '.$request->query('error') : 'Lütfen tekrar dene.')->danger()->send();

            return redirect(GoogleSettings::getUrl());
        }

        try {
            GoogleClient::connect((string) $request->query('code'), $request->user());
            $problem = GoogleClient::sitesProblem($request->user());
            $problem
                ? Notification::make()->title('Google bağlandı ama site listesi alınamadı')->body($problem)->warning()->persistent()->send()
                : Notification::make()->title('Google hesabı bağlandı')->success()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Google bağlantısı başarısız')->body('İstemci kimliği ve gizli anahtarı kontrol et.')->danger()->send();
        }

        return redirect(GoogleSettings::getUrl());
    }
}
