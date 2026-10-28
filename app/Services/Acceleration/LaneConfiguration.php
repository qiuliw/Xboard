<?php

namespace App\Services\Acceleration;

use App\Models\Server;

class LaneConfiguration
{
    public static function hash(Server $server): string
    {
        // Accounting/liveness changes do not change the protocol or authorization.
        $attributes = array_diff_key($server->attributesToArray(), array_flip([
            'u', 'd', 'updated_at', 'last_check_at', 'last_push_at',
        ]));
        ksort($attributes);
        return hash('sha256', json_encode($attributes));
    }
}
