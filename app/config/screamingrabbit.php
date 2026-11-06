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

    // Defaults for the alert mailbox on the "Bildirim ayarları" page (Hostinger's SMTP server).
    'alerts' => [
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_port' => 465,
        // Quiet hours and the weekly summary follow the operator's clock, not the server's (UTC).
        'timezone' => env('ALERTS_TIMEZONE', 'Europe/Istanbul'),
    ],

    // How many finished crawls to keep per project; older ones are deleted every night to save database space.
    'crawl_retention' => (int) env('CRAWL_RETENTION', 10),

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
        // Outgoing links to other sites are checked once each after the crawl, at most this many per crawl.
        'check_external' => true,
        'max_external' => 500,
    ],

    // Site-wide speed scan (PageSpeed Insights for every crawled page). Each scheduler minute measures this many pages
    // side by side. With an API key Google allows 400 calls per 100 seconds and 25,000 a day (free); without one the
    // shared anonymous quota runs out quickly, so fewer calls are made.
    'speed_scan' => [
        'concurrency' => (int) env('SPEED_SCAN_CONCURRENCY', 15),
        'concurrency_without_key' => (int) env('SPEED_SCAN_CONCURRENCY_WITHOUT_KEY', 4),
        'timeout' => 50,
        // After a quota answer (429) the scan waits this long before trying again.
        'backoff_minutes' => 15,
    ],

    // Ready-made User-Agent strings offered in the project form; any other value can still be typed.
    'user_agents' => [
        'Googlebot (akıllı telefon)' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Googlebot (masaüstü)' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'Chrome (masaüstü)' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    ],
];
