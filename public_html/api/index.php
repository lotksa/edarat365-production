<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| cPanel Deployment - Edarat365
|--------------------------------------------------------------------------
| Laravel app folder is located OUTSIDE public_html for security.
| This file is the only entry point exposed to the web.
*/

$laravelPath = realpath(__DIR__ . '/../../laravel-app');

if ($laravelPath === false || !is_dir($laravelPath)) {
    http_response_code(500);
    exit('Configuration Error: Laravel application path not found.');
}

if (file_exists($maintenance = $laravelPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $laravelPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $laravelPath.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
