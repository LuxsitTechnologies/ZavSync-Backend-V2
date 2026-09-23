<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partial';
    case Paid = 'paid';
    case Void = 'void';

    public function isPosted(): bool
    {
        return $this !== self::Draft;
    }

    public function acceptsPayments(): bool
    {
        return in_array($this, [self::Unpaid, self::PartiallyPaid], true);
    }
}
