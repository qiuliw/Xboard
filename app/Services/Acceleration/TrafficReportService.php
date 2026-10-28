<?php

namespace App\Services\Acceleration;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;

class TrafficReportService
{
    public function accept(Server $node, string $reportId, array $data): void
    {
        if (!$node->accel_managed || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $reportId)) throw new AccelerationException('REPORT_ID_REQUIRED', 428);
        if (!$data || count($data) > 1000) throw new AccelerationException('INVALID_TRAFFIC_REPORT', 422);
        foreach ($data as $uid => $bytes) {
            if (!ctype_digit((string) $uid) || (int) $uid < 1 || !is_array($bytes) || array_keys($bytes) !== [0, 1]
                || !is_int($bytes[0]) || !is_int($bytes[1]) || min($bytes) < 0 || max($bytes) > 1_000_000_000_000_000) throw new AccelerationException('INVALID_TRAFFIC_REPORT', 422);
        }
        ksort($data, SORT_NUMERIC);
        $hash = hash('sha256', json_encode($data));
        DB::transaction(function () use ($node, $reportId, $data, $hash) {
            $fresh = DB::table('accel_traffic_reports')->insertOrIgnore(['server_id' => $node->id, 'report_id' => $reportId, 'payload_hash' => $hash, 'created_at' => now()]);
            $receipt = DB::table('accel_traffic_reports')->where('server_id', $node->id)->where('report_id', $reportId)->lockForUpdate()->first();
            if (!$receipt || $receipt->payload_hash !== $hash) throw new AccelerationException('REPORT_CONFLICT');
            if (!$fresh) return;
            $server = $node->toArray(); $server['rate'] = $node->getCurrentRate();
            $protocol = $node->type;
            [$server, $protocol, $data] = HookManager::filter('traffic.process.before', [$server, $protocol, $data]);
            [$server, $protocol, $data] = HookManager::filter('traffic.before_process', [$server, $protocol, $data]);
            // All financial/stat rows and receipt commit together. No queue hand-off gap.
            // Redis pending-check is a set and is safe to repeat after a rollback.
            (new TrafficFetchJob($server, $data, $protocol, strtotime(date('Y-m-d'))))->handle();
            (new StatUserJob($server, $data, $protocol, 'd'))->handle();
            (new StatServerJob($server, $data, $protocol, 'd'))->handle();
        }, 3);
    }
}
