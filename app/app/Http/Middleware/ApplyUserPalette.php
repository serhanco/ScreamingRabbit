<?php

namespace App\Http\Middleware;

use App\Support\Palette;
use Closure;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Swaps the panel colours for the palette the signed-in user picked. The panel registers its colours
 * before the user is known, so this runs after authentication and overrides them for the request.
 */
class ApplyUserPalette
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            FilamentColor::register(Palette::colors($user->color_palette));
        }

        return $next($request);
    }
}
