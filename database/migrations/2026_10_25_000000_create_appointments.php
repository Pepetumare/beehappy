<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            // Restrict profile deletion: user cascades must never erase appointment history.
            $table->foreignId('patient_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('therapist_profile_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('busy_until');
            $table->string('status', 20);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('gap_minutes');
            $table->uuid('idempotency_key');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['patient_profile_id', 'idempotency_key'], 'appointment_patient_request');
            $table->index(['therapist_profile_id', 'starts_at', 'busy_until'], 'appointment_therapist_interval');
            $table->index(['patient_profile_id', 'starts_at', 'ends_at'], 'appointment_patient_interval');
        });
        DB::table('users')->where('role', 'paciente')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                DB::table('patient_profiles')->insert([
                    'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('patient_profiles');
    }
};
