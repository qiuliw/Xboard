<?php

namespace App\Services\Acceleration;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function reserve(User $user, array $applicationIds, string $releaseId): array
    {
        $applicationIds = array_values(array_unique($applicationIds));
        sort($applicationIds);
        return DB::transaction(function () use ($user, $applicationIds, $releaseId) {
            DB::table('accel_states')->insertOrIgnore(['user_id' => $user->id, 'version' => 0]);
            DB::table('accel_states')->where('user_id', $user->id)->lockForUpdate()->first();
            $result = [];
            foreach ($applicationIds as $appId) {
                $app = DB::table('accel_applications')->where('id', $appId)->where('enabled', true)->first();
                if (!$app) throw new AccelerationException('APPLICATION_UNAVAILABLE', 422);
                $existing = DB::table('accel_reservations')->where('release_id', $releaseId)->where('application_id', $appId)->first();
                if ($existing && $existing->user_id !== $user->id) throw new AccelerationException('RELEASE_CONFLICT');
                $current = $existing ?? DB::table('accel_assignments')->where('user_id', $user->id)->where('application_id', $appId)->first();
                $resources = DB::table('accel_resources')->where('status', 'healthy')->whereIn('country', json_decode($app->countries, true))->orderBy('id')->lockForUpdate()->get();
                $lane = null;
                foreach ($resources as $resource) {
                    $lanes = DB::table('accel_lanes')->where('resource_id', $resource->id)->where('status', 'healthy')->orderBy('id')->get();
                    if ($current) {
                        $lane = $lanes->firstWhere('id', $current->lane_id);
                        if ($lane) break;
                        continue; // Existing binding never silently migrates.
                    }
                    $keys = [];
                    foreach (['accel_assignments', 'accel_reservations'] as $table) {
                        foreach (DB::table($table)->whereIn('lane_id', $lanes->pluck('id'))->get() as $binding) {
                            $keys[$binding->user_id . ':' . $binding->application_id] = true;
                        }
                    }
                    if (count($keys) < $resource->capacity && $lanes->isNotEmpty()) { $lane = $lanes->first(); break; }
                }
                if (!$lane) throw new AccelerationException($current ? 'BOUND_LANE_UNAVAILABLE' : 'NO_CAPACITY', 503);
                DB::table('accel_reservations')->insertOrIgnore(['user_id' => $user->id, 'application_id' => $appId, 'lane_id' => $lane->id, 'release_id' => $releaseId]);
                $result[] = ['application_id' => $appId, 'lane_id' => $lane->id];
            }
            return $result;
        }, 3);
    }

    public function activate(User $user, string $releaseId): void
    {
        // Caller must hold the user's state row lock and verify deployment first.
        foreach (DB::table('accel_reservations')->where('user_id', $user->id)->where('release_id', $releaseId)->get() as $row) {
            DB::table('accel_assignments')->updateOrInsert(['user_id' => $user->id, 'application_id' => $row->application_id], ['lane_id' => $row->lane_id, 'release_id' => $releaseId]);
        }
        DB::table('accel_reservations')->where('user_id', $user->id)->where('release_id', $releaseId)->delete();
    }
}
