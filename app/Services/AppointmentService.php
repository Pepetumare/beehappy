<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\PatientProfile;
use App\Models\TherapistProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(private AvailabilityService $availability) {}

    public function parseStart(string $value): CarbonImmutable
    {
        $value = str_replace('Z', '+00:00', $value);
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00\+00:00\z/', $value)) {
            throw ValidationException::withMessages(['starts_at' => 'Selecciona un horario válido de la disponibilidad.']);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date || $date->format('Y-m-d\TH:i:sP') !== $value) {
            throw ValidationException::withMessages(['starts_at' => 'Selecciona un horario válido de la disponibilidad.']);
        }

        return $date->utc();
    }

    /** Global order: patient profile, therapist profile, users ascending ID, then appointment. */
    public function create(User $actor, int $therapistId, string $date, string $startsAt, string $key): Appointment
    {
        abort_unless($actor->isPatient() && $actor->is_active, 403);
        $key = strtolower($key);
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $this->availability->localDate($date, false);
        $start = $this->parseStart($startsAt);
        if ($start->setTimezone(config('appointments.timezone'))->toDateString() !== $date) {
            throw ValidationException::withMessages(['date' => 'La fecha no coincide con el horario seleccionado.']);
        }
        $patientId = $actor->patientProfile()->firstOrCreate()->id;

        return DB::transaction(function () use ($patientId, $therapistId, $date, $start, $key) {
            $patient = PatientProfile::whereKey($patientId)->lockForUpdate()->firstOrFail();
            $therapist = TherapistProfile::whereKey($therapistId)->lockForUpdate()->firstOrFail();
            $users = User::whereIn('id', [$patient->user_id, $therapist->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($users[$patient->user_id]->isPatient() && $users[$patient->user_id]->is_active, 403);
            $existing = $patient->appointments()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->therapist_profile_id !== $therapistId || ! $existing->starts_at->equalTo($start)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Esta solicitud ya se utilizó para otros datos. Actualiza la disponibilidad.']);
                }

                return $existing; // Includes cancelled appointments; never reactivate a retry.
            }
            $this->availability->localDate($date);
            $slots = $this->availability->forDate($therapist, $date, true, $patient);
            if (! $start->isFuture() || ! collect($slots)->contains(fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->equalTo($start))) {
                throw ValidationException::withMessages(['starts_at' => 'Este horario ya no está disponible. Actualiza la disponibilidad y elige otro.']);
            }
            $duration = (int) config('appointments.session_minutes');
            $gap = (int) config('appointments.gap_minutes');
            $end = $start->addMinutes($duration);

            return $patient->appointments()->create([
                'therapist_profile_id' => $therapist->id,
                'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'),
                'busy_until' => $end->addMinutes($gap)->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration, 'gap_minutes' => $gap,
                'status' => AppointmentStatus::Scheduled, 'idempotency_key' => $key,
            ]);
        }, 3);
    }

    private function change(User $actor, Appointment $appointment, callable $operation): Appointment
    {
        abort_unless($actor->is_active && $appointment->belongsToUser($actor), 404);

        return DB::transaction(function () use ($actor, $appointment, $operation) {
            PatientProfile::whereKey($appointment->patient_profile_id)->lockForUpdate()->firstOrFail();
            TherapistProfile::whereKey($appointment->therapist_profile_id)->lockForUpdate()->firstOrFail();
            $currentActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $current = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_unless($currentActor->is_active && $current->belongsToUser($currentActor), 404);
            $operation($current, $currentActor);

            return $current;
        }, 3);
    }

    public function cancel(User $actor, Appointment $appointment, ?string $reason = null): Appointment
    {
        Validator::make(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:500']])->validate();

        return $this->change($actor, $appointment, function ($current, $currentActor) use ($reason) {
            if ($current->status === AppointmentStatus::Cancelled) {
                return;
            }
            if (! $current->canCancel()) {
                throw ValidationException::withMessages(['appointment' => 'Solo puedes cancelar citas futuras que estén agendadas.']);
            }
            $current->update([
                'status' => AppointmentStatus::Cancelled, 'cancelled_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
                'cancelled_by' => $currentActor->id, 'cancellation_reason' => $reason,
            ]);
        });
    }

    public function recordOutcome(User $actor, Appointment $appointment, AppointmentStatus $status): Appointment
    {
        abort_unless($actor->isTherapist(), 403);

        return $this->change($actor, $appointment, function ($current) use ($status) {
            if (! in_array($status, [AppointmentStatus::Completed, AppointmentStatus::NoShow], true) || ! $current->canRecordOutcome()) {
                throw ValidationException::withMessages(['appointment' => 'Solo puedes registrar asistencia después de terminar una cita agendada.']);
            }
            $current->update(['status' => $status]);
        });
    }
}
