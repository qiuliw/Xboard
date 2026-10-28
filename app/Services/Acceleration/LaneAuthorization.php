<?php

namespace App\Services\Acceleration;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LaneAuthorization
{
    public function canUse(User $user, Server $node): bool
    {
        if (!$node->getAttribute('accel_managed')) return true;
        if (!$user->isAvailable() || !in_array((string) $user->group_id, array_map('strval', $node->group_ids ?? []), true)) return false;
        return DB::table('accel_grants')->join('accel_lanes', 'accel_lanes.id', '=', 'accel_grants.lane_id')
            ->where('accel_lanes.server_id', $node->id)->where('accel_lanes.status', 'healthy')
            ->where('accel_grants.user_id', $user->id)->where('accel_grants.valid_until', '>', now())->exists();
    }

    public function filterUsers(Server $node, Collection $eligibleBeforeHook, Collection $afterHook): Collection
    {
        if (!$node->getAttribute('accel_managed')) return $afterHook;
        $eligibleIds = $eligibleBeforeHook->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = DB::table('accel_grants')->join('accel_lanes', 'accel_lanes.id', '=', 'accel_grants.lane_id')
            ->where('accel_lanes.server_id', $node->id)->where('accel_lanes.status', 'healthy')
            ->where('accel_grants.valid_until', '>', now())->pluck('accel_grants.user_id')->map(fn ($id) => (int) $id)->all();
        // Return original eligible records: hooks may not replace credentials for a valid ID either.
        $afterIds = $afterHook->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $eligibleBeforeHook->filter(fn ($row) => in_array((int) $row->id, $eligibleIds, true)
            && in_array((int) $row->id, $ids, true) && in_array((int) $row->id, $afterIds, true))->values();
    }

    public function filterServers(User $user, array $servers): array
    {
        return array_values(array_filter($servers, function ($row) use ($user) {
            $node = Server::find($row['id'] ?? null);
            return $node && $this->canUse($user, $node);
        }));
    }
}
