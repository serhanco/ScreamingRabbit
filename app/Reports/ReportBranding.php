<?php

namespace App\Reports;

use App\Models\Setting;

/**
 * How client reports look: the agency's own logo, name, accent colour, contact line and a short introduction.
 * The "a Serhan Demirel product" attribution in the footer always stays.
 */
class ReportBranding
{
    public const DEFAULT_ACCENT = '#ea580c';

    public const KEYS = ['report_logo', 'report_company', 'report_accent', 'report_contact', 'report_intro'];

    /** Logos are stored inline (data URI) so they work in the PDF and in frozen report copies. */
    public const MAX_LOGO_KB = 300;

    /**
     * @return array{logo: ?string, company: ?string, accent: string, link: string, contact: ?string, intro: ?string}
     */
    public static function current(): array
    {
        $accent = (string) Setting::get('report_accent', self::DEFAULT_ACCENT);
        $accent = preg_match('/^#[0-9a-f]{6}$/i', $accent) ? strtolower($accent) : self::DEFAULT_ACCENT;

        return [
            'logo' => Setting::get('report_logo'),
            'company' => Setting::get('report_company'),
            'accent' => $accent,
            // A darker shade of the accent for links, so they stay readable on white.
            'link' => self::darken($accent, 0.15),
            'contact' => Setting::get('report_contact'),
            'intro' => Setting::get('report_intro'),
        ];
    }

    public static function darken(string $hex, float $amount): string
    {
        $rgb = array_map(fn (string $part) => (int) round(hexdec($part) * (1 - $amount)), str_split(ltrim($hex, '#'), 2));

        return vsprintf('#%02x%02x%02x', $rgb);
    }
}
