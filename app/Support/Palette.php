<?php

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * The colour palettes a user can choose for the admin panel (config/screamingrabbit.php `palettes`).
 */
class Palette
{
    /**
     * @return array<string, string> key => label
     */
    public static function options(): array
    {
        return array_map(fn (array $palette) => $palette['label'], config('screamingrabbit.palettes'));
    }

    public static function resolve(?string $key): string
    {
        return array_key_exists((string) $key, config('screamingrabbit.palettes')) ? $key : config('screamingrabbit.default_palette');
    }

    /**
     * Filament colour definitions for the palette; unknown keys fall back to the default palette.
     *
     * @return array<string, array<int | string, string | int>>
     */
    public static function colors(?string $key): array
    {
        $palette = config('screamingrabbit.palettes')[self::resolve($key)];

        return [
            'primary' => constant(Color::class.'::'.$palette['primary']),
            'gray' => constant(Color::class.'::'.$palette['gray']),
        ];
    }
}
