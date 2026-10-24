<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case PendingPayment = 'pending_payment';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pendiente de pago',
            self::Cancelled => 'Cancelada',
        };
    }
}
