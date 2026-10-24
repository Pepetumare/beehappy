<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleBlock extends Model
{
    protected $fillable = ['starts_at', 'ends_at', 'reason'];

    protected $hidden = ['reason'];

    // Explicit UTC accessors avoid interpreting persisted values in a local app timezone.
    public function getStartsAtAttribute(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    public function getEndsAtAttribute(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    public function therapistProfile(): BelongsTo
    {
        return $this->belongsTo(TherapistProfile::class);
    }
}
