<?php

namespace Tests\Feature\Acceleration;

use App\Models\Server;
use App\Models\User;
use App\Services\Acceleration\LaneAuthorization;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class LaneAuthorizationTest extends AccelerationTestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::withoutEvents(fn () => User::create(['email' => 'lane@example.test', 'password' => 'unused',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'group_id' => 1, 'plan_id' => 1,
            'transfer_enable' => 1000, 'expired_at' => time() + 3600, 'u' => 0, 'd' => 0, 'banned' => 0]));
        $node = Server::withoutEvents(fn () => Server::create(['name' => 'lane', 'type' => 'shadowsocks',
            'host' => 'entry.example.test', 'port' => 443, 'server_port' => 443, 'rate' => 1, 'accel_managed' => true, 'group_ids' => ['1'], 'show' => true, 'protocol_settings' => ['cipher' => 'aes-128-gcm']]));
        DB::table('accel_resources')->insert(['id' => 1, 'name' => 'resource', 'country' => 'SG', 'capacity' => 10, 'status' => 'healthy']);
        DB::table('accel_lanes')->insert(['id' => 1, 'server_id' => $node->id, 'resource_id' => 1, 'host' => $node->host, 'port' => 443, 'status' => 'healthy']);
        return [$user, $node];
    }

    public function test_lane_denies_without_grant_and_expired_grant_does_not_restore_access(): void
    {
        [$user, $node] = $this->fixture();
        $this->assertCount(0, ServerService::getAvailableUsers($node));
        DB::table('accel_grants')->insert(['user_id' => $user->id, 'lane_id' => 1, 'release_id' => 'r', 'valid_until' => now()->addMinute()]);
        $this->assertCount(1, ServerService::getAvailableUsers($node));
        DB::table('accel_grants')->update(['valid_until' => now()->subSecond()]);
        $this->assertCount(0, ServerService::getAvailableUsers($node));
    }

    public function test_final_intersection_drops_hook_injected_users(): void
    {
        [$user, $node] = $this->fixture();
        DB::table('accel_grants')->insert(['user_id' => $user->id, 'lane_id' => 1, 'release_id' => 'r', 'valid_until' => now()->addMinute()]);
        $allowed = (object) ['id' => $user->id];
        $injected = (object) ['id' => 999];
        $result = app(LaneAuthorization::class)->filterUsers($node, collect([$allowed]), collect([$allowed, $injected]));
        $this->assertSame([$user->id], $result->pluck('id')->all());
    }

    public function test_websocket_delta_removes_user_without_lane_permission(): void
    {
        [$user, $node] = $this->fixture();
        Cache::put('node_ws_alive:' . $node->id, true);
        $this->assertTrue($user->isAvailable());
        Redis::shouldReceive('publish')->once()->with('node:push', \Mockery::on(fn ($payload) => json_decode($payload, true)['data']['action'] === 'remove'));
        NodeSyncService::notifyUserChanged($user);
    }

    public function test_protocol_hook_cannot_reintroduce_an_unauthorized_lane(): void
    {
        [$user, $node] = $this->fixture();
        \App\Services\Plugin\HookManager::registerFilter('protocol.servers.filtered', fn () => [$node->toArray()]);
        $protocol = new class($user, []) extends \App\Support\AbstractProtocol {
            public function handle() { return $this->servers; }
        };
        $this->assertSame([], $protocol->handle());
    }

    public function test_user_hook_cannot_replace_an_authorized_users_credentials(): void
    {
        [$user, $node] = $this->fixture();
        DB::table('accel_grants')->insert(['user_id' => $user->id, 'lane_id' => 1, 'release_id' => 'r', 'valid_until' => now()->addMinute()]);
        \App\Services\Plugin\HookManager::registerFilter('server.users.get', function ($rows) {
            $rows->first()->uuid = 'changed-by-hook';
            return $rows;
        });
        $this->assertSame($user->uuid, ServerService::getAvailableUsers($node)->first()->uuid);
    }

    public function test_user_losing_plan_cannot_keep_a_lane_grant(): void
    {
        [$user,$node]=$this->fixture();
        DB::table('accel_grants')->insert(['user_id'=>$user->id,'lane_id'=>1,'release_id'=>'r','valid_until'=>now()->addMinute()]);
        DB::table('v2_user')->where('id',$user->id)->update(['plan_id'=>null]);
        $this->assertCount(0,ServerService::getAvailableUsers($node));
    }
}
