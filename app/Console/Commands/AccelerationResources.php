<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AccelerationResources extends Command
{
    protected $signature = 'acceleration:resources {manifest : Local JSON manifest} {--apply : Apply validated changes}';
    protected $description = 'Validate acceleration resources (dry-run by default; never prints credentials)';

    public function handle(): int
    {
        try {
            $raw = file_get_contents($this->argument('manifest'));
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            Validator::make($data, [
                'applications' => 'required|array', 'applications.*.id' => 'required|string|regex:/^[a-zA-Z0-9_-]{1,64}$/|distinct',
                'applications.*.name' => 'required|string|max:100', 'applications.*.domains' => 'required|array',
                'applications.*.domains.*' => 'required|string|regex:/^[a-zA-Z0-9.-]+$/',
                'applications.*.process_names' => 'present|array', 'applications.*.process_names.*' => 'string|regex:/^[a-zA-Z0-9_. -]+$/',
                'applications.*.priority' => 'required|integer|min:0|max:10000',
                'applications.*.allowed_countries' => 'required|array|min:1', 'applications.*.allowed_countries.*' => 'string|regex:/^[A-Z]{2}$/',
                'resources' => 'present|array', 'resources.*.id' => 'required|integer|min:1|distinct',
                'resources.*.name' => 'required|string|max:100', 'resources.*.country' => 'required|string|regex:/^[A-Z]{2}$/',
                'resources.*.public_ip' => 'required|ip|distinct', 'resources.*.capacity' => 'required|integer|min:1',
                'resources.*.lanes' => 'required|array|min:1', 'resources.*.lanes.*.server_id' => 'required|integer|exists:v2_server,id|distinct',
                'resources.*.lanes.*.host' => 'required|string|regex:/^[a-zA-Z0-9.:-]+$/',
                'resources.*.lanes.*.port' => 'required|integer|min:1|max:65535',
            ])->validate();
            DB::transaction(function () use ($data) {
                foreach ($data['resources'] as $resource) {
                    $existing = DB::table('accel_resources')->where('id', $resource['id'])->lockForUpdate()->first();
                    if ($existing && ($existing->public_ip !== $resource['public_ip'] || $existing->country !== $resource['country'])) throw new \RuntimeException('Use a new resource identity when changing an exit.');
                    $otherIp = DB::table('accel_resources')->where('public_ip', $resource['public_ip'])->where('id', '!=', $resource['id'])->exists();
                    if ($otherIp) throw new \RuntimeException('Duplicate exit IP.');
                    foreach ($resource['lanes'] as $lane) {
                        $old = DB::table('accel_lanes')->where('server_id', $lane['server_id'])->first();
                        if ($old && ($old->resource_id !== $resource['id'] || $old->host !== $lane['host'] || $old->port !== $lane['port'])) throw new \RuntimeException('Existing lane identity is immutable.');
                    }
                }
                if (!$this->option('apply')) return;
                foreach ($data['applications'] as $app) {
                    DB::table('accel_applications')->updateOrInsert(['id' => $app['id']], ['name' => $app['name'], 'enabled' => true,
                        'countries' => json_encode($app['allowed_countries']), 'rules' => json_encode(array_intersect_key($app, array_flip(['domains', 'process_names', 'priority'])))]);
                }
                foreach ($data['resources'] as $resource) {
                    DB::table('accel_resources')->updateOrInsert(['id' => $resource['id']], array_merge(array_intersect_key($resource, array_flip(['name', 'country', 'public_ip', 'capacity'])), ['status' => 'healthy']));
                    foreach ($resource['lanes'] as $lane) {
                        DB::table('accel_lanes')->updateOrInsert(['server_id' => $lane['server_id']], ['resource_id' => $resource['id'], 'host' => $lane['host'], 'port' => $lane['port'], 'status' => 'healthy']);
                        Server::where('id', $lane['server_id'])->update(['accel_managed' => true]);
                    }
                }
            });
            $this->info($this->option('apply') ? 'Resources applied; node verification is still required.' : 'Dry-run validation passed; no changes written.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Manifest validation errors may contain arbitrary operator input: do not echo it.
            $this->error('Resource validation failed; no changes committed.');
            return self::FAILURE;
        }
    }
}
