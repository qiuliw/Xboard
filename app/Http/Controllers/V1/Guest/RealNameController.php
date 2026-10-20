<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\RealName\RealNameService;
use Illuminate\Http\Request;

class RealNameController extends Controller
{
    public function __construct(
        private readonly RealNameService $realNameService
    ) {
    }

    /** 短链入口，302 到支付宝认证页 */
    public function start(Request $request)
    {
        $orderNo = (string) $request->input('order_no', '');
        $certifyId = (string) $request->input('certify_id', '');

        $pending = null;
        if ($orderNo !== '') {
            $pending = $this->realNameService->getPendingByOrderNo($orderNo);
            $certifyId = (string) ($pending['certify_id'] ?? $certifyId);
        } elseif ($certifyId !== '') {
            $pending = $this->realNameService->getPending($certifyId);
        }

        if (!$pending || $certifyId === '') {
            return $this->htmlPage(false, '实名认证链接已失效，请返回客户端重新发起。');
        }

        try {
            $callbackUrl = $this->realNameService->callbackUrl()
                . '?order_no=' . rawurlencode((string) ($pending['outer_order_no'] ?? $orderNo));
            return redirect()->away(
                $this->realNameService->makeClient()->buildCertifyUrl($certifyId, $callbackUrl)
            );
        } catch (\Throwable $e) {
            return $this->htmlPage(false, $e->getMessage() ?: '无法打开支付宝认证页');
        }
    }

    /** 认证回跳：查询结果并落库 */
    public function callback(Request $request)
    {
        $certifyId = $request->input('certify_id')
            ?: $request->input('cert_verify_id')
            ?: $request->input('verify_id');
        $orderNo = $request->input('order_no');

        $pending = null;
        if ($certifyId) {
            $pending = $this->realNameService->getPending((string) $certifyId);
            if ($pending) {
                $pending['certify_id'] = (string) $certifyId;
            }
        }
        if (!$pending && $orderNo) {
            $pending = $this->realNameService->getPendingByOrderNo((string) $orderNo);
            $certifyId = $pending['certify_id'] ?? $certifyId;
        }

        if (!$pending || !$certifyId) {
            return $this->htmlPage(false, '实名认证会话已过期，请返回客户端重新发起。');
        }

        try {
            if ($this->realNameService->completeIfPassed((string) $certifyId)) {
                return $this->htmlPage(true, '实名认证成功，请返回客户端继续操作。');
            }

            $result = $this->realNameService->makeClient()->query((string) $certifyId);
            $reason = $result['fail_reason'] ?: '尚未完成人脸认证或认证未通过';
            return $this->htmlPage(false, $reason . '，请返回客户端重试或等待自动刷新。');
        } catch (\Throwable $e) {
            return $this->htmlPage(false, $e->getMessage() ?: '实名认证失败，请稍后重试。');
        }
    }

    private function htmlPage(bool $success, string $message)
    {
        $title = $success ? '实名认证成功' : '实名认证失败';
        $color = $success ? '#16a34a' : '#dc2626';

        return response(
            '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title) . '</title>'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;'
            . 'background:#f8fafc;color:#0f172a}.card{max-width:420px;padding:32px;background:#fff;'
            . 'border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08);text-align:center}'
            . 'h1{font-size:22px;margin:0 0 12px;color:' . $color . '}p{margin:0;line-height:1.6;color:#475569}</style>'
            . '</head><body><div class="card"><h1>' . htmlspecialchars($title) . '</h1><p>'
            . htmlspecialchars($message) . '</p></div></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }
}
