<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TherapistProfile extends Model
{
    protected $attributes = ['accepts_appointments' => true];

    protected $fillable = ['commune', 'introduction', 'accepts_appointments'];

    protected function casts(): array
    {
        return ['accepts_appointments' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TherapistSchedule::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ScheduleBlock::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function sessionOffers(): HasMany
    {
        return $this->hasMany(SessionOffer::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function scopeBookable(Builder $query): void
    {
        $query->where('accepts_appointments', true)->whereHas('user', fn (Builder $users) => $users->where('is_active', true)->where('role', User::ROLE_THERAPIST));
    }
}
