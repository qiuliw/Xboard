<?php

namespace Tests\Feature\Acceleration;

use App\Models\Server;
use App\Services\Acceleration\NodeDeploymentVerifier;
use App\Services\Acceleration\ReleaseService;
use Illuminate\Support\Facades\DB;

class NodeDeploymentVerifierTest extends AccelerationScenarioTestCase
{
    public function test_active_probe_proves_the_pending_users_current_lane_and_fixed_exit(): void
    {
        config(['acceleration.verifier' => 'active_probe']);
        DB::table('accel_resources')->update(['public_ip' => '203.0.113.21']);
        $release = app(ReleaseService::class)->prepare($this->user, ['demo'], 0, 'probe-test-00000001');
        $probe = new class {
            public array $proxies = [];
            public function exitIp(array $proxy, float $timeout): ?string
            {
                $this->proxies[] = $proxy;
                return '203.0.113.21';
            }
        };
        $this->app->instance('App\\Services\\Acceleration\\ActiveConnectionProbe', $probe);
        $evidence = app(NodeDeploymentVerifier::class)->verify($release['release_id']);
        $this->assertTrue($evidence->passed);
        $this->assertSame([1 => 1], $evidence->laneGenerations);
        $this->assertSame('entry.example.test', $probe->proxies[0]['server']);
        $this->assertSame(Server::first()->generateServerPassword($this->user), $probe->proxies[0]['password']);
    }

    public function test_wrong_exit_or_expired_grant_never_activates_a_release(): void
    {
        config(['acceleration.verifier' => 'active_probe']);
        DB::table('accel_resources')->update(['public_ip' => '203.0.113.21']);
        $release = app(ReleaseService::class)->prepare($this->user, ['demo'], 0, 'probe-test-00000001');
        $this->app->instance('App\\Services\\Acceleration\\ActiveConnectionProbe', new class {
            public function exitIp(array $proxy, float $timeout): ?string { return '203.0.113.22'; }
        });
        app(ReleaseService::class)->activate($release['release_id']);
        $this->assertSame('pending', DB::table('accel_releases')->value('status'));
        DB::table('accel_grants')->update(['valid_until' => now()->subSecond()]);
        $this->assertFalse(app(NodeDeploymentVerifier::class)->verify($release['release_id'])->passed);
    }

    public function test_changed_generation_is_not_probed_or_accepted(): void
    {
        config(['acceleration.verifier' => 'active_probe']);
        DB::table('accel_resources')->update(['public_ip' => '203.0.113.21']);
        $release = app(ReleaseService::class)->prepare($this->user, ['demo'], 0, 'probe-test-00000001');
        DB::table('accel_lanes')->update(['generation' => 2]);
        $this->app->instance('App\\Services\\Acceleration\\ActiveConnectionProbe', new class {
            public function exitIp(array $proxy, float $timeout): ?string { throw new \LogicException('Stale lane must not be probed'); }
        });
        $this->assertFalse(app(NodeDeploymentVerifier::class)->verify($release['release_id'])->passed);
    }
}
