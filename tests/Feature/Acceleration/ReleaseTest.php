<?php

namespace Tests\Feature\Acceleration;

use App\Models\Server;
use App\Models\User;
use App\Services\Acceleration\ReleaseService;
use App\Services\Acceleration\AccelerationException;
use App\Services\Acceleration\NodeDeploymentVerifier;
use App\Services\Acceleration\DeploymentEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReleaseTest extends AccelerationScenarioTestCase
{
    public function test_pending_is_idempotent_and_cannot_be_activated_without_node_evidence(): void
    {
        $service = app(ReleaseService::class);
        $release = $service->prepare($this->user, ['demo'], 0, 'test-key-00000001');
        $this->assertSame('pending', $release['status']);
        $this->assertSame($release, $service->prepare($this->user, ['demo'], 0, 'test-key-00000001'));
        $service->activate($release['release_id']);
        $this->assertSame(0, DB::table('accel_assignments')->count());
        $this->assertSame('pending', DB::table('accel_releases')->value('status'));
    }

    public function test_activation_requires_current_generation_and_configuration_is_user_scoped(): void
    {
        $service = app(ReleaseService::class);
        $release = $service->prepare($this->user, ['demo'], 0, 'test-key-00000001');
        $this->app->instance(NodeDeploymentVerifier::class, new class extends NodeDeploymentVerifier {
            public function verify(string $releaseId): DeploymentEvidence { return new DeploymentEvidence([1 => 1], now(), true); }
        });
        $saved = DB::table('accel_releases')->where('id', $release['release_id'])->first();
        $this->assertSame(1, $saved->version);
        $this->assertSame(1, DB::table('accel_lanes')->value('generation'));
        $this->assertFalse(now()->gte($saved->valid_until));
        $node = Server::first();
        $this->assertSame(json_decode($saved->snapshot, true)[1]['server_hash'], hash('sha256', json_encode($node->attributesToArray())));
        $service->activate($release['release_id']);
        $config = $service->activeConfig($this->user);
        $this->assertSame(1, $config['version']);
        $this->assertSame('accel-lane-1', $config['mihomo']['proxies'][0]['name']);
        $this->assertLessThanOrEqual($this->user->expired_at, strtotime($config['valid_until']));
        $this->assertSame(1, DB::table('accel_assignments')->count());
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $service = app(ReleaseService::class);
        $service->prepare($this->user, ['demo'], 0, 'test-key-00000001');
        $this->expectException(AccelerationException::class);
        $service->prepare($this->user, [], 0, 'test-key-00000001');
    }

    public function test_changed_lane_generation_cannot_serve_a_stale_active_configuration(): void
    {
        $service=app(ReleaseService::class);
        $release=$service->prepare($this->user,['demo'],0,'test-key-00000001');
        $this->app->instance(NodeDeploymentVerifier::class,new class extends NodeDeploymentVerifier {
            public function verify(string $releaseId): DeploymentEvidence {return new DeploymentEvidence([1=>1],now(),true);}
        });
        $service->activate($release['release_id']);
        DB::table('accel_lanes')->update(['generation'=>2]);
        $this->expectException(AccelerationException::class);
        $service->activeConfig($this->user);
    }
}
