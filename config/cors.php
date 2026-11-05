<?php

use App\Support\CorsOrigins;

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | greyon-engine routes live on the web stack (/login, /admin/*, /catalog…).
    | Quasar may run on any localhost port (9000 default, 9400 in local tests).
    |
    | Room panoramas are static files under /storage/uploads/rooms. nginx
    | serves those directly, so it does not see this middleware. Boot
    | renders the same allowlist into /etc/nginx-storage-cors.conf
    | (`php artisan storage:cors-map`). Keep both in App\Support\CorsOrigins.
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => CorsOrigins::allowed(),

    'allowed_origins_patterns' => CorsOrigins::patterns(),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
