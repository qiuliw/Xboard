<?php

namespace App\Services\RealName;

use Illuminate\Support\Facades\Http;

class AlipayCertVerifyClient
{
    private string $appId;
    private string $privateKey;
    private string $alipayPublicKey;
    private string $signType = 'RSA2';
    private string $gateway = 'https://openapi.alipay.com/gateway.do';

    public function __construct(string $appId, string $privateKey, string $alipayPublicKey)
    {
        $this->appId = $appId;
        $this->privateKey = $privateKey;
        $this->alipayPublicKey = $alipayPublicKey;
    }

    /**
     * 实名证件信息比对验证预咨询
     *
     * @return array{verify_id: string}
     */
    public function preconsult(string $userName, string $certNo, string $certType = 'IDENTITY_CARD'): array
    {
        $response = $this->request('alipay.user.certdoc.certverify.preconsult', [
            'user_name' => $userName,
            'cert_type' => $certType,
            'cert_no' => $certNo,
        ]);

        if (empty($response['verify_id'])) {
            throw new \RuntimeException('支付宝未返回 verify_id');
        }

        return ['verify_id' => $response['verify_id']];
    }

    /**
     * 用 auth_code 换取 access_token
     *
     * @return array{access_token: string}
     */
    public function exchangeToken(string $authCode): array
    {
        $params = $this->buildCommonParams('alipay.system.oauth.token');
        $params['grant_type'] = 'authorization_code';
        $params['code'] = $authCode;
        unset($params['biz_content']);
        $params['sign'] = $this->sign($this->buildQuery($params));

        $response = Http::asForm()->post($this->gateway, $params)->json();
        $payload = $response['alipay_system_oauth_token_response'] ?? null;
        if (!$payload || empty($payload['access_token'])) {
            $error = $response['error_response']['sub_msg']
                ?? $response['error_response']['msg']
                ?? '换取授权令牌失败';
            throw new \RuntimeException($error);
        }

        return ['access_token' => $payload['access_token']];
    }

    /**
     * 实名证件信息比对验证咨询
     *
     * @return array{passed: bool, fail_reason?: string}
     */
    public function consult(string $verifyId, string $accessToken): array
    {
        $response = $this->request(
            'alipay.user.certdoc.certverify.consult',
            ['verify_id' => $verifyId],
            $accessToken
        );

        $passed = ($response['passed'] ?? 'F') === 'T';

        return [
            'passed' => $passed,
            'fail_reason' => $response['fail_reason'] ?? null,
        ];
    }

    public function buildAuthUrl(string $verifyId, string $redirectUri): string
    {
        $query = http_build_query([
            'app_id' => $this->appId,
            'scope' => 'id_verify',
            'redirect_uri' => $redirectUri,
            'cert_verify_id' => $verifyId,
        ]);

        return 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?' . $query;
    }

    private function request(string $method, array $bizContent, ?string $authToken = null): array
    {
        $params = $this->buildCommonParams($method, $bizContent, $authToken);
        $params['sign'] = $this->sign($this->buildQuery($params));

        $response = Http::asForm()->post($this->gateway, $params)->json();
        $resKey = str_replace('.', '_', $method) . '_response';
        if (!isset($response[$resKey])) {
            $error = $response['error_response']['sub_msg']
                ?? $response['error_response']['msg']
                ?? '从支付宝请求失败';
            throw new \RuntimeException($error);
        }

        $payload = $response[$resKey];
        if (($payload['code'] ?? '') !== '10000' && ($payload['msg'] ?? '') !== 'Success') {
            throw new \RuntimeException($payload['sub_msg'] ?? $payload['msg'] ?? '支付宝业务失败');
        }

        return $payload;
    }

    private function buildCommonParams(string $method, array $bizContent = [], ?string $authToken = null): array
    {
        $params = [
            'app_id' => $this->appId,
            'method' => $method,
            'charset' => 'UTF-8',
            'sign_type' => $this->signType,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
        ];

        if (!empty($bizContent)) {
            $params['biz_content'] = json_encode($bizContent, JSON_UNESCAPED_UNICODE);
        }

        if ($authToken) {
            $params['auth_token'] = $authToken;
        }

        return $params;
    }

    private function buildQuery(array $query): string
    {
        ksort($query);
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === '' || $value === null || $key === 'sign') {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }
        return implode('&', $parts);
    }

    private function sign(string $signData): string
    {
        $privateKey = $this->normalizePrivateKey($this->privateKey);
        $privateId = openssl_pkey_get_private($privateKey);
        if ($privateId === false) {
            throw new \RuntimeException('支付宝私钥格式错误');
        }

        $signature = '';
        openssl_sign($signData, $signature, $privateId, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function normalizePrivateKey(string $privateKey): string
    {
        $privateKey = trim($privateKey);
        if (str_contains($privateKey, 'BEGIN')) {
            return $privateKey;
        }

        $chunks = str_split($privateKey, 64);
        return "-----BEGIN RSA PRIVATE KEY-----\n"
            . implode("\n", $chunks)
            . "\n-----END RSA PRIVATE KEY-----";
    }
}
