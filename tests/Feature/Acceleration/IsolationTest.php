<?php

namespace Tests\Feature\Acceleration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class IsolationTest extends AccelerationTestCase
{
    public function test_boot_uses_only_test_resources(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->expectException(\RuntimeException::class);
        Http::get('https://must-not-connect.example.test');
    }
}
