<?php

namespace App\Filament\Widgets;

use App\Google\GoogleClient;
use App\Models\Setting;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/** Walks an admin through creating the Google sign-in key on the dashboard, until a Google account is connected. */
class GoogleSetupGuide extends Widget
{
    protected string $view = 'google.setup-widget';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    // Render with the page so the guide is visible immediately on first login.
    protected static bool $isLazy = false;

    public string $clientId = '';

    public string $clientSecret = '';

    public static function canView(): bool
    {
        return (auth()->user()?->isAdmin() ?? false) && ! GoogleClient::connected();
    }

    public function mount(): void
    {
        $this->clientId = (string) Setting::get('google_client_id');
        $this->clientSecret = (string) Setting::get('google_client_secret');
    }

    public function saveKeys(): void
    {
        abort_unless(static::canView(), 403);

        $this->validate(['clientId' => 'required|string', 'clientSecret' => 'required|string'], [], ['clientId' => 'İstemci kimliği', 'clientSecret' => 'Gizli anahtar']);

        Setting::put('google_client_id', trim($this->clientId));
        Setting::put('google_client_secret', trim($this->clientSecret));

        Notification::make()->title('Kaydedildi')->body('Şimdi 7. adımdaki "Google hesabını bağla" düğmesine bas.')->success()->send();
    }
}
