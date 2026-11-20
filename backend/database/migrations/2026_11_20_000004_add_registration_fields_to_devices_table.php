<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('approved')->index()->after('active');
            $table->string('device_model', 120)->nullable()->after('hardware_id');
            $table->timestamp('requested_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'device_model', 'requested_at']);
        });
    }
};
