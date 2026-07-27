<?php

declare(strict_types=1);

/**
 * Runtime-seeded language keys for the eurosite addon.
 *
 * Shape: 'lang.key' => ['en' => '...', 'ro' => '...']. MVP ships the settings
 * labels; storefront/booking labels get added as those surfaces land.
 */

return [
    'eurosite.eurosite'            => ['en' => 'Eurosite', 'ro' => 'Eurosite'],
    'eurosite.api_header'          => ['en' => 'Eurosite API connection', 'ro' => 'Conexiune API Eurosite'],
    'eurosite.api_url'             => ['en' => 'API endpoint URL', 'ro' => 'Adresă endpoint API'],
    'eurosite.api_user'            => ['en' => 'API user', 'ro' => 'Utilizator API'],
    'eurosite.api_password'        => ['en' => 'API password', 'ro' => 'Parolă API'],
    'eurosite.tourop_code'         => ['en' => 'Tour operator code', 'ro' => 'Cod touroperator'],
    'eurosite.default_currency'    => ['en' => 'Default currency', 'ro' => 'Monedă implicită'],
    'eurosite.default_language'    => ['en' => 'Default API language', 'ro' => 'Limbă implicită API'],
    'eurosite.allow_insecure_api'  => ['en' => 'Allow insecure (HTTP) API connection', 'ro' => 'Permite conexiune API nesecurizată (HTTP)'],
    'eurosite.resilience_header'   => ['en' => 'Resilience', 'ro' => 'Reziliență'],
    'eurosite.api_max_retries'     => ['en' => 'Max retries', 'ro' => 'Reîncercări maxime'],
    'eurosite.api_timeout'         => ['en' => 'Request timeout (s)', 'ro' => 'Timeout cerere (s)'],
    'eurosite.circuit_breaker_threshold' => ['en' => 'Circuit breaker threshold', 'ro' => 'Prag circuit breaker'],
    'eurosite.circuit_breaker_timeout'   => ['en' => 'Circuit breaker cooldown (s)', 'ro' => 'Cooldown circuit breaker (s)'],
];
