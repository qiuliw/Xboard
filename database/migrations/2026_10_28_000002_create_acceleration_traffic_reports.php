<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accel_traffic_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('server_id');
            $table->string('report_id', 128);
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->primary(['server_id', 'report_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('accel_traffic_reports'); }
};
