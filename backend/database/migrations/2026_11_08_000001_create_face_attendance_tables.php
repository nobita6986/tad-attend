<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Laravel 13 scaffold creates `users` already.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('grace_minutes')->default(5);
            $table->json('weekdays')->nullable(); // ISO 1=Monday..7=Sunday; null means all days.
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('full_name', 160);
            $table->string('gender', 16)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('employed_from')->nullable();
            $table->date('employed_until')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('department', 120)->nullable();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_method', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from', 'effective_to']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('direction', 16); // in/out; no unsafe implicit auto mode
            $table->char('token_hash', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('face_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->longText('embedding'); // Laravel encrypted:array cast => ciphertext
            $table->string('model_id', 80);
            $table->string('model_version', 40);
            $table->boolean('active')->default(true);
            $table->timestamp('enrolled_at');
            $table->timestamps();
            $table->unique(['employee_id', 'model_id', 'model_version']);
        });

        Schema::create('enrollment_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained('devices')->restrictOnDelete();
            $table->dateTime('captured_at'); // UTC from ISO offset
            $table->dateTime('received_at'); // authoritative server receipt UTC
            $table->string('direction', 8); // in/out
            $table->string('review_state', 24)->default('accepted');
            $table->boolean('is_offline')->default(false);
            $table->decimal('match_score', 7, 5);
            $table->string('liveness_method', 64);
            $table->string('model_id', 80);
            $table->string('model_version', 40);
            $table->string('image_path', 255)->nullable(); // private disk
            $table->timestamps();
            $table->index(['employee_id', 'captured_at']);
            $table->index(['review_state', 'captured_at']);
            $table->index(['received_at', 'image_path']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 80);
            $table->string('subject_id', 80);
            $table->json('details')->nullable(); // NO biometric values, image, passwords or bearer tokens
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'attendance_events', 'enrollment_tickets', 'face_templates', 'devices', 'shift_assignments', 'employees', 'shifts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
