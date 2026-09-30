<?php

namespace App\Enums;

enum MovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case Sale = 'sale';
    case SaleCancellation = 'sale_cancellation';
    case Adjustment = 'adjustment';
    case Opname = 'opname';
    case ReturnIn = 'return_in';
    case ReturnOut = 'return_out';
}
