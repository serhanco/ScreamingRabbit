<?php

namespace App\Filament\Resources\Crawls;

class CrawlColors
{
    public static function status(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'running' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function severity(string $severity): string
    {
        return match ($severity) {
            'high' => 'danger',
            'medium' => 'warning',
            'low' => 'info',
            default => 'gray',
        };
    }

    public static function httpStatus(?int $code): string
    {
        return match (true) {
            $code === null => 'danger',
            $code >= 500 => 'danger',
            $code >= 400 => 'danger',
            $code >= 300 => 'warning',
            default => 'success',
        };
    }
}
