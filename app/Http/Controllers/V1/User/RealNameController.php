<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
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

    public function status(Request $request)
    {
        $user = $request->user();
        $this->realNameService->syncPendingForUser($user);
        $user->refresh();

        return $this->success([
            'required' => $this->realNameService->isRequired(),
            'verified' => $this->realNameService->isVerified($user),
            'real_name' => $user->real_name,
            'id_number_mask' => $user->id_number_mask,
            'verified_at' => $user->real_name_verified_at,
        ]);
    }

    public function preconsult(Request $request)
    {
        $request->validate([
            'real_name' => 'required|string|min:2|max:32',
            'cert_no' => ['required', 'string', 'regex:/^\d{17}[\dXx]$/'],
        ], [
            'cert_no.regex' => __('身份证号格式不正确'),
        ]);

        $user = $request->user();
        if ($this->realNameService->isVerified($user)) {
            return $this->success([
                'verified' => true,
                'message' => __('已完成实名认证'),
            ]);
        }

        $realName = trim($request->input('real_name'));
        $certNo = strtoupper(trim($request->input('cert_no')));
        $hash = $this->realNameService->hashIdNumber($certNo);

        if (User::where('id_number_hash', $hash)->where('id', '!=', $user->id)->exists()) {
            throw new ApiException(__('该身份证号已绑定其他账号'));
        }

        try {
            $client = $this->realNameService->makeClient();
            $outerOrderNo = $client->generateOuterOrderNo();
            $callbackUrl = $this->realNameService->callbackUrl()
                . '?order_no=' . rawurlencode($outerOrderNo);

            $result = $client->initialize($realName, $certNo, $callbackUrl, $outerOrderNo);
            $certifyId = $result['certify_id'];

            $this->realNameService->cachePending($certifyId, [
                'user_id' => $user->id,
                'real_name' => $realName,
                'cert_no' => $certNo,
                'id_number_hash' => $hash,
                'id_number_mask' => $this->realNameService->maskIdNumber($certNo),
                'outer_order_no' => $outerOrderNo,
                'certify_id' => $certifyId,
            ]);

            return $this->success([
                'verify_id' => $certifyId,
                'auth_url' => $this->realNameService->startUrl($outerOrderNo),
            ]);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException($e->getMessage() ?: __('发起实名认证失败'));
        }
    }
}
