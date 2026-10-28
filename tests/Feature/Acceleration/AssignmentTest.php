<?php

namespace Tests\Feature\Acceleration;

use App\Models\User;
use App\Services\Acceleration\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class AssignmentTest extends AccelerationTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('accel_applications')->insert(['id' => 'demo', 'name' => 'Demo', 'rules' => '{}', 'countries' => '["SG"]', 'enabled' => true]);
        DB::table('accel_resources')->insert(['id' => 1, 'name' => 'test', 'country' => 'SG', 'capacity' => 1, 'status' => 'healthy']);
        DB::table('accel_lanes')->insert(['id' => 1, 'server_id' => 1, 'resource_id' => 1, 'host' => 'entry.example.test', 'port' => 443, 'status' => 'healthy']);
    }

    public function test_reservation_is_idempotent_and_current_binding_survives_cache_loss(): void
    {
        $user = new User();
        $user->id = 10;
        $service = app(AssignmentService::class);
        $first = $service->reserve($user, ['demo'], 'release-a');
        $this->assertSame($first, $service->reserve($user, ['demo'], 'release-a'));
        $this->assertSame(1, DB::table('accel_reservations')->count());
        $service->activate($user, 'release-a');
        cache()->flush();
        $second = $service->reserve($user, ['demo'], 'release-b');
        $this->assertSame($first[0]['lane_id'], $second[0]['lane_id']);
        $this->assertSame(1, DB::table('accel_assignments')->count());
    }

    public function test_capacity_is_not_oversubscribed_and_existing_binding_is_not_lost(): void
    {
        $one = new User(); $one->id = 10;
        $two = new User(); $two->id = 20;
        $service = app(AssignmentService::class);
        $service->reserve($one, ['demo'], 'one');
        try {
            $service->reserve($two, ['demo'], 'two');
            $this->fail('Expected no capacity');
        } catch (\App\Services\Acceleration\AccelerationException $e) {
            $this->assertSame('NO_CAPACITY', $e->errorCode);
        }
        $this->assertSame(1, DB::table('accel_reservations')->count());
    }
}
