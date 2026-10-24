<?php

namespace App\Services;

use App\Models\PatientProfile;
use App\Models\TherapistProfile;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AvailabilityService
{
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('appointments.timezone'))->startOfDay();
    }

    public function lastDate(): CarbonImmutable
    {
        return $this->today()->addDays(config('appointments.horizon_days') - 1);
    }

    public function localDate(string $date, bool $limitHorizon = true): CarbonImmutable
    {
        if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw ValidationException::withMessages(['date' => 'Ingresa una fecha válida con formato AAAA-MM-DD.']);
        }
        $day = CarbonImmutable::parse($date, config('appointments.timezone'))->startOfDay();
        if ($limitHorizon && ($date < $this->today()->toDateString() || $date > $this->lastDate()->toDateString())) {
            throw ValidationException::withMessages(['date' => 'Elige una fecha entre '.$this->today()->toDateString().' y '.$this->lastDate()->toDateString().'.']);
        }

        return $day;
    }

    /** Resolve repeated wall times explicitly; a range covers both occurrences. */
    public function localTime(string $date, string $time, bool $latest = false): ?CarbonImmutable
    {
        $wall = $date.' '.substr($time, 0, 5);
        $naive = CarbonImmutable::parse($wall, 'UTC');
        $zone = new \DateTimeZone(config('appointments.timezone'));
        $transitions = $zone->getTransitions($naive->subDays(2)->timestamp, $naive->addDays(2)->timestamp);
        $candidates = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $candidate = $naive->subSeconds($offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i') === $wall) {
                $candidates[$candidate->timestamp] = $candidate;
            }
        }
        ksort($candidates);

        return $candidates ? ($latest ? end($candidates) : reset($candidates)) : null;
    }

    /** Sessions use elapsed minutes, traversing DST changes on the UTC timeline. */
    public function forDate(TherapistProfile $therapist, string $date, bool $currentReads = false, ?PatientProfile $patient = null): array
    {
        $day = $this->localDate($date);
        $userQuery = $therapist->user();
        $profileQuery = TherapistProfile::whereKey($therapist->id);
        if ($currentReads) {
            $userQuery->lockForUpdate();
            $profileQuery->lockForUpdate();
        }
        $user = $userQuery->first();
        if (! $user || ! $user->is_active || ! $user->isTherapist() || ! $profileQuery->first()?->accepts_appointments) {
            return [];
        }

        $zone = config('appointments.timezone');
        $nextDay = $this->localDate($day->addDay()->toDateString(), false);
        $blockQuery = $therapist->blocks()
            ->where('starts_at', '<', $nextDay->utc()->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $day->utc()->format('Y-m-d H:i:s'));
        $scheduleQuery = $therapist->schedules()->where('day_of_week', $day->isoWeekday())->orderBy('start_time');
        $busyQuery = $therapist->appointments()->occupying()
            ->where('busy_until', '>', $day->utc()->format('Y-m-d H:i:s'))
            ->where('starts_at', '<', $nextDay->utc()->addMinutes(config('appointments.gap_minutes'))->format('Y-m-d H:i:s'));
        $patientQuery = $patient?->appointments()->occupying()
            ->where('ends_at', '>', $day->utc()->format('Y-m-d H:i:s'))
            ->where('starts_at', '<', $nextDay->utc()->format('Y-m-d H:i:s'));
        if ($currentReads) {
            $blockQuery->lockForUpdate();
            $scheduleQuery->lockForUpdate();
            $busyQuery->lockForUpdate();
            $patientQuery?->lockForUpdate();
        }
        $blocks = $blockQuery->get();
        $schedules = $scheduleQuery->get();
        $busy = $busyQuery->get();
        $patientBusy = $patientQuery?->get() ?? collect();
        $duration = (int) config('appointments.session_minutes');
        $step = $duration + (int) config('appointments.gap_minutes');
        $now = CarbonImmutable::now('UTC');
        $slots = [];
        foreach ($schedules as $schedule) {
            $start = $this->localTime($date, $schedule->start_time);
            $limit = $this->localTime($date, $schedule->end_time, true);
            // Skip non-existent local endpoints rather than silently moving a weekly shift.
            if (! $start || ! $limit) {
                continue;
            }
            for ($cursor = $start; $cursor->addMinutes($duration)->lte($limit); $cursor = $cursor->addMinutes($step)) {
                $end = $cursor->addMinutes($duration);
                if ($cursor->lt($now)) {
                    continue;
                }
                if (! $this->fitsShift($cursor, $end, $schedule)) {
                    continue;
                }
                $busyUntil = $end->addMinutes(config('appointments.gap_minutes'));
                if ($blocks->contains(fn ($block) => $block->starts_at->lt($busyUntil) && $block->ends_at->gt($cursor))) {
                    continue;
                }
                if ($busy->contains(fn ($appointment) => $appointment->starts_at->lt($busyUntil) && $appointment->busy_until->gt($cursor))
                    || $patientBusy->contains(fn ($appointment) => $appointment->starts_at->lt($end) && $appointment->ends_at->gt($cursor))) {
                    continue;
                }
                $slots[] = [
                    'starts_at' => $cursor->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                    'label' => $cursor->setTimezone($zone)->format('H:i').'–'.$end->setTimezone($zone)->format('H:i'),
                ];
            }
        }

        return $slots;
    }

    /** Check real session coverage, without imposing today's duration or slot grid. */
    public function fitsShift(CarbonImmutable $start, CarbonImmutable $end, $schedule): bool
    {
        $zone = config('appointments.timezone');
        $date = $start->setTimezone($zone)->toDateString();
        if ($start->setTimezone($zone)->isoWeekday() !== (int) $schedule->day_of_week) {
            return false;
        }
        $points = [$start, $end->subMicrosecond()];
        $transitions = (new \DateTimeZone($zone))->getTransitions($start->timestamp, $end->timestamp);
        foreach (array_slice($transitions, 1) as $transition) {
            $instant = CarbonImmutable::createFromTimestampUTC($transition['ts']);
            if ($instant->gt($start) && $instant->lt($end)) {
                $points[] = $instant;
                $points[] = $instant->subMicrosecond();
            }
        }

        return collect($points)->every(function ($point) use ($zone, $date, $schedule) {
            $local = $point->setTimezone($zone);
            $clock = $local->format('H:i:s');

            return $local->toDateString() === $date
                && $clock >= substr($schedule->start_time, 0, 5).':00'
                && $clock < substr($schedule->end_time, 0, 5).':00';
        });
    }
}
