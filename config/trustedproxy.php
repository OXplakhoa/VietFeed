<?php

// Read by Illuminate\Http\Middleware\TrustProxies as its fallback when no proxies are set in code.
// Empty by default (trust nobody). The self-hosted demo sets TRUSTED_PROXIES=127.0.0.1,::1 because
// cloudflared and Caddy both reach php-fpm from loopback (see deploy/env.demo.example).
// Kept in config (not env() in bootstrap/app.php) so it survives `php artisan config:cache`.
return [
    'proxies' => env('TRUSTED_PROXIES'),
];
