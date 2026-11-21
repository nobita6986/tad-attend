<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 160);
            $table->string('region', 20)->default('north'); // north, central, south
            $table->string('address', 500)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('manager_name', 160)->nullable();
            $table->string('manager_phone', 40)->nullable();
            $table->foreignId('default_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['region', 'active']);
        });

        Schema::create('user_branches', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'branch_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('managed_regions')->nullable()->after('role');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->index('branch_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->index(['branch_id', 'active']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->index(['branch_id', 'active']);
        });

        Schema::table('attendance_events', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('device_id')->constrained('branches')->nullOnDelete();
            $table->index(['branch_id', 'captured_at']);
        });

        Schema::create('employee_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('to_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->date('effective_date');
            $table->string('note', 500)->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_transfers');

        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('managed_regions');
        });

        Schema::dropIfExists('user_branches');
        Schema::dropIfExists('branches');
    }
};
