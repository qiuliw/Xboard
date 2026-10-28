<?php

namespace Tests\Feature\Acceleration;

use Illuminate\Support\Facades\DB;

class ResourceCommandTest extends AccelerationScenarioTestCase
{
    public function test_import_is_dry_run_unless_apply_is_explicit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'accel');
        $manifest = ['applications' => [['id' => 'another', 'name' => 'Another', 'domains' => ['app.example.test'], 'process_names' => [], 'priority' => 1, 'allowed_countries' => ['SG']]], 'resources' => []];
        file_put_contents($path, json_encode($manifest));
        try {
            $this->artisan('acceleration:resources', ['manifest' => $path])->assertSuccessful();
            $this->assertFalse(DB::table('accel_applications')->where('id', 'another')->exists());
            $this->artisan('acceleration:resources', ['manifest' => $path, '--apply' => true])->assertSuccessful();
            $this->assertTrue(DB::table('accel_applications')->where('id', 'another')->exists());
        } finally { unlink($path); }
    }
}
