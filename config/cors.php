<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // از .env خونده می‌شود؛ چندتا دامنه را با کاما جدا کن
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))),

    // الگوهای رجکس (اختیاری) برای اجازه‌دادن به ساب‌دامین‌ها
    'allowed_origins_patterns' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS_PATTERNS', '')))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // کش preflight (۱ روز)
    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    // چون Credentials فعاله، از '*' برای اوریجین استفاده نکن؛
    // دامنه‌ها را دقیق در ENV بده.
    'supports_credentials' => true,
];
