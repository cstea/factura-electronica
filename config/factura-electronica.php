<?php

declare(strict_types=1);

return [
    'environment' => env('FE_ENV', 'sandbox'),
    'credentials' => [
        'username' => env('FE_USERNAME'),
        'password' => env('FE_PASSWORD'),
    ],
    'certificate' => [
        'path' => env('FE_P12_PATH'),
        'pin' => env('FE_PIN'),
    ],
    // Check each signed comprobante against the bundled v4.4 XSD before sending it.
    'validate_schema' => (bool) env('FE_VALIDATE_SCHEMA', true),
];
