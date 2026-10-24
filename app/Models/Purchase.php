<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Enums\SessionOfferType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    // Only trusted service data, never request->all().
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => PurchaseStatus::class, 'offer_type' => SessionOfferType::class, 'total_clp' => 'integer', 'sessions_count' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
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

    public function sessionOffer(): BelongsTo
    {
        return $this->belongsTo(SessionOffer::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isPatient()) {
            $query->whereHas('patientProfile', fn (Builder $profiles) => $profiles->where('user_id', $user->id));
        } elseif ($user->isTherapist()) {
            $query->whereHas('therapistProfile', fn (Builder $profiles) => $profiles->where('user_id', $user->id));
        } elseif (! $user->isAdministrator()) {
            $query->whereRaw('1 = 0');
        }
    }
}
