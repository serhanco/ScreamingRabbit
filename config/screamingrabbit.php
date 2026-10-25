<?php

return [
    // Attribution shown in client reports, the admin panel and other public places.
    'brand' => [
        'name' => env('BRAND_NAME', 'Serhan Demirel'),
        'url' => env('BRAND_URL', 'https://serhandemirel.com'),
        'product' => 'ScreamingRabbit',
    ],

    // Colour palettes each user can pick from the user menu in the admin panel. Only colours change, never the layout.
    // 'primary' and 'gray' are names of Filament's built-in palettes (Filament\Support\Colors\Color constants).
    'default_palette' => 'turuncu',
    'palettes' => [
        'turuncu' => ['label' => 'Turuncu (varsayılan)', 'primary' => 'Orange', 'gray' => 'Zinc'],
        'okyanus' => ['label' => 'Okyanus mavisi', 'primary' => 'Sky', 'gray' => 'Slate'],
        'lacivert' => ['label' => 'Lacivert', 'primary' => 'Indigo', 'gray' => 'Slate'],
        'orman' => ['label' => 'Orman yeşili', 'primary' => 'Emerald', 'gray' => 'Stone'],
        'mor' => ['label' => 'Mor', 'primary' => 'Violet', 'gray' => 'Gray'],
        'gul' => ['label' => 'Gül kurusu', 'primary' => 'Rose', 'gray' => 'Neutral'],
        'kehribar' => ['label' => 'Kehribar', 'primary' => 'Amber', 'gray' => 'Stone'],
        'grafit' => ['label' => 'Grafit', 'primary' => 'Slate', 'gray' => 'Zinc'],
    ],

    // Default crawl settings for a new project. Politeness defaults are deliberately conservative
    // so that crawling client sites on shared hosting stays safe (see docs/05 section 5.3).
    'crawl_defaults' => [
        'max_urls' => 5000,
        'concurrency' => 2,
        'delay_ms' => 300,
        'respect_robots' => true,
        'user_agent' => 'ScreamingRabbit/1.0 (+'.env('APP_URL', 'http://localhost').'/bot)',
        'include' => '',
        'exclude' => '',
    ],

    // Ready-made User-Agent strings offered in the project form; any other value can still be typed.
    'user_agents' => [
        'Googlebot (akıllı telefon)' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Googlebot (masaüstü)' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'Chrome (masaüstü)' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    ],
];
