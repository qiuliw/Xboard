<?php

namespace App\Services\Acceleration;

use App\Models\Server;
use App\Models\User;
use App\Protocols\ClashMeta;
use App\Services\RealName\RealNameService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReleaseService
{
    public function assertEligible(User $user): void
    {
        if (!config('acceleration.enabled') || !in_array((int) $user->id, array_map('intval', config('acceleration.user_ids', [])), true)) {
            throw new AccelerationException('ACCELERATION_DISABLED', 403);
        }
        $user->refresh();
        if (!$user->isAvailable()) throw new AccelerationException('ACCOUNT_UNAVAILABLE', 403);
        $realname = app(RealNameService::class);
        if ($realname->isRequired() && !$realname->isVerified($user)) throw new AccelerationException('REALNAME_REQUIRED', 403);
    }

    public function prepare(User $user, array $applicationIds, int $expectedVersion, string $key): array
    {
        $this->assertEligible($user);
        sort($applicationIds);
        $applicationIds = array_values(array_unique($applicationIds));
        if (!$applicationIds) throw new AccelerationException('EMPTY_SELECTION', 422);
        $hash = hash('sha256', json_encode([$applicationIds, $expectedVersion]));
        return DB::transaction(function () use ($user, $applicationIds, $expectedVersion, $key, $hash) {
            DB::table('accel_states')->insertOrIgnore(['user_id' => $user->id, 'version' => 0]);
            $state = DB::table('accel_states')->where('user_id', $user->id)->lockForUpdate()->first();
            $existing = DB::table('accel_releases')->where('user_id', $user->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->payload_hash !== $hash) throw new AccelerationException('IDEMPOTENCY_CONFLICT');
                return $this->summary($existing);
            }
            if (!config('acceleration.accept_new')) throw new AccelerationException('PREPARATION_PAUSED', 503);
            if ($state->version !== $expectedVersion) throw new AccelerationException('VERSION_CONFLICT');
            if (DB::table('accel_releases')->where('user_id', $user->id)->where('status', 'pending')->exists()) throw new AccelerationException('PREPARATION_IN_PROGRESS');
            $id = (string) Str::uuid();
            $bindings = app(AssignmentService::class)->reserve($user, $applicationIds, $id);
            $valid = now()->addDay();
            if ($user->expired_at !== null && $user->expired_at < $valid->timestamp) $valid = \Carbon\Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
            $snapshot = [];
            foreach ($bindings as $binding) {
                $lane = DB::table('accel_lanes')->where('id', $binding['lane_id'])->first();
                $server = Server::find($lane->server_id);
                if (!$server || !$server->accel_managed || !in_array((string) $user->group_id, array_map('strval', $server->group_ids ?? []), true)) throw new AccelerationException('LANE_UNAVAILABLE', 503);
                $snapshot[(string) $lane->id] = ['generation' => $lane->generation, 'server_hash' => $this->serverHash($server)];
                DB::table('accel_grants')->insertOrIgnore(['user_id' => $user->id, 'lane_id' => $lane->id, 'release_id' => $id, 'valid_until' => $valid]);
            }
            DB::table('accel_releases')->insert(['id' => $id, 'user_id' => $user->id, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'version' => $state->version + 1, 'status' => 'pending', 'rules_version' => app(CatalogService::class)->get()['rules_version'], 'snapshot' => json_encode($snapshot), 'valid_until' => $valid, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('accel_states')->where('user_id', $user->id)->update(['preferences' => json_encode($applicationIds)]);
            return $this->summary(DB::table('accel_releases')->where('id', $id)->first());
        }, 3);
    }

    public function activate(string $releaseId): void
    {
        $evidence = app(NodeDeploymentVerifier::class)->verify($releaseId);
        if (!$evidence->passed || $evidence->observedAt->lt(now()->subSeconds(90)) || $evidence->observedAt->gt(now())) return;
        DB::transaction(function () use ($releaseId, $evidence) {
            $release = DB::table('accel_releases')->where('id', $releaseId)->first();
            if (!$release || $release->status !== 'pending') return;
            $state = DB::table('accel_states')->where('user_id', $release->user_id)->lockForUpdate()->first();
            $user = User::findOrFail($release->user_id);
            $this->assertEligible($user);
            if ($state->version + 1 !== $release->version || now()->gte($release->valid_until)) return;
            foreach (json_decode($release->snapshot, true) as $id => $snapshot) {
                $lane = DB::table('accel_lanes')->where('id', $id)->lockForUpdate()->first();
                $server = $lane ? Server::find($lane->server_id) : null;
                if (!$lane || $lane->status !== 'healthy' || !$server || $this->serverHash($server) !== $snapshot['server_hash']
                    || $lane->generation !== $snapshot['generation'] || ($evidence->laneGenerations[$id] ?? null) !== $lane->generation) return;
            }
            app(AssignmentService::class)->activate($user, $releaseId);
            DB::table('accel_states')->where('user_id', $user->id)->update(['version' => $release->version, 'active_release' => $releaseId]);
            DB::table('accel_releases')->where('id', $releaseId)->update(['status' => 'ready', 'updated_at' => now()]);
        }, 3);
    }

    public function activeConfig(User $user): array
    {
        $this->assertEligible($user);
        $state = DB::table('accel_states')->where('user_id', $user->id)->first();
        $release = $state?->active_release ? DB::table('accel_releases')->where('id', $state->active_release)->where('user_id', $user->id)->where('status', 'ready')->first() : null;
        if (!$release || now()->gte($release->valid_until)) throw new AccelerationException('CONFIG_NOT_READY', 503);
        $snapshot = json_decode($release->snapshot, true);
        $proxies = []; $apps = []; $entrances = [];
        foreach (DB::table('accel_assignments')->where('user_id', $user->id)->where('release_id', $release->id)->get() as $assignment) {
            $lane = DB::table('accel_lanes')->where('id', $assignment->lane_id)->first();
            $node = $lane ? Server::find($lane->server_id) : null;
            if (!$node || !app(LaneAuthorization::class)->canUse($user, $node) || ($snapshot[$lane->id]['server_hash'] ?? '') !== $this->serverHash($node)
                || ($snapshot[$lane->id]['generation'] ?? null) !== $lane->generation) throw new AccelerationException('LANE_UNAVAILABLE', 503);
            $name = 'accel-lane-' . $lane->id;
            $raw = $node->toArray(); $raw['name'] = $name; $raw['host'] = $lane->host; $raw['port'] = $lane->port;
            $method = match ($node->type) { 'shadowsocks' => 'buildShadowsocks', 'vmess' => 'buildVmess', 'vless' => 'buildVless', 'trojan' => 'buildTrojan', default => throw new AccelerationException('UNSUPPORTED_LANE_PROTOCOL', 503) };
            $proxies[$name] = ClashMeta::$method($node->generateServerPassword($user), $raw);
            $apps[] = ['id' => $assignment->application_id, 'lane_id' => (string) $lane->id, 'proxy_names' => [$name]];
            $entrances[$lane->id] = ['id' => (string) $lane->id, 'host' => $lane->host, 'port' => $lane->port, 'probe' => 'tcp'];
        }
        $validUntil = \Carbon\Carbon::parse($release->valid_until);
        if ($user->expired_at !== null && $user->expired_at < $validUntil->timestamp) $validUntil = \Carbon\Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
        return ['schema_version' => 1, 'version' => $release->version, 'rules_version' => $release->rules_version, 'valid_until' => $validUntil->toIso8601String(),
            'applications' => $apps, 'entrances' => array_values($entrances), 'mihomo' => ['proxies' => array_values($proxies)]];
    }

    private function serverHash(Server $server): string { return hash('sha256', json_encode($server->attributesToArray())); }
    private function summary(object $release): array { return ['release_id' => $release->id, 'version' => $release->version, 'status' => $release->status]; }
}
