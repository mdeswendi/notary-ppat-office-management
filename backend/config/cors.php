<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Sanctum SPA authentication is cookie based, so the browser only sends the
    | session and XSRF cookies when the response allows credentials AND names
    | an explicit origin. A wildcard origin is invalid with credentials, and
    | browsers reject it — see docs/07_SECURITY_RULES.md section 30.
    |
    | Origins are listed explicitly rather than pattern matched. Production
    | should carry only the approved frontend origin.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
    ],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_unique([
        env('FRONTEND_URL', 'http://localhost:3000'),
        // Documented development variant. Cookies are host scoped, so a single
        // flow must not mix localhost and 127.0.0.1.
        'http://127.0.0.1:3000',
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
