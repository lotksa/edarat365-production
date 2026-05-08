<?php

/**
 * CORS configuration.
 *
 * SECURITY: never use `*` for allowed_origins together with
 * supports_credentials=true (browsers reject this combo). Origins are read
 * from env CORS_ALLOWED_ORIGINS (comma-separated). FRONTEND_URL/APP_URL are
 * appended as defaults.
 */
$envOrigins = array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    fn ($v) => $v !== ''
);

$origins = array_values(array_unique(array_filter(array_merge(
    $envOrigins,
    [env('FRONTEND_URL'), env('APP_URL')]
))));

if (empty($origins)) {
    // Local dev fallback only.
    $origins = ['http://localhost:5173', 'http://localhost:5180'];
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'Accept', 'Accept-Language', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];
