<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RealName\RealNameService;
use Illuminate\Http\Request;

class RealNameController extends Controller
{
    public function __construct(
        private readonly RealNameService $realNameService
    ) {
    }

    /**
     * 支付宝授权回调：完成证件比对并标记用户已实名
     */
    public function callback(Request $request)
    {
        $authCode = $request->input('auth_code');
        $verifyId = $request->input('cert_verify_id') ?: $request->input('verify_id');

        if (!$authCode || !$verifyId) {
            return $this->htmlPage(false, '缺少授权参数，请返回客户端重新发起实名认证。');
        }

        $pending = $this->realNameService->getPending($verifyId);
        if (!$pending) {
            return $this->htmlPage(false, '实名认证会话已过期，请返回客户端重新发起。');
        }

        try {
            $client = $this->realNameService->makeClient();
            $token = $client->exchangeToken($authCode);
            $consult = $client->consult($verifyId, $token['access_token']);

            if (!$consult['passed']) {
                $reason = $consult['fail_reason'] ?: '实名信息不一致';
                return $this->htmlPage(false, $reason . '，请返回客户端重试。');
            }

            $user = User::find($pending['user_id']);
            if (!$user) {
                return $this->htmlPage(false, '用户不存在。');
            }

            if (!$user->real_name_verified) {
                $conflict = User::where('id_number_hash', $pending['id_number_hash'])
                    ->where('id', '!=', $user->id)
                    ->exists();
                if ($conflict) {
                    return $this->htmlPage(false, '该身份证号已绑定其他账号。');
                }

                $user->real_name_verified = true;
                $user->real_name = $pending['real_name'];
                $user->id_number_hash = $pending['id_number_hash'];
                $user->id_number_mask = $pending['id_number_mask'];
                $user->real_name_verified_at = time();
                $user->save();
            }

            $this->realNameService->forgetPending($verifyId);

            return $this->htmlPage(true, '实名认证成功，请返回客户端继续操作。');
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
