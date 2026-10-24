<?php

namespace App\Services;

use App\Models\TherapistProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TherapistCalendarService
{
    public function __construct(private AvailabilityService $availability) {}

    public function change(User $actor, callable $operation): void
    {
        abort_unless($actor->is_active && $actor->isTherapist(), 403);
        $id = $actor->therapistProfile()->firstOrCreate()->id;
        DB::transaction(function () use ($id, $actor, $operation) {
            // Calendar operations only lock the therapist; never acquire a patient afterwards.
            $profile = TherapistProfile::whereKey($id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $user->isTherapist(), 403);
            $operation($profile);
            $this->assertAppointmentsCovered($profile);
        }, 3);
    }

    private function assertAppointmentsCovered(TherapistProfile $profile): void
    {
        $appointments = $profile->appointments()->occupying()
            ->where('busy_until', '>', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))->lockForUpdate()->get();
        $shifts = $profile->schedules()->lockForUpdate()->get();
        $blocks = $profile->blocks()->lockForUpdate()->get();
        foreach ($appointments as $appointment) {
            $covered = $shifts->contains(fn ($shift) => $this->availability->fitsShift($appointment->starts_at, $appointment->ends_at, $shift));
            $blocked = $blocks->contains(fn ($block) => $block->starts_at->lt($appointment->busy_until) && $block->ends_at->gt($appointment->starts_at));
            if (! $covered || $blocked) {
                throw ValidationException::withMessages(['calendar' => 'Este cambio afecta una cita vigente. Conserva su tramo y horario o cancela la cita explícitamente antes de modificarlo.']);
            }
        }
    }
}
