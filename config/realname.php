<?php

return [
    'enabled' => env('REALNAME_ENABLED', false),
    // Independent from enabled: an unavailable provider must not bypass enforcement.
    'require_purchase' => env('REALNAME_REQUIRE_PURCHASE', false),
    'require_subscription' => env('REALNAME_REQUIRE_SUBSCRIPTION', false),
    'app_id' => env('REALNAME_ALIPAY_APP_ID'),
    'private_key' => env('REALNAME_ALIPAY_PRIVATE_KEY'),
    'alipay_public_key' => env('REALNAME_ALIPAY_PUBLIC_KEY'),
    // No inferred product/contract: obtain this value from the merchant agreement.
    'biz_code' => env('REALNAME_ALIPAY_BIZ_CODE'),
    'return_url' => env('REALNAME_RETURN_URL'),
    'hmac_key' => env('REALNAME_HMAC_KEY'),
    'attempt_ttl_seconds' => 1800,
    'initializing_timeout_seconds' => 90,
    'retry_after_seconds' => 10,
    'initialize_cooldown_seconds' => 60,
    'daily_user_limit' => env('REALNAME_DAILY_USER_LIMIT', 5),
    'daily_ip_limit' => env('REALNAME_DAILY_IP_LIMIT', 20),
];
