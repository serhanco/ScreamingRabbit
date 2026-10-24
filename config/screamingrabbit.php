<?php

return [
    // Default crawl settings for a new project. Politeness defaults are deliberately conservative
    // so that crawling client sites on shared hosting stays safe (see docs/05 section 5.3).
    'crawl_defaults' => [
        'max_urls' => 50000,
        'concurrency' => 3,
        'delay_ms' => 200,
        'respect_robots' => true,
        'user_agent' => 'ScreamingRabbit/1.0 (+'.env('APP_URL', 'http://localhost').'/bot)',
    ],
];
