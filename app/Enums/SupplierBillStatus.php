<?php

namespace App\Enums;

enum SupplierBillStatus: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partial';
    case Paid = 'paid';
    case Void = 'void';

    public function acceptsPayments(): bool
    {
        return in_array($this, [self::Unpaid, self::PartiallyPaid], true);
    }
}
