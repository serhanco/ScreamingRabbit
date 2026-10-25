<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// A normal checkout keeps the application one level above public/. On shared hosting (docs/06) the web root
// is the repository's `deploy` branch, which keeps the application in an `app` folder next to this file.
$basePath = is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/..' : __DIR__.'/app';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $basePath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $basePath.'/vendor/autoload.php';

// Before the first installation there is no .env yet: send visitors to the installer instead of an error page.
$dataPath = App\Support\DataDir::resolve($basePath) ?? $basePath;
if (! is_file($dataPath.'/.env') && is_file(__DIR__.'/install.php')) {
    header('Location: /install.php');
    exit;
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $basePath.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
