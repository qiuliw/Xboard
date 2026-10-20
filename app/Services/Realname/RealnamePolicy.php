<?php

namespace App\Services\Realname;

use App\Models\RealnameVerification;
use App\Models\User;
use Throwable;

class RealnamePolicy
{
    public function purchase(User $user): void
    {
        $this->enforce($user, (bool) config('realname.require_purchase'));
    }

    public function subscription(User $user): void
    {
        $this->enforce($user, (bool) config('realname.require_subscription'));
    }

    private function enforce(User $user, bool $required): void
    {
        if (!$required) {
            return;
        }
        try {
            // Local trusted state only; provider outages never revoke verified users.
            if (RealnameVerification::where('user_id', $user->id)->whereNotNull('verified_at')->exists()) {
                return;
            }
        } catch (Throwable) {
            // Missing migration/database outage must not turn enforcement into fail-open.
        }
        throw new RealnameException('REALNAME_REQUIRED', '请先完成实名认证', 403);
    }
}
