<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Models\PatientProfile;
use App\Models\Purchase;
use App\Models\SessionOffer;
use App\Models\TherapistProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function create(User $actor, int $offerId, string $key): Purchase
    {
        abort_unless($actor->isPatient() && $actor->is_active, 403);
        $key = strtolower($key);
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $offer = SessionOffer::findOrFail($offerId);
        $patientId = $actor->patientProfile()->firstOrCreate()->id;

        return DB::transaction(function () use ($offer, $patientId, $key) {
            // Fixed order: patient, professional, users ascending, offer, purchase.
            $patient = PatientProfile::whereKey($patientId)->lockForUpdate()->firstOrFail();
            $therapist = TherapistProfile::whereKey($offer->therapist_profile_id)->lockForUpdate()->firstOrFail();
            $users = User::whereIn('id', [$patient->user_id, $therapist->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($users[$patient->user_id]->is_active && $users[$patient->user_id]->isPatient(), 403);
            $currentOffer = $therapist->sessionOffers()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $existing = $patient->purchases()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->session_offer_id !== $currentOffer->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Esta solicitud ya se utilizó para otra oferta. Inicia una nueva solicitud.']);
                }

                return $existing;
            }
            $seller = $users[$therapist->user_id];
            if (! $currentOffer->active || ! $seller->is_active || ! $seller->isTherapist() || ! $therapist->accepts_appointments) {
                throw ValidationException::withMessages(['offer' => 'Esta oferta ya no está disponible. Consulta las ofertas actuales del profesional.']);
            }

            return $patient->purchases()->create([
                'reference' => (string) Str::ulid(), 'therapist_profile_id' => $therapist->id,
                'session_offer_id' => $currentOffer->id, 'offer_name' => $currentOffer->name,
                'offer_type' => $currentOffer->type, 'sessions_count' => $currentOffer->sessions_count,
                'total_clp' => $currentOffer->price_clp, 'currency' => 'CLP',
                'status' => PurchaseStatus::PendingPayment, 'idempotency_key' => $key,
            ]);
        }, 3);
    }

    public function cancel(User $actor, string $reference): Purchase
    {
        abort_unless($actor->isPatient() && $actor->is_active, 403);
        $purchase = Purchase::visibleTo($actor)->where('reference', $reference)->firstOrFail();

        return DB::transaction(function () use ($actor, $purchase) {
            PatientProfile::whereKey($purchase->patient_profile_id)->lockForUpdate()->firstOrFail();
            TherapistProfile::whereKey($purchase->therapist_profile_id)->lockForUpdate()->firstOrFail();
            $currentActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($currentActor->isPatient() && $currentActor->is_active, 403);
            $current = Purchase::visibleTo($currentActor)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($current->status === PurchaseStatus::Cancelled) {
                return $current;
            }
            if ($current->status !== PurchaseStatus::PendingPayment) {
                throw ValidationException::withMessages(['purchase' => 'Solo puedes cancelar solicitudes pendientes de pago.']);
            }
            $current->update(['status' => PurchaseStatus::Cancelled, 'cancelled_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')]);

            return $current;
        }, 3);
    }
}
