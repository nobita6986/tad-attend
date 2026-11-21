<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('registered_latitude', 10, 7)->nullable()->after('device_model');
            $table->decimal('registered_longitude', 10, 7)->nullable()->after('registered_latitude');
            $table->decimal('registered_gps_accuracy_meters', 8, 2)->nullable()->after('registered_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['registered_latitude', 'registered_longitude', 'registered_gps_accuracy_meters']);
        });
    }
};
