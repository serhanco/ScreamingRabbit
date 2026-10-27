<?php

namespace App\Filament\Widgets;

use App\Google\GoogleClient;
use Filament\Widgets\Widget;

/** Shows the Google OAuth setup steps on the dashboard until an admin connects a Google account. */
class GoogleSetupGuide extends Widget
{
    protected string $view = 'google.setup-widget';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    // Render with the page so the guide is visible immediately on first login.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return (auth()->user()?->isAdmin() ?? false) && ! GoogleClient::connected();
    }
}
