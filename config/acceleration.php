<?php

return [
    'enabled' => (bool) env('ACCELERATION_ENABLED', false),
    'accept_new' => (bool) env('ACCELERATION_ACCEPT_NEW', false),
    'user_ids' => array_filter(array_map('intval', explode(',', (string) env('ACCELERATION_USER_IDS', '')))),
];
