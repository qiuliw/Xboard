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
        return (bool) admin_setting('real_name_verify', 0);
    }

    public function isVerified(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return (bool) $user->real_name_verified;
    }

    /**
     * 购买/订阅前校验：开启实名且未通过则抛错
     */
    public function assertVerified(?User $user): void
    {
        if (!$this->isRequired()) {
            return;
        }

        if (!$this->isVerified($user)) {
            throw new ApiException(__('请先完成实名认证'), self::ERROR_CODE);
        }
    }

    public function makeClient(): AlipayCertVerifyClient
    {
        $appId = (string) admin_setting('alipay_real_name_app_id', '');
        $privateKey = (string) admin_setting('alipay_real_name_private_key', '');
        $publicKey = (string) admin_setting('alipay_real_name_public_key', '');

        if ($appId === '' || $privateKey === '' || $publicKey === '') {
            throw new ApiException(__('实名认证未配置支付宝应用参数'), 500);
        }

        return new AlipayCertVerifyClient($appId, $privateKey, $publicKey);
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

    public function cachePending(string $verifyId, array $payload, int $ttl = 7200): void
    {
        Cache::put($this->cacheKey($verifyId), $payload, $ttl);
    }

    public function getPending(string $verifyId): ?array
    {
        $data = Cache::get($this->cacheKey($verifyId));
        return is_array($data) ? $data : null;
    }

    public function forgetPending(string $verifyId): void
    {
        Cache::forget($this->cacheKey($verifyId));
    }

    public function callbackUrl(): string
    {
        $configured = admin_setting('alipay_real_name_redirect_uri');
        if (!empty($configured)) {
            return rtrim((string) $configured, '/');
        }

        return url('/api/v1/guest/realName/callback');
    }

    private function cacheKey(string $verifyId): string
    {
        return CacheKey::get('REAL_NAME_VERIFY', $verifyId);
    }
}
