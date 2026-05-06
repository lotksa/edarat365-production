<?php
define('LARAVEL_START', microtime(true));

// Fix REQUEST_URI for Laravel routing
// Laravel calculates path relative to SCRIPT_NAME, but we want it to see full URI
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

if (file_exists($maintenance = __DIR__.'/../../laravel-app/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../../laravel-app/vendor/autoload.php';

$app = require_once __DIR__.'/../../laravel-app/bootstrap/app.php';

$app->handleRequest(Illuminate\Http\Request::capture());
