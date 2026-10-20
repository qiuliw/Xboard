<?php

namespace App\Services\Realname;

use App\Models\RealnameAttempt;
use App\Models\RealnameVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RealnameService
{
    public function __construct(private IdentityProvider $provider)
    {
    }

    public function status(User $user): array
    {
        $verification = RealnameVerification::find($user->id);
        $attempt = $verification?->current_attempt_id
            ? RealnameAttempt::where('user_id', $user->id)->find($verification->current_attempt_id)
            : null;
        return $this->snapshot($verification, $attempt);
    }

    public function start(User $user, #[\SensitiveParameter] string $name, #[\SensitiveParameter] string $idNumber, #[\SensitiveParameter] string $idempotencyKey, #[\SensitiveParameter] ?string $ip = null): array
    {
        if (!config('realname.enabled')) {
            return $this->status($user);
        }
        // Check configuration before reserving a one-shot initialize operation.
        $this->provider->assertReady();
        $key = config('realname.hmac_key');
        if (!is_string($key) || strlen($key) < 32) {
            throw new RealnameException('REALNAME_UNAVAILABLE', '实名认证服务暂不可用', 503);
        }
        $idempotencyHmac = hash_hmac('sha256', $user->id . ':' . $idempotencyKey, $key);
        $requestHmac = hash_hmac('sha256', json_encode([$user->id, $name, $idNumber], JSON_THROW_ON_ERROR), $key);

        [$attempt, $initialize] = DB::transaction(function () use ($user, $idempotencyHmac, $requestHmac, $ip) {
            $verification = $this->lockVerification($user);
            $previous = RealnameAttempt::where('user_id', $user->id)->where('idempotency_hmac', $idempotencyHmac)->first();
            if ($previous) {
                if (!hash_equals($previous->request_hmac, $requestHmac)) {
                    throw new RealnameException('REALNAME_IDEMPOTENCY_CONFLICT', '请使用新的请求标识', 409);
                }
                return [$previous, false];
            }
            if ($verification->verified_at) {
                return [null, false];
            }
            $current = $verification->current_attempt_id ? RealnameAttempt::find($verification->current_attempt_id) : null;
            if ($current?->isActive()) {
                // Also prevents concurrent calls with different idempotency keys/identities.
                return [$current, false];
            }
            $latest = RealnameAttempt::where('user_id', $user->id)->latest('created_at')->first();
            if ($latest && $latest->created_at->copy()->addSeconds(config('realname.initialize_cooldown_seconds'))->isFuture()) {
                throw new RealnameException('REALNAME_RATE_LIMITED', '请稍后再发起新的认证', 429);
            }
            $this->consumeDailyQuota('user:' . $user->id, (int) config('realname.daily_user_limit'));
            if ($ip !== null) {
                $this->consumeDailyQuota('ip:' . $ip, (int) config('realname.daily_ip_limit'));
            }
            $attempt = RealnameAttempt::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => 'initializing',
                'idempotency_hmac' => $idempotencyHmac,
                'request_hmac' => $requestHmac,
                'outer_order_no' => bin2hex(random_bytes(16)),
            ]);
            $verification->current_attempt_id = $attempt->id;
            $verification->save();
            return [$attempt, true];
        });

        if (!$initialize) {
            return $this->snapshot(RealnameVerification::find($user->id), $attempt);
        }
        try {
            // No transaction retry can repeat this side effect. Never log the exception.
            $result = $this->provider->initialize($attempt->outer_order_no, $name, $idNumber);
        } catch (Throwable) {
            return $this->finishInitialization($user, $attempt, 'uncertain');
        }
        if (($result['accepted'] ?? null) === false) {
            return $this->finishInitialization($user, $attempt, 'failed');
        }
        if (($result['accepted'] ?? null) !== true) {
            return $this->finishInitialization($user, $attempt, 'uncertain');
        }
        $certifyId = $result['certify_id'] ?? null;
        if (!is_string($certifyId) || $certifyId === '' || strlen($certifyId) > 1024) {
            return $this->finishInitialization($user, $attempt, 'uncertain');
        }
        // Persist the certifyId before generating the URL, so a URL failure is recoverable.
        $this->finishInitialization($user, $attempt, 'uncertain', $certifyId);
        try {
            $authUrl = $this->provider->certify($certifyId);
            if (!self::isAllowedAuthUrl($authUrl)) {
                return $this->snapshot(RealnameVerification::find($user->id), $attempt->fresh());
            }
        } catch (Throwable) {
            return $this->snapshot(RealnameVerification::find($user->id), $attempt->fresh());
        }
        return $this->finishInitialization($user, $attempt, 'pending', null, $authUrl);
    }

    public function refresh(User $user, string $attemptId): array
    {
        // Ownership before any provider call (including when the feature is disabled).
        $attempt = RealnameAttempt::where('user_id', $user->id)->find($attemptId);
        if (!$attempt) {
            throw new RealnameException('REALNAME_ATTEMPT_NOT_FOUND', '认证记录不存在', 404);
        }
        if (!config('realname.enabled')) {
            return $this->snapshot(RealnameVerification::find($user->id), $attempt);
        }
        $shouldQuery = DB::transaction(function () use ($user, &$attempt) {
            $verification = $this->lockVerification($user);
            $attempt = RealnameAttempt::where('user_id', $user->id)->lockForUpdate()->findOrFail($attempt->id);
            if ($verification->verified_at || !$attempt->isActive()) {
                return false;
            }
            if ($attempt->status === 'initializing') {
                if ($attempt->created_at->copy()->addSeconds(config('realname.initializing_timeout_seconds'))->isFuture()) {
                    return false;
                }
                // A crashed initialize is not safe to repeat, even without certifyId.
                $attempt->status = 'uncertain';
                $attempt->save();
            }
            if (!$attempt->certify_id || $attempt->next_query_at?->isFuture()) {
                return false;
            }
            $attempt->next_query_at = now()->addSeconds($this->retrySeconds());
            $attempt->save();
            return true;
        });
        if (!$shouldQuery) {
            return $this->snapshot(RealnameVerification::find($user->id), $attempt);
        }
        try {
            $result = $this->provider->query($attempt->certify_id);
        } catch (Throwable) {
            $result = 'unknown';
        }
        DB::transaction(function () use ($user, &$attempt, $result) {
            $verification = $this->lockVerification($user);
            $attempt = RealnameAttempt::where('user_id', $user->id)->lockForUpdate()->findOrFail($attempt->id);
            if (!$attempt->isActive()) {
                return;
            }
            $attempt->status = match ($result) {
                'passed' => 'succeeded',
                'failed' => 'failed',
                'expired' => 'expired',
                default => 'uncertain',
            };
            if ($result === 'passed' && !$verification->verified_at) {
                $verification->verified_at = now();
                $verification->save();
            }
            if (!$attempt->isActive()) {
                $attempt->auth_url = null;
            }
            $attempt->save();
        });
        return $this->snapshot(RealnameVerification::find($user->id), $attempt);
    }

    private function lockVerification(User $user): RealnameVerification
    {
        // An existing trusted user row is the per-user database concurrency lock.
        // Works across all web workers; no dependency on an optional cache/plugin.
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        return RealnameVerification::firstOrCreate(['user_id' => $user->id]);
    }

    private function finishInitialization(User $user, RealnameAttempt $attempt, string $status, #[\SensitiveParameter] ?string $certifyId = null, #[\SensitiveParameter] ?string $authUrl = null): array
    {
        DB::transaction(function () use ($user, &$attempt, $status, $certifyId, $authUrl) {
            $this->lockVerification($user);
            $attempt = RealnameAttempt::where('user_id', $user->id)->lockForUpdate()->findOrFail($attempt->id);
            if (!$attempt->isActive()) {
                return;
            }
            $attempt->status = $status;
            if ($certifyId !== null) {
                $attempt->certify_id = $certifyId;
                $attempt->certify_id_hmac = hash_hmac('sha256', $certifyId, config('realname.hmac_key'));
            }
            if ($authUrl !== null) {
                $attempt->auth_url = $authUrl;
                $attempt->expires_at = now()->addSeconds(config('realname.attempt_ttl_seconds'));
            }
            $attempt->save();
        });
        return $this->snapshot(RealnameVerification::find($user->id), $attempt);
    }

    private function consumeDailyQuota(#[\SensitiveParameter] string $scope, int $maximum): void
    {
        $bucket = hash_hmac('sha256', now()->format('Y-m-d') . ':' . $scope, config('realname.hmac_key'));
        DB::table('realname_initialize_limits')->insertOrIgnore(['bucket' => $bucket, 'used' => 0, 'created_at' => now()]);
        $consumed = DB::table('realname_initialize_limits')->where('bucket', $bucket)
            ->where('used', '<', max(0, $maximum))->increment('used');
        if ($consumed !== 1) {
            throw new RealnameException('REALNAME_RATE_LIMITED', '今日认证次数已达上限', 429);
        }
    }

    public static function isAllowedAuthUrl(string $url): bool
    {
        $parts = parse_url($url);
        return !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && $parts !== false
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'openapi.alipay.com'
            && ($parts['path'] ?? '') === '/gateway.do'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] === 443);
    }

    private function retrySeconds(): int
    {
        return max(1, (int) config('realname.retry_after_seconds', 10));
    }

    private function snapshot(?RealnameVerification $verification, ?RealnameAttempt $attempt): array
    {
        $attemptStatus = $attempt?->status;
        if ($attemptStatus === 'initializing'
            && $attempt->created_at->copy()->addSeconds(config('realname.initializing_timeout_seconds'))->isPast()) {
            $attemptStatus = 'uncertain';
        }
        $status = $verification?->verified_at ? 'verified' : match ($attemptStatus) {
            'initializing', 'pending', 'uncertain' => 'pending',
            'failed' => 'failed',
            'expired' => 'expired',
            default => 'unverified',
        };
        $authUrl = $attempt?->auth_url;
        if (!$attempt?->isActive() || $attempt?->expires_at?->isPast() || !is_string($authUrl) || !self::isAllowedAuthUrl($authUrl)) {
            $authUrl = null;
        }
        return [
            'enabled' => (bool) config('realname.enabled'),
            'required_for_purchase' => (bool) config('realname.require_purchase'),
            'required_for_subscription' => (bool) config('realname.require_subscription'),
            'status' => $status,
            'verified_at' => $verification?->verified_at?->toIso8601String(),
            'attempt' => $attempt ? [
                'id' => $attempt->id,
                'status' => $attemptStatus,
                'auth_url' => $authUrl,
                'expires_at' => $attempt->expires_at?->toIso8601String(),
                'retry_after_seconds' => $attempt->isActive() ? $this->retrySeconds() : 0,
            ] : null,
        ];
    }
}
