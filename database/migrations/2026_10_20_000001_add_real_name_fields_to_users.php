<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'real_name_verified')) {
                $table->boolean('real_name_verified')->default(false)->after('banned');
            }
            if (!Schema::hasColumn('v2_user', 'real_name')) {
                $table->string('real_name', 64)->nullable()->after('real_name_verified');
            }
            if (!Schema::hasColumn('v2_user', 'id_number_hash')) {
                $table->string('id_number_hash', 64)->nullable()->unique()->after('real_name');
            }
            if (!Schema::hasColumn('v2_user', 'id_number_mask')) {
                $table->string('id_number_mask', 32)->nullable()->after('id_number_hash');
            }
            if (!Schema::hasColumn('v2_user', 'real_name_verified_at')) {
                $table->integer('real_name_verified_at')->nullable()->after('id_number_mask');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $columns = [
                'real_name_verified',
                'real_name',
                'id_number_hash',
                'id_number_mask',
                'real_name_verified_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('v2_user', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
