<?php

return [
    'headers_enabled' => env('SECURITY_HEADERS_ENABLED', true),
    'csp_report_only' => env('CSP_REPORT_ONLY', true),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
