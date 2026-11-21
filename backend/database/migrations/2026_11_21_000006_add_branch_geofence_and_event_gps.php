<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('allowed_radius_meters')->nullable()->after('longitude');
            $table->boolean('enforce_geofence')->default(false)->after('allowed_radius_meters');
        });

        Schema::table('attendance_events', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('image_path');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->decimal('gps_accuracy_meters', 8, 2)->nullable()->after('longitude');
            $table->decimal('distance_meters', 10, 2)->nullable()->after('gps_accuracy_meters');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'gps_accuracy_meters', 'distance_meters']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'allowed_radius_meters', 'enforce_geofence']);
        });
    }
};
