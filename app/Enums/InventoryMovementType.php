<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case SaleIssue = 'sale_issue';
    case CustomerReturn = 'customer_return';
    case SupplierReturn = 'supplier_return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case PositiveAdjustment = 'positive_adjustment';
    case NegativeAdjustment = 'negative_adjustment';
    case CostAdjustment = 'cost_adjustment';
}
