<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapist_profile_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->unsignedTinyInteger('sessions_count');
            $table->unsignedInteger('price_clp');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['therapist_profile_id', 'active'], 'offer_therapist_active');
            $table->unique(['id', 'therapist_profile_id'], 'offer_seller_pair');
        });
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('patient_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('therapist_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_offer_id')->constrained()->restrictOnDelete();
            $table->string('offer_name', 150);
            $table->string('offer_type', 20);
            $table->unsignedTinyInteger('sessions_count');
            $table->unsignedInteger('total_clp');
            $table->char('currency', 3);
            $table->string('status', 30);
            $table->uuid('idempotency_key');
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['patient_profile_id', 'idempotency_key'], 'purchase_patient_request');
            $table->index(['patient_profile_id', 'created_at'], 'purchase_patient_date');
            $table->index(['therapist_profile_id', 'created_at'], 'purchase_therapist_date');
            $table->foreign(['session_offer_id', 'therapist_profile_id'], 'purchase_offer_seller_pair')
                ->references(['id', 'therapist_profile_id'])->on('session_offers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('session_offers');
    }
};
