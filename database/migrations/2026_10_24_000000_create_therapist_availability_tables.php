<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. Guards allow safely resuming an interrupted migration.
        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_active')->default(true);
            });
        }
        if (! Schema::hasTable('therapist_profiles')) {
            Schema::create('therapist_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('commune', 120)->nullable();
                $table->text('introduction')->nullable();
                $table->boolean('accepts_appointments')->default(true)->index();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('therapist_schedules')) {
            Schema::create('therapist_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('therapist_profile_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('day_of_week');
                $table->time('start_time');
                $table->time('end_time');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('therapist_schedules', 'therapist_schedule_day_start')) {
            Schema::table('therapist_schedules', function (Blueprint $table) {
                $table->index(['therapist_profile_id', 'day_of_week', 'start_time'], 'therapist_schedule_day_start');
            });
        }
        if (! Schema::hasTable('schedule_blocks')) {
            Schema::create('schedule_blocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('therapist_profile_id')->constrained()->cascadeOnDelete();
                // UTC by contract, independent of the application timezone.
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('reason', 500)->nullable();
                $table->timestamps();
                $table->index(['therapist_profile_id', 'starts_at', 'ends_at'], 'therapist_block_interval');
            });
        }
        DB::table('users')->where('role', 'apiterapeuta')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                DB::table('therapist_profiles')->insertOrIgnore([
                    'user_id' => $user->id, 'accepts_appointments' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_blocks');
        Schema::dropIfExists('therapist_schedules');
        Schema::dropIfExists('therapist_profiles');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};
