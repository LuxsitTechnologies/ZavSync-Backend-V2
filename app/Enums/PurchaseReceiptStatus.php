<?php

namespace App\Enums;

enum PurchaseReceiptStatus: string
{
    case Partial = 'partial';
    case Complete = 'complete';
}
