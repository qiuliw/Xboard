<?php

namespace App\Services\Acceleration;

use Illuminate\Support\Facades\DB;

class CatalogService
{
    public function get(): array
    {
        $apps = DB::table('accel_applications')->where('enabled', true)->orderBy('id')->get()->map(function ($row) {
            $rules = json_decode($row->rules, true);
            return ['id' => $row->id, 'name' => $row->name, 'platforms' => ['windows'], 'allowed_countries' => json_decode($row->countries, true),
                'domains' => $rules['domains'] ?? [], 'process_names' => $rules['process_names'] ?? [], 'priority' => $rules['priority'] ?? 0];
        })->all();
        return ['schema_version' => 1, 'rules_version' => hash('sha256', json_encode($apps)), 'applications' => $apps];
    }
}
