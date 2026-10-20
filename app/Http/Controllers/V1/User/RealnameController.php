<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\Realname\RealnameException;
use App\Services\Realname\RealnameService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class RealnameController extends Controller
{
    public function __construct(private RealnameService $service)
    {
    }

    public function status(Request $request)
    {
        $this->limit($request, 'status', 60);
        return $this->safely(fn () => $this->service->status($request->user()));
    }

    public function start(Request $request)
    {
        $this->limit($request, 'start', 5);
        // Never accept identity material via query strings or put validation input in a session.
        $input = $request->isJson() ? $request->json()->all() : $request->request->all();
        $input['idempotency_key'] = $request->header('Idempotency-Key');
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/u'],
            'id_number' => ['required', 'string', 'regex:/^(?:[0-9]{15}|[0-9]{17}[0-9Xx])$/'],
            'consent' => ['required', function ($attribute, $value, $fail) {
                if ($value !== true) {
                    $fail('必须明确同意实名认证');
                }
            }],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        // Minimise lifetime on the request, including downstream log middleware.
        $request->request->remove('name');
        $request->request->remove('id_number');
        $request->json()->remove('name');
        $request->json()->remove('id_number');
        if ($request->query->has('name') || $request->query->has('id_number') || $validator->fails()) {
            throw new RealnameException('REALNAME_INVALID_INPUT', '请填写有效身份信息、同意声明和请求标识', 422);
        }
        return $this->safely(fn () => $this->service->start(
            $request->user(), trim($input['name']), strtoupper(trim($input['id_number'])), $input['idempotency_key'], $request->ip()
        ));
    }

    public function refresh(Request $request)
    {
        $this->limit($request, 'refresh', 12);
        $validator = Validator::make($request->all(), ['attempt_id' => ['required', 'uuid']]);
        if ($validator->fails()) {
            throw new RealnameException('REALNAME_INVALID_INPUT', '认证记录标识无效', 422);
        }
        return $this->safely(fn () => $this->service->refresh($request->user(), $request->input('attempt_id')));
    }

    private function limit(Request $request, string $action, int $maximum): void
    {
        if (!RateLimiter::attempt('realname:' . $action . ':' . $request->user()->id, $maximum, static fn () => true, 60)) {
            throw new RealnameException('REALNAME_RATE_LIMITED', '请求过于频繁，请稍后再试', 429);
        }
    }

    private function safely(callable $operation)
    {
        try {
            return $this->respond($operation());
        } catch (RealnameException $exception) {
            throw $exception;
        } catch (\Throwable) {
            // QueryException bindings/SDK error traces may contain sensitive material.
            // Do not log or chain the original throwable, even with APP_DEBUG enabled.
            throw new RealnameException('REALNAME_UNAVAILABLE', '实名认证服务暂不可用', 503);
        }
    }

    private function respond(array $data)
    {
        return $this->success($data)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }
}
