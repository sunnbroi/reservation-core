<?php

declare(strict_types=1);

namespace Reservation\Domain;

enum BookingStatus: string
{
    case Held = 'held';
    case PaymentPending = 'payment_pending';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case PaymentFailed = 'payment_failed';
    case ReviewRequired = 'review_required';
}
