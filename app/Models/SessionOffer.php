<?php

namespace App\Models;

use App\Enums\SessionOfferType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionOffer extends Model
{
    protected $fillable = ['name', 'description', 'type', 'sessions_count', 'price_clp', 'active'];

    protected function casts(): array
    {
        return ['type' => SessionOfferType::class, 'sessions_count' => 'integer', 'price_clp' => 'integer', 'active' => 'boolean'];
    }

    public function therapistProfile(): BelongsTo
    {
        return $this->belongsTo(TherapistProfile::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where('active', true)->whereHas('therapistProfile', fn (Builder $profiles) => $profiles->bookable());
    }

    public function scopeManageableBy(Builder $query, User $user): void
    {
        if ($user->isTherapist()) {
            $query->whereHas('therapistProfile', fn (Builder $profiles) => $profiles->where('user_id', $user->id));
        } elseif (! $user->isAdministrator()) {
            $query->whereRaw('1 = 0');
        }
    }
}
