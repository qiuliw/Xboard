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

abstract class AccelerationScenarioTestCase extends AccelerationTestCase
{
    use RefreshDatabase;

    protected User $user;
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::withoutEvents(fn () => User::create(['email' => 'release@example.test', 'password' => 'unused', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'group_id' => 1, 'plan_id' => 1, 'transfer_enable' => 1000, 'u' => 0, 'd' => 0, 'expired_at' => time() + 600]));
        $server = Server::withoutEvents(fn () => Server::create(['name' => 'test', 'type' => 'shadowsocks', 'host' => 'entry.example.test', 'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => ['1'], 'accel_managed' => true, 'protocol_settings' => ['cipher' => 'aes-128-gcm']]));
        DB::table('accel_applications')->insert(['id' => 'demo', 'name' => 'Demo', 'rules' => '{"domains":["service.example.test"],"process_names":["demo.exe"],"priority":10}', 'countries' => '["SG"]', 'enabled' => 1]);
        DB::table('accel_resources')->insert(['id' => 1, 'name' => 'test', 'country' => 'SG', 'capacity' => 10, 'status' => 'healthy']);
        DB::table('accel_lanes')->insert(['id' => 1, 'server_id' => $server->id, 'resource_id' => 1, 'host' => 'entry.example.test', 'port' => 443, 'status' => 'healthy']);
        config(['acceleration.enabled' => true, 'acceleration.accept_new' => true, 'acceleration.user_ids' => [$this->user->id]]);
        admin_setting(['real_name_verify' => 0]);
    }

}
