<?php

namespace App\Services\Realname;

use Alipay\EasySDK\Kernel\Config;
use Alipay\EasySDK\Kernel\EasySDKKernel;
use Alipay\EasySDK\Member\Identification\Client;
use Alipay\EasySDK\Member\Identification\Models\IdentityParam;
use Alipay\EasySDK\Member\Identification\Models\MerchantConfig;

class AlipayIdentityProvider implements IdentityProvider
{
    public function assertReady(): void
    {
        foreach (['app_id', 'private_key', 'alipay_public_key', 'biz_code', 'return_url', 'hmac_key'] as $key) {
            if (!is_string(config('realname.' . $key)) || trim(config('realname.' . $key)) === '') {
                throw new RealnameException('REALNAME_UNAVAILABLE', '实名认证服务暂不可用', 503);
            }
        }
        $url = parse_url(config('realname.return_url'));
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !class_exists(Client::class)) {
            throw new RealnameException('REALNAME_UNAVAILABLE', '实名认证服务暂不可用', 503);
        }
    }

    private function client(): Client
    {
        $this->assertReady();
        $config = new Config();
        $config->protocol = 'https';
        $config->gatewayHost = 'openapi.alipay.com';
        $config->appId = config('realname.app_id');
        $config->merchantPrivateKey = config('realname.private_key');
        $config->alipayPublicKey = config('realname.alipay_public_key');
        $config->signType = 'RSA2';
        $config->ignoreSSL = false;
        // Do not use the SDK's static Factory (shared state in Octane/payment plugins).
        return new Client(new EasySDKKernel($config));
    }

    public function initialize(string $outerOrderNo, #[\SensitiveParameter] string $name, #[\SensitiveParameter] string $idNumber): array
    {
        $identity = new IdentityParam();
        $identity->identityType = 'CERT_INFO';
        $identity->certType = 'IDENTITY_CARD';
        $identity->certName = $name;
        $identity->certNo = $idNumber;
        $merchant = new MerchantConfig();
        $merchant->returnUrl = config('realname.return_url');
        // PHP EasySDK calls the initialize API "init". SDK verifies response signatures.
        $response = $this->client()->init($outerOrderNo, config('realname.biz_code'), $identity, $merchant);
        return [
            'accepted' => match ($response->code ?? null) {
                '10000' => true,
                '40004' => false, // signed business rejection, not a system/transport error
                default => null,
            },
            'certify_id' => $response->code === '10000' ? ($response->certifyId ?? null) : null,
        ];
    }

    public function certify(#[\SensitiveParameter] string $certifyId): string
    {
        return $this->client()->certify($certifyId)->body;
    }

    public function query(#[\SensitiveParameter] string $certifyId): string
    {
        $response = $this->client()->query($certifyId);
        return self::interpretQuery($response->code ?? null, $response->passed ?? null, $response->subCode ?? null);
    }

    public static function interpretQuery(?string $code, ?string $passed, ?string $subCode): string
    {
        if ($code === '10000') {
            return match ($passed) {
                'T' => 'passed',
                // F is not passed; without contract-specific finality, it may still be incomplete.
                'F' => 'unknown',
                default => 'unknown',
            };
        }
        if ($code === '40004' && $subCode === 'CERTIFY_ID_EXPIRED') {
            return 'expired';
        }
        return 'unknown';
    }
}
