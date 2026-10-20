<?php

namespace App\Services\RealName;

use Illuminate\Support\Facades\Http;

/**
 * 支付宝身份验证：alipay.user.certify.open.*（默认 biz_code=FACE）
 */
class AlipayCertVerifyClient
{
    private string $appId;
    private string $privateKey;
    private string $alipayPublicKey;
    private string $bizCode;
    private string $signType = 'RSA2';
    private string $charset = 'utf-8';
    private string $gateway = 'https://openapi.alipay.com/gateway.do';

    public function __construct(
        string $appId,
        string $privateKey,
        string $alipayPublicKey,
        string $bizCode = 'FACE'
    ) {
        $this->appId = $appId;
        $this->privateKey = $privateKey;
        $this->alipayPublicKey = $alipayPublicKey;
        $this->bizCode = $bizCode !== '' ? $bizCode : 'FACE';
    }

    /**
     * @return array{certify_id: string, verify_id: string, outer_order_no: string}
     */
    public function initialize(
        string $userName,
        string $certNo,
        string $returnUrl,
        ?string $outerOrderNo = null,
        string $certType = 'IDENTITY_CARD'
    ): array {
        $outerOrderNo = $outerOrderNo ?: $this->generateOuterOrderNo();

        $response = $this->request('alipay.user.certify.open.initialize', [
            'outer_order_no' => $outerOrderNo,
            'biz_code' => $this->bizCode,
            'identity_param' => [
                'identity_type' => 'CERT_INFO',
                'cert_type' => $certType,
                'cert_name' => $userName,
                'cert_no' => $certNo,
            ],
            'merchant_config' => [
                'return_url' => $returnUrl,
            ],
        ]);

        $certifyId = (string) ($response['certify_id'] ?? '');
        if ($certifyId === '') {
            throw new \RuntimeException('支付宝未返回 certify_id');
        }

        return [
            'certify_id' => $certifyId,
            'verify_id' => $certifyId,
            'outer_order_no' => $outerOrderNo,
        ];
    }

    /** 等价 SDK pageExecute(GET)：签名用原值，URL 用 urlencode */
    public function buildCertifyUrl(string $certifyId, ?string $returnUrl = null): string
    {
        $params = $this->buildCommonParams('alipay.user.certify.open.certify', [
            'certify_id' => $certifyId,
        ]);
        if ($returnUrl) {
            $params['return_url'] = $returnUrl;
        }
        $params['sign'] = $this->sign($this->buildSignContent($params));

        return $this->gateway . '?' . $this->buildQueryUrlEncoded($params);
    }

    /**
     * @return array{passed: bool, fail_reason?: string|null}
     */
    public function query(string $certifyId): array
    {
        $response = $this->request('alipay.user.certify.open.query', [
            'certify_id' => $certifyId,
        ]);

        return [
            'passed' => ($response['passed'] ?? 'F') === 'T',
            'fail_reason' => $response['fail_reason'] ?? null,
        ];
    }

    public function generateOuterOrderNo(): string
    {
        return 'WAF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
    }

    private function request(string $method, array $bizContent): array
    {
        $params = $this->buildCommonParams($method, $bizContent);
        $params['sign'] = $this->sign($this->buildSignContent($params));
        $body = $this->buildQueryUrlEncoded($params);

        $httpResponse = Http::withBody(
            $body,
            'application/x-www-form-urlencoded;charset=' . $this->charset
        )->post($this->gateway);

        $response = $this->decodeResponse($httpResponse->body(), $httpResponse->header('Content-Type'));
        $resKey = str_replace('.', '_', $method) . '_response';

        if (!isset($response[$resKey])) {
            throw new \RuntimeException($this->formatAlipayError(
                $response['error_response'] ?? [],
                '从支付宝请求失败'
            ));
        }

        $payload = $response[$resKey];
        if (($payload['code'] ?? '') !== '10000' && ($payload['msg'] ?? '') !== 'Success') {
            throw new \RuntimeException($this->formatAlipayError($payload, '支付宝业务失败'));
        }

        return $payload;
    }

    private function decodeResponse(string $body, ?string $contentType): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $charset = 'GBK';
        if ($contentType && preg_match('/charset=([^\s;]+)/i', $contentType, $matches)) {
            $charset = strtoupper(trim($matches[1], "\"'"));
        }

        foreach (array_unique([$charset, 'GBK', 'GB2312']) as $from) {
            if (in_array($from, ['UTF-8', 'UTF8'], true)) {
                continue;
            }
            $converted = @mb_convert_encoding($body, 'UTF-8', $from);
            if ($converted === false) {
                continue;
            }
            $decoded = json_decode($converted, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException('支付宝响应解析失败');
    }

    private function formatAlipayError(array $payload, string $fallback): string
    {
        $subCode = (string) ($payload['sub_code'] ?? '');
        $subMsg = (string) ($payload['sub_msg'] ?? $payload['msg'] ?? '');

        if (
            $subCode === 'isv.insufficient-isv-permissions'
            || str_contains($subMsg, '权限不足')
        ) {
            return '支付宝应用未绑定「支付宝身份验证」能力或应用未上线';
        }

        return $subMsg !== '' ? $subMsg : $fallback;
    }

    private function buildCommonParams(string $method, array $bizContent = []): array
    {
        $params = [
            'app_id' => $this->appId,
            'method' => $method,
            'format' => 'JSON',
            'charset' => $this->charset,
            'sign_type' => $this->signType,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
        ];

        if (!empty($bizContent)) {
            $params['biz_content'] = json_encode($bizContent, JSON_UNESCAPED_UNICODE);
        }

        return $params;
    }

    private function buildSignContent(array $query): string
    {
        ksort($query);
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '' || $key === 'sign') {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }
        return implode('&', $parts);
    }

    private function buildQueryUrlEncoded(array $query): string
    {
        ksort($query);
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $key . '=' . urlencode((string) $value);
        }
        return implode('&', $parts);
    }

    private function sign(string $signData): string
    {
        $privateId = openssl_pkey_get_private($this->normalizePrivateKey($this->privateKey));
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
