<?php

namespace App\Filament\Widgets;

use App\Google\GoogleClient;
use App\Models\Setting;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/**
 * Walks an admin through Google on the dashboard until they have connected their own account: the primary admin gets
 * the whole OAuth client setup, other admins only the connect step. Invited members never see it.
 */
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
        $user = auth()->user();

        return (GoogleClient::canManageClient($user) || GoogleClient::canConnect($user)) && ! GoogleClient::connected($user);
    }

    public function mount(): void
    {
        $this->clientId = (string) Setting::get('google_client_id');
        $this->clientSecret = (string) Setting::get('google_client_secret');
    }

    public function saveKeys(): void
    {
        abort_unless(static::canView() && GoogleClient::canManageClient(auth()->user()), 403);

        $this->validate(['clientId' => 'required|string', 'clientSecret' => 'required|string'], [], ['clientId' => 'İstemci kimliği', 'clientSecret' => 'Gizli anahtar']);

        Setting::put('google_client_id', trim($this->clientId));
        Setting::put('google_client_secret', trim($this->clientSecret));

        Notification::make()->title('Kaydedildi')->body('Şimdi 7. adımdaki "Google hesabını bağla" düğmesine bas.')->success()->send();
    }
}
