<?php

declare(strict_types=1);

return [
    'environment' => env('FE_ENV', 'sandbox'),
    'credentials' => [
        'username' => env('FE_USERNAME'),
        'password' => env('FE_PASSWORD'),
    ],
    'http' => [
        'timeout' => (int) env('FE_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('FE_HTTP_CONNECT_TIMEOUT', 10),
    ],
    'certificate' => [
        'path' => env('FE_P12_PATH'),
        'pin' => env('FE_PIN'),
    ],
    // Check each signed comprobante against the bundled v4.4 XSD before sending it.
    'validate_schema' => (bool) env('FE_VALIDATE_SCHEMA', true),
];
