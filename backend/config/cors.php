<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],

    // React (Vite) dev server default ports. Add your production
    // frontend origin here too once deployed.
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,

    // We use a manual Authorization: Bearer <token> header, not cookies,
    // so credentials don't need to be included.
    'supports_credentials' => false,
];
