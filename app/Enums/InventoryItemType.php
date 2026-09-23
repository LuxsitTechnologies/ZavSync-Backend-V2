<?php

namespace App\Enums;

enum InventoryItemType: string
{
    case Inventory = 'inventory';
    case NonInventory = 'non_inventory';
    case Service = 'service';
}
