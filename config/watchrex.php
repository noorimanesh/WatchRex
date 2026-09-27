<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WatchRex — Infrastructure & Uptime Monitoring
    |--------------------------------------------------------------------------
    | Developed and maintained by Fabapars (https://fabapars.com)
    */

    'version' => '1.0.0',

    'vendor' => [
        'name' => 'Fabapars',
        'url' => 'https://fabapars.com',
    ],

    // Queue used for monitor checks. Run a dedicated worker:
    // php artisan queue:work --queue=checks,alerts,default
    'queues' => [
        'checks' => env('WATCHREX_CHECK_QUEUE', 'checks'),
        'alerts' => env('WATCHREX_ALERT_QUEUE', 'alerts'),
        'domains' => env('WATCHREX_DOMAIN_QUEUE', 'default'),
    ],

    // Name of this probe location (used for multi-location monitoring).
    'location' => env('WATCHREX_LOCATION', 'local'),
    'location_label' => env('WATCHREX_LOCATION_LABEL', 'Main server'),
    'location_country' => env('WATCHREX_LOCATION_COUNTRY'),

    // Probe mode: this install only runs checks for a central hub.
    'probe' => [
        'hub_url' => env('WATCHREX_HUB_URL'),
        'token' => env('WATCHREX_PROBE_TOKEN'),
        'concurrency' => (int) env('WATCHREX_PROBE_CONCURRENCY', 4),
    ],

    // Visual change detection (headless Chrome/Chromium screenshots, optional).
    'screenshots' => [
        'chrome' => env('WATCHREX_CHROME_PATH'),
        'tenants' => (bool) env('WATCHREX_SCREENSHOTS_FOR_TENANTS', false),
        'width' => 1366,
        'height' => 900,
        'keep' => 5,
    ],

    // OpenID Connect single sign-on (Keycloak, Azure AD / Entra ID, Google, Authentik, Okta…).
    'sso' => [
        'enabled' => (bool) env('OIDC_ENABLED', false),
        'label' => env('OIDC_LABEL', 'SSO'),
        'issuer' => env('OIDC_ISSUER'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'scopes' => env('OIDC_SCOPES', 'openid email profile'),
        'auto_create' => (bool) env('OIDC_AUTO_CREATE', false),
        'allowed_domains' => array_filter(array_map('trim', explode(',', (string) env('OIDC_ALLOWED_DOMAINS', '')))),
        'default_role' => env('OIDC_DEFAULT_ROLE', 'user'),
        'disable_password_login' => (bool) env('OIDC_DISABLE_PASSWORD_LOGIN', false),
    ],

    'retention' => [
        'heartbeats_days' => (int) env('WATCHREX_HEARTBEAT_DAYS', 30),
        'server_metrics_days' => (int) env('WATCHREX_METRIC_DAYS', 14),
        'daily_stats_days' => (int) env('WATCHREX_DAILY_STATS_DAYS', 400),
        'audit_days' => (int) env('WATCHREX_AUDIT_DAYS', 180),
    ],

    'defaults' => [
        'interval' => 60,
        'timeout' => 10,
        'retries' => 1,
        'ssl_warn_days' => 14,
        'domain_warn_days' => 30,
        'user_agent' => 'WatchRex/1.0 (+https://fabapars.com)',
        'max_body_bytes' => 2 * 1024 * 1024,
    ],

    'domains' => [
        'refresh_hours' => (int) env('WATCHREX_DOMAIN_REFRESH_HOURS', 6),
        'max_subdomains' => (int) env('WATCHREX_MAX_SUBDOMAINS', 60),
        'subdomain_ssl_checks' => (int) env('WATCHREX_SUBDOMAIN_SSL_CHECKS', 25),
        'dkim_selectors' => ['default', 'google', 'selector1', 'selector2', 'k1', 'mail', 'dkim', 's1', 's2', 'x'],
        'rbl' => [
            'zen.spamhaus.org',
            'bl.spamcop.net',
            'b.barracudacentral.org',
            'dnsbl.sorbs.net',
            'psbl.surriel.com',
            'dnsbl-1.uceprotect.net',
        ],
    ],

    // HTTPS geo-IP provider. {ip} is replaced with the address.
    'geoip_url' => env('WATCHREX_GEOIP_URL', 'https://ipwho.is/{ip}'),

    // Block monitors that point to private / reserved networks for non-admin
    // users (SSRF protection for multi-tenant installations).
    'block_private_targets' => (bool) env('WATCHREX_BLOCK_PRIVATE_TARGETS', true),

    // Anomaly detection (EWMA based, O(1) memory per monitor).
    'anomaly' => [
        'enabled' => true,
        'min_samples' => 20,
        'sigma' => 4.0,
        'min_factor' => 2.5,
        'min_ms' => 300,
    ],

    // Iranian national filtering fingerprints (peyvandha redirect).
    'censorship' => [
        'ips' => ['10.10.34.34', '10.10.34.35', '10.10.34.36'],
        'markers' => ['peyvandha.ir', '10.10.34.34', '10.10.34.35', '10.10.34.36'],
    ],

    'plans' => [
        'free' => ['label' => 'Free', 'max_monitors' => 5, 'min_interval' => 300, 'max_servers' => 1, 'max_domains' => 2, 'max_status_pages' => 1],
        'pro' => ['label' => 'Pro', 'max_monitors' => 50, 'min_interval' => 60, 'max_servers' => 5, 'max_domains' => 20, 'max_status_pages' => 3],
        'business' => ['label' => 'Business', 'max_monitors' => 500, 'min_interval' => 30, 'max_servers' => 50, 'max_domains' => 200, 'max_status_pages' => 20],
        'enterprise' => ['label' => 'Enterprise', 'max_monitors' => null, 'min_interval' => 20, 'max_servers' => null, 'max_domains' => null, 'max_status_pages' => null],
    ],

    'default_plan' => env('WATCHREX_DEFAULT_PLAN', 'pro'),

    'locales' => ['fa' => 'فارسی', 'en' => 'English'],

    'security' => [
        'csp' => (bool) env('WATCHREX_CSP', true),
        'hsts' => (bool) env('WATCHREX_HSTS', true),
        'login_attempts' => 5,
    ],
];
