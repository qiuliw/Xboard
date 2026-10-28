<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', fn (Blueprint $t) => $t->boolean('accel_managed')->default(false));
        Schema::create('accel_applications', function (Blueprint $t) {
            $t->string('id', 64)->primary(); $t->string('name');
            $t->json('rules'); $t->json('countries'); $t->boolean('enabled')->default(false);
        });
        Schema::create('accel_resources', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('country', 2);
            $t->string('public_ip')->nullable()->unique();
            $t->unsignedInteger('capacity'); $t->string('status')->default('disabled');
        });
        Schema::create('accel_lanes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('server_id')->unique();
            $t->foreignId('resource_id')->constrained('accel_resources');
            $t->string('host'); $t->unsignedInteger('port');
            $t->string('status')->default('disabled'); $t->unsignedInteger('generation')->default(1);
        });
        Schema::create('accel_states', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary(); $t->unsignedInteger('version')->default(0);
            $t->json('preferences')->nullable(); $t->string('active_release')->nullable();
        });
        Schema::create('accel_releases', function (Blueprint $t) {
            $t->string('id', 64)->primary(); $t->unsignedBigInteger('user_id')->index();
            $t->string('idempotency_key', 128); $t->string('payload_hash', 64);
            $t->unsignedInteger('version'); $t->string('status'); $t->string('rules_version', 64)->default('1');
            $t->json('snapshot')->nullable(); $t->timestamp('valid_until')->nullable();
            $t->timestamps(); $t->unique(['user_id', 'idempotency_key']);
        });
        foreach (['accel_assignments', 'accel_reservations'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id(); $t->unsignedBigInteger('user_id'); $t->string('application_id', 64);
                $t->foreignId('lane_id')->constrained('accel_lanes'); $t->string('release_id', 64);
                $t->unique($table === 'accel_assignments' ? ['user_id', 'application_id'] : ['release_id', 'application_id']);
            });
        }
        Schema::create('accel_grants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->foreignId('lane_id')->constrained('accel_lanes');
            $t->string('release_id', 64); $t->timestamp('valid_until');
            $t->unique(['user_id', 'lane_id', 'release_id']);
        });
    }

    public function down(): void
    {
        foreach (['accel_grants', 'accel_reservations', 'accel_assignments', 'accel_releases', 'accel_states', 'accel_lanes', 'accel_resources', 'accel_applications'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('v2_server', fn (Blueprint $t) => $t->dropColumn('accel_managed'));
    }
};
