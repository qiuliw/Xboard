<?php

namespace Tests\Feature\Acceleration;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficReportTest extends AccelerationScenarioTestCase
{
    public function test_retry_is_counted_once_but_identical_new_samples_are_counted_again(): void
    {
        Redis::shouldReceive('sadd')->andReturn(1);
        $service = app('App\\Services\\Acceleration\\TrafficReportService');
        $server = Server::first();
        $data = [$this->user->id => [12, 34]];
        $service->accept($server, 'report-test-00001', $data);
        $service->accept($server, 'report-test-00001', $data);
        $this->assertSame(12, $this->user->fresh()->u);
        $this->assertSame(34, $this->user->fresh()->d);
        $service->accept($server, 'report-test-00002', $data);
        $this->assertSame(24, $this->user->fresh()->u);
        $this->assertSame(68, $this->user->fresh()->d);
        $this->assertSame(24, (int) DB::table('v2_stat_user')->sum('u'));
        $this->assertSame(68, (int) DB::table('v2_stat_server')->sum('d'));
    }

    public function test_same_report_id_cannot_replace_payload_and_partial_accounting_rolls_back(): void
    {
        Redis::shouldReceive('sadd')->andReturn(1);
        $service = app('App\\Services\\Acceleration\\TrafficReportService');
        $service->accept(Server::first(), 'report-test-00001', [$this->user->id => [12, 34]]);
        try {
            $service->accept(Server::first(), 'report-test-00001', [$this->user->id => [99, 34]]);
            $this->fail('Conflicting replay was accepted');
        } catch (\App\Services\Acceleration\AccelerationException $e) {
            $this->assertSame('REPORT_CONFLICT', $e->getMessage());
        }
        DB::unprepared("CREATE TRIGGER reject_stat BEFORE UPDATE ON v2_stat_user BEGIN SELECT RAISE(ABORT, 'test stat failure'); END");
        try { $service->accept(Server::first(), 'report-test-00002', [$this->user->id => [12, 34]]); }
        catch (\Illuminate\Database\QueryException) {}
        $this->assertSame(12, $this->user->fresh()->u);
        $this->assertSame(1, DB::table('accel_traffic_reports')->count());
    }
}
