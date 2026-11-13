<?php

declare(strict_types=1);

namespace Reservation\Domain;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
}
