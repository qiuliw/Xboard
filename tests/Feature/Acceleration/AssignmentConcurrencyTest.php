<?php

namespace Tests\Feature\Acceleration;

use App\Models\User;
use App\Services\Acceleration\AccelerationException;
use App\Services\Acceleration\AssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class AssignmentConcurrencyTest extends AccelerationTestCase
{
    public function test_repeatable_read_snapshot_cannot_hide_a_committed_reservation(): void
    {
        if (getenv('ACCEL_MYSQL_TEST') !== '1') $this->markTestSkipped('Dedicated MariaDB/MySQL loopback fixture not configured; SQLite cannot verify this contract.');
        $database = 'orange_acceleration_test_' . bin2hex(random_bytes(6));
        $connection = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 33077, 'username' => 'root',
            'password' => getenv('ACCEL_MYSQL_PASSWORD'), 'database' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        // This fixture never accepts a remote host, production port, or arbitrary database name.
        config(['database.connections.accel_admin' => $connection]);
        $admin = DB::connection('accel_admin');
        $admin->statement('CREATE DATABASE `' . $database . '`');
        foreach (['accel_a', 'accel_b'] as $name) {
            config(['database.connections.' . $name => array_merge($connection, ['database' => $database])]);
            DB::connection($name)->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            DB::setDefaultConnection('accel_a');
            Schema::create('v2_server', fn (Blueprint $table) => $table->id());
            $migration = require base_path('database/migrations/2026_10_28_000001_create_acceleration_tables.php');
            $migration->up();
            DB::table('accel_applications')->insert(['id'=>'demo','name'=>'Demo','rules'=>'{}','countries'=>'["SG"]','enabled'=>1]);
            DB::table('accel_resources')->insert(['id'=>1,'name'=>'capacity-one','country'=>'SG','capacity'=>1,'status'=>'healthy']);
            DB::table('accel_lanes')->insert(['id'=>1,'server_id'=>1,'resource_id'=>1,'host'=>'entry.example.test','port'=>443,'status'=>'healthy']);
            DB::setDefaultConnection('accel_b');
            DB::beginTransaction();
            DB::table('accel_applications')->get(); // B's read view predates A's reservation.
            DB::setDefaultConnection('accel_a');
            $first=new User();$first->id=10;
            app(AssignmentService::class)->reserve($first,['demo'],'a');
            DB::setDefaultConnection('accel_b');
            $second=new User();$second->id=20;
            try {
                app(AssignmentService::class)->reserve($second,['demo'],'b');
                $this->fail('REPEATABLE READ snapshot hid a committed reservation and overbooked capacity=1');
            } catch (AccelerationException $exception) {
                $this->assertSame('NO_CAPACITY',$exception->errorCode);
            }
        } finally {
            $b=DB::connection('accel_b');
            while ($b->transactionLevel()>0) $b->rollBack();
            DB::disconnect('accel_a'); DB::disconnect('accel_b');
            $admin->statement('DROP DATABASE `' . $database . '`'); // Generated test-only database, never operator input.
            DB::setDefaultConnection('sqlite');
        }
    }
}
