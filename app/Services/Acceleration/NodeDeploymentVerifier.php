<?php

namespace App\Services\Acceleration;

use App\Models\Server;
use App\Models\User;
use App\Protocols\ClashMeta;
use Illuminate\Support\Facades\DB;

class NodeDeploymentVerifier
{
    public function verify(string $releaseId): DeploymentEvidence
    {
        $failed = fn () => new DeploymentEvidence([], now(), false);
        if (config('acceleration.verifier') !== 'active_probe') return $failed();
        $release = DB::table('accel_releases')->where('id', $releaseId)->where('status', 'pending')->first();
        if (!$release || now()->gte($release->valid_until)) return $failed();
        $user = User::find($release->user_id);
        if (!$user) return $failed();
        try { app(ReleaseService::class)->assertEligible($user); }
        catch (AccelerationException) { return $failed(); }
        $generations = [];
        $deadline = microtime(true) + 15;
        foreach (json_decode($release->snapshot, true) ?? [] as $id => $expected) {
            $lane = DB::table('accel_lanes')->where('id', $id)->first();
            $resource = $lane ? DB::table('accel_resources')->where('id', $lane->resource_id)->first() : null;
            $server = $lane ? Server::find($lane->server_id) : null;
            if (!$lane || !$resource || !$server || $lane->status !== 'healthy' || $resource->status !== 'healthy'
                || $lane->generation !== $expected['generation'] || LaneConfiguration::hash($server) !== $expected['server_hash']
                || !filter_var($resource->public_ip, FILTER_VALIDATE_IP) || !app(LaneAuthorization::class)->canUse($user, $server)
                || !DB::table('accel_grants')->where('release_id', $releaseId)->where('user_id', $user->id)->where('lane_id', $id)->where('valid_until', '>', now())->exists()) return $failed();
            $method = match ($server->type) {
                'shadowsocks' => 'buildShadowsocks', 'vmess' => 'buildVmess', 'vless' => 'buildVless', 'trojan' => 'buildTrojan', default => null,
            };
            $remaining = min(6.0, $deadline - microtime(true));
            if (!$method || $remaining < 1) return $failed();
            $raw = $server->toArray();
            $raw['name'] = 'accel-probe'; $raw['host'] = $lane->host; $raw['port'] = $lane->port;
            $proxy = ClashMeta::$method($server->generateServerPassword($user), $raw);
            try { $ip = app(ActiveConnectionProbe::class)->exitIp($proxy, $remaining); }
            catch (\Throwable) { return $failed(); }
            if (!is_string($ip) || @inet_pton($ip) !== @inet_pton($resource->public_ip)) return $failed();
            $generations[(int) $id] = $lane->generation;
        }
        // This attests a fresh protocol handshake and fixed exit for this snapshot.
        // The caller re-locks/rechecks generations before activation. No client ACK is used.
        return new DeploymentEvidence($generations, now(), count($generations) > 0);
    }
}
