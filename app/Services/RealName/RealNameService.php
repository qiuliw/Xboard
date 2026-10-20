<?php

namespace App\Services\RealName;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class RealNameService
{
    public const ERROR_CODE = 40301;

    public function isRequired(): bool
    {
        $setting = admin_setting('real_name_verify', null);
        if ($setting !== null && $setting !== '') {
            return (bool) $setting;
        }

        return $this->envBool('REALNAME_ENABLED')
            || $this->envBool('REALNAME_REQUIRE_PURCHASE')
            || $this->envBool('REALNAME_REQUIRE_SUBSCRIPTION');
    }

    public function isVerified(?User $user): bool
    {
        return (bool) ($user?->real_name_verified);
    }

    public function assertVerified(?User $user): void
    {
        if ($this->isRequired() && !$this->isVerified($user)) {
            throw new ApiException(__('请先完成实名认证'), self::ERROR_CODE);
        }
    }

    public function makeClient(): AlipayCertVerifyClient
    {
        $appId = $this->configValue('alipay_real_name_app_id', 'REALNAME_ALIPAY_APP_ID');
        $privateKey = $this->configValue('alipay_real_name_private_key', 'REALNAME_ALIPAY_PRIVATE_KEY');
        $publicKey = $this->configValue('alipay_real_name_public_key', 'REALNAME_ALIPAY_PUBLIC_KEY');
        $bizCode = $this->configValue('alipay_real_name_biz_code', 'REALNAME_ALIPAY_BIZ_CODE') ?: 'FACE';

        if ($appId === '' || $privateKey === '' || $publicKey === '') {
            throw new ApiException(__('实名认证未配置支付宝应用参数'), 500);
        }

        return new AlipayCertVerifyClient($appId, $privateKey, $publicKey, $bizCode);
    }

    public function maskIdNumber(string $certNo): string
    {
        $len = strlen($certNo);
        if ($len < 8) {
            return str_repeat('*', $len);
        }

        return substr($certNo, 0, 4) . str_repeat('*', $len - 8) . substr($certNo, -4);
    }

    public function hashIdNumber(string $certNo): string
    {
        return hash('sha256', strtoupper(trim($certNo)));
    }

    public function cachePending(string $certifyId, array $payload, int $ttl = 86400): void
    {
        Cache::put($this->cacheKey($certifyId), $payload, $ttl);
        if (!empty($payload['outer_order_no'])) {
            Cache::put($this->orderCacheKey($payload['outer_order_no']), $certifyId, $ttl);
        }
        if (!empty($payload['user_id'])) {
            Cache::put($this->userCacheKey((int) $payload['user_id']), $certifyId, $ttl);
        }
    }

    public function getPending(string $certifyId): ?array
    {
        $data = Cache::get($this->cacheKey($certifyId));
        return is_array($data) ? $data : null;
    }

    public function getPendingByOrderNo(string $orderNo): ?array
    {
        $certifyId = Cache::get($this->orderCacheKey($orderNo));
        if (!$certifyId) {
            return null;
        }

        $pending = $this->getPending((string) $certifyId);
        if ($pending) {
            $pending['certify_id'] = (string) $certifyId;
        }

        return $pending;
    }

    public function forgetPending(string $certifyId): void
    {
        $pending = $this->getPending($certifyId);
        Cache::forget($this->cacheKey($certifyId));
        if (!$pending) {
            return;
        }
        if (!empty($pending['outer_order_no'])) {
            Cache::forget($this->orderCacheKey($pending['outer_order_no']));
        }
        if (!empty($pending['user_id'])) {
            Cache::forget($this->userCacheKey((int) $pending['user_id']));
        }
    }

    public function completeIfPassed(string $certifyId): bool
    {
        $pending = $this->getPending($certifyId);
        if (!$pending) {
            return false;
        }

        $result = $this->makeClient()->query($certifyId);
        if (!$result['passed']) {
            return false;
        }

        $user = User::find($pending['user_id'] ?? 0);
        if (!$user) {
            return false;
        }

        if (!$user->real_name_verified) {
            $conflict = User::where('id_number_hash', $pending['id_number_hash'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($conflict) {
                throw new ApiException(__('该身份证号已绑定其他账号'));
            }

            $user->real_name_verified = true;
            $user->real_name = $pending['real_name'];
            $user->id_number_hash = $pending['id_number_hash'];
            $user->id_number_mask = $pending['id_number_mask'];
            $user->real_name_verified_at = time();
            $user->save();
        }

        $this->forgetPending($certifyId);
        return true;
    }

    public function syncPendingForUser(User $user): void
    {
        if ($this->isVerified($user)) {
            return;
        }

        $certifyId = Cache::get($this->userCacheKey((int) $user->id));
        if (!$certifyId) {
            return;
        }

        try {
            $this->completeIfPassed((string) $certifyId);
        } catch (\Throwable) {
            // status 轮询时忽略瞬时错误
        }
    }

    public function callbackUrl(): string
    {
        $configured = $this->configValue('alipay_real_name_redirect_uri', 'REALNAME_RETURN_URL');
        if ($configured !== '') {
            if (str_contains($configured, '/api/v1/guest/realName/callback')) {
                return rtrim($configured, '/');
            }
            if (preg_match('#^https?://[^/]+/?$#', $configured)) {
                return rtrim($configured, '/') . '/api/v1/guest/realName/callback';
            }
            if (str_contains($configured, '/realname/return')) {
                return $this->publicBaseUrl() . '/api/v1/guest/realName/callback';
            }
            return rtrim($configured, '/');
        }

        return $this->publicBaseUrl() . '/api/v1/guest/realName/callback';
    }

    public function startUrl(string $orderNo): string
    {
        return $this->publicBaseUrl()
            . '/api/v1/guest/realName/start?order_no='
            . rawurlencode($orderNo);
    }

    private function publicBaseUrl(): string
    {
        foreach ([
            $this->configValue('alipay_real_name_redirect_uri', 'REALNAME_RETURN_URL'),
            (string) (admin_setting('app_url') ?: ''),
            (string) env('APP_URL', ''),
        ] as $candidate) {
            if ($candidate !== '' && preg_match('#^(https?://[^/]+)#', $candidate, $m)) {
                if (!str_contains($m[1], 'localhost')) {
                    return $m[1];
                }
            }
        }

        throw new ApiException(__('请配置 app_url 或 alipay_real_name_redirect_uri'), 500);
    }

    private function configValue(string $settingKey, string $envKey): string
    {
        $fromSetting = admin_setting($settingKey, null);
        if ($fromSetting !== null && $fromSetting !== '') {
            return trim((string) $fromSetting);
        }

        return trim((string) env($envKey, ''));
    }

    private function envBool(string $key): bool
    {
        return filter_var(env($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    private function cacheKey(string $certifyId): string
    {
        return CacheKey::get('REAL_NAME_VERIFY', $certifyId);
    }

    private function orderCacheKey(string $orderNo): string
    {
        return CacheKey::get('REAL_NAME_VERIFY', 'order:' . $orderNo);
    }

    private function userCacheKey(int $userId): string
    {
        return CacheKey::get('REAL_NAME_VERIFY', 'user:' . $userId);
    }
}
