<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    // Application services explicitly assign trusted fields; never fill from request input.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => AppointmentStatus::class];
    }

    public function getStartsAtAttribute(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    public function getEndsAtAttribute(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    public function getBusyUntilAttribute(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    public function getCancelledAtAttribute(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    public function patientProfile(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function therapistProfile(): BelongsTo
    {
        return $this->belongsTo(TherapistProfile::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeOccupying(Builder $query): void
    {
        $query->where('status', '!=', AppointmentStatus::Cancelled->value);
    }

    public function belongsToUser(User $user): bool
    {
        return ($user->isPatient() && $this->patientProfile->user_id === $user->id)
            || ($user->isTherapist() && $this->therapistProfile->user_id === $user->id);
    }

    public function canCancel(): bool
    {
        return $this->status === AppointmentStatus::Scheduled && $this->starts_at->isFuture();
    }

    public function canRecordOutcome(): bool
    {
        return $this->status === AppointmentStatus::Scheduled && $this->ends_at->lte(CarbonImmutable::now('UTC'));
    }
}
