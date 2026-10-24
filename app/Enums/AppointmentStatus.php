<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendada',
            self::Cancelled => 'Cancelada',
            self::Completed => 'Realizada',
            self::NoShow => 'No asistió',
        };
    }
}
