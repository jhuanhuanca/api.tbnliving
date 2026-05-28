<?php

/**
 * Orígenes TBN Living (siempre permitidos en producción vía lista + patrones regex).
 * El .env puede ampliar la lista; los patrones cubren subdominios aunque falte una entrada en env.
 */
$tbnlivingOrigins = [
    'https://admin.tbnliving.com',
    'https://front.tbnliving.com',
    'https://api.tbnliving.com',
    'https://tbnliving.com',
    'https://www.tbnliving.com',
    'https://www.front.tbnliving.com',
    'https://www.admin.tbnliving.com',
];

$devOrigins = [
    'http://localhost',
    'http://localhost:8000',
    'http://localhost:8080',
    'http://localhost:5173',
    'http://127.0.0.1',
    'http://127.0.0.1:8000',
];

$fromEnv = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))));

if ($fromEnv === ['*']) {
    $allowedOrigins = ['*'];
} elseif ($fromEnv !== []) {
    $allowedOrigins = array_values(array_unique(array_merge($fromEnv, $tbnlivingOrigins)));
} else {
    $allowedOrigins = array_merge($tbnlivingOrigins, $devOrigins);
}

$supportsCredentials = filter_var(
    env('CORS_SUPPORTS_CREDENTIALS', env('APP_ENV') === 'production'),
    FILTER_VALIDATE_BOOL,
);

// Con credenciales no se puede usar wildcard * (el navegador lo rechaza).
if ($supportsCredentials && in_array('*', $allowedOrigins, true)) {
    $allowedOrigins = array_values(array_unique(array_merge(
        array_diff($allowedOrigins, ['*']),
        $tbnlivingOrigins,
        $devOrigins,
    )));
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)*tbnliving\.com$#i',
        '#^http://localhost(:\d+)?$#i',
        '#^http://127\.0\.0\.1(:\d+)?$#i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    'supports_credentials' => $supportsCredentials,

];
