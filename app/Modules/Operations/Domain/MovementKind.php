<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/** Movimientos que Operations puede pedirle al módulo Inventory. */
enum MovementKind: string
{
    case Purchase = 'PURCHASE';
    case Transfer = 'TRANSFER';
    case AdjustmentIn = 'ADJUSTMENT_IN';
    case AdjustmentOut = 'ADJUSTMENT_OUT';
    case WriteOff = 'WRITE_OFF';
    case Sale = 'SALE';
    case OpeningBalance = 'OPENING_BALANCE';
}
