<?php

namespace Tests\Feature\Acceleration;

use App\Models\Server;
use App\Services\ServerService;
use Illuminate\Support\Facades\DB;

class CompatibilityTest extends AccelerationScenarioTestCase
{
    public function test_unmanaged_servers_keep_working_without_acceleration_queries(): void
    {
        $node=Server::first();
        Server::withoutEvents(fn ()=>$node->update(['accel_managed'=>false,'show'=>true]));
        $accelerationQueries=[];
        DB::listen(function ($query) use (&$accelerationQueries) {
            if (str_contains($query->sql,'accel_')) $accelerationQueries[]=$query->sql;
        });
        $this->assertCount(1,ServerService::getAvailableUsers($node));
        $this->assertCount(1,ServerService::getAvailableServers($this->user));
        $this->assertSame([],$accelerationQueries);
    }
}
