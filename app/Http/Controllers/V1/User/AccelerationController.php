<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\Acceleration\AccelerationException;
use App\Services\Acceleration\CatalogService;
use App\Services\Acceleration\ReleaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AccelerationController extends Controller
{
    public function catalog(Request $request)
    {
        $data = app(CatalogService::class)->get();
        return $this->success($data)->header('Cache-Control', 'private, no-store');
    }

    public function state(Request $request)
    {
        $release = DB::table('accel_releases')->where('user_id', $request->user()->id)->orderByDesc('created_at')->orderByDesc('version')->first();
        if ($release?->status === 'pending') app(ReleaseService::class)->activate($release->id);
        $release = $release ? DB::table('accel_releases')->where('id', $release->id)->first() : null;
        $state = DB::table('accel_states')->where('user_id', $request->user()->id)->first();
        return $this->success(['version' => $state?->version ?? 0, 'application_ids' => json_decode($state?->preferences ?? '[]', true),
            'release' => $release ? ['release_id' => $release->id, 'version' => $release->version, 'status' => $release->status] : null]);
    }

    public function preferences(Request $request)
    {
        $data = $this->selection($request);
        return DB::transaction(function () use ($request, $data) {
            $uid = $request->user()->id;
            DB::table('accel_states')->insertOrIgnore(['user_id' => $uid, 'version' => 0]);
            $state = DB::table('accel_states')->where('user_id', $uid)->lockForUpdate()->first();
            if ($state->version !== $data['expected_version']) throw new AccelerationException('VERSION_CONFLICT');
            if (DB::table('accel_releases')->where('user_id', $uid)->where('status', 'pending')->exists()) throw new AccelerationException('PREPARATION_IN_PROGRESS');
            DB::table('accel_states')->where('user_id', $uid)->update(['preferences' => json_encode($data['application_ids']), 'version' => $state->version + 1]);
            return $this->success(['version' => $state->version + 1]);
        });
    }

    public function prepare(Request $request)
    {
        $data = $this->selection($request);
        $key = (string) $request->header('Idempotency-Key');
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key)) throw new AccelerationException('INVALID_IDEMPOTENCY_KEY', 422);
        return $this->success(app(ReleaseService::class)->prepare($request->user(), $data['application_ids'], $data['expected_version'], $key));
    }

    public function config(Request $request)
    {
        // Eligibility and grant checks must happen before ETag comparison.
        $data = app(ReleaseService::class)->activeConfig($request->user());
        $etag = '"' . hash('sha256', json_encode($data)) . '"';
        if ($request->header('If-None-Match') === $etag) return response('', 304)->header('Cache-Control', 'private, no-cache')->header('ETag', $etag);
        return $this->success($data)->header('Cache-Control', 'private, no-cache')->header('ETag', $etag);
    }

    public function ack(Request $request)
    {
        $data = $request->validate(['release_id' => 'required|string|max:64', 'version' => 'required|integer|min:1', 'result' => 'required|in:applied,failed', 'error_code' => 'nullable|string|regex:/^[A-Z0-9_]{1,64}$/']);
        $owned = DB::table('accel_releases')->where('id', $data['release_id'])->where('version', $data['version'])->where('user_id', $request->user()->id)->exists();
        if (!$owned) throw new AccelerationException('RELEASE_NOT_FOUND', 404);
        Cache::put('acceleration:ack:' . $request->user()->id, $data, 3600);
        return $this->success(true); // Diagnostics only, never activates a release.
    }

    public function measurements(Request $request)
    {
        $data = $request->validate(['entrance_id' => 'required|integer|min:1', 'samples' => 'required|array|max:3', 'samples.*' => 'integer|min:0|max:3000']);
        $allowed = DB::table('accel_grants')->where('user_id', $request->user()->id)->where('lane_id', $data['entrance_id'])->where('valid_until', '>', now())->exists();
        if (!$allowed) throw new AccelerationException('ENTRANCE_NOT_FOUND', 404);
        Cache::put('acceleration:measurement:' . $request->user()->id, $data, 90);
        return $this->success(true);
    }

    private function selection(Request $request): array
    {
        return $request->validate(['application_ids' => 'required|array|max:20', 'application_ids.*' => 'required|string|max:64|regex:/^[a-zA-Z0-9_-]+$/|distinct', 'expected_version' => 'required|integer|min:0']);
    }
}
