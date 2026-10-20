<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('realname_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->uuid('current_attempt_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('realname_initialize_limits', function (Blueprint $table) {
            $table->char('bucket', 64)->primary(); // keyed HMAC of date + user/IP, no raw IP
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('created_at')->index();
        });
        Schema::create('realname_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('status', 20);
            $table->char('idempotency_hmac', 64);
            $table->char('request_hmac', 64);
            $table->string('outer_order_no', 32)->unique();
            $table->text('certify_id')->nullable(); // encrypted by the model
            $table->char('certify_id_hmac', 64)->nullable()->unique();
            $table->text('auth_url')->nullable(); // encrypted by the model
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('next_query_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_hmac'], 'realname_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realname_attempts');
        Schema::dropIfExists('realname_initialize_limits');
        Schema::dropIfExists('realname_verifications');
    }
};
