<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\NewMovement;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\UnitCost;

final class MovementFactory
{
    public static function purchase(
        int $productId,
        int $to,
        string|int $qty,
        string|int $cost = '1',
        int $userId = 1,
        ?string $uuid = null,
        ?string $key = null,
        ?string $sourceType = null,
    ): NewMovement {
        return new NewMovement(
            $uuid ?? Ids::uuid(), $key ?? Ids::key(), MovementType::Purchase, $productId,
            null, $to, Quantity::of($qty), $userId, unitCost: UnitCost::of($cost), sourceType: $sourceType,
        );
    }

    public static function opening(int $productId, int $to, string|int $qty, string|int $cost = '1', int $userId = 1, ?string $key = null): NewMovement
    {
        return new NewMovement(
            Ids::uuid(), $key ?? Ids::key(), MovementType::OpeningBalance, $productId,
            null, $to, Quantity::of($qty), $userId, unitCost: UnitCost::of($cost), sourceType: 'OPENING',
        );
    }

    public static function sale(int $productId, int $from, string|int $qty, int $userId = 1, ?string $uuid = null, ?string $key = null): NewMovement
    {
        return new NewMovement(
            $uuid ?? Ids::uuid(), $key ?? Ids::key(), MovementType::Sale, $productId,
            $from, null, Quantity::of($qty), $userId,
        );
    }

    public static function transfer(int $productId, int $from, int $to, string|int $qty, int $userId = 1): NewMovement
    {
        return new NewMovement(
            Ids::uuid(), Ids::key(), MovementType::Transfer, $productId,
            $from, $to, Quantity::of($qty), $userId,
        );
    }

    public static function writeOff(int $productId, int $from, string|int $qty, int $reasonId = 1, int $userId = 1, ?string $sourceType = null): NewMovement
    {
        return new NewMovement(
            Ids::uuid(), Ids::key(), MovementType::WriteOff, $productId,
            $from, null, Quantity::of($qty), $userId, reasonId: $reasonId, sourceType: $sourceType,
        );
    }

    public static function adjustmentOut(int $productId, int $from, string|int $qty, int $reasonId = 1, int $userId = 1, ?string $sourceType = null): NewMovement
    {
        return new NewMovement(
            Ids::uuid(), Ids::key(), MovementType::AdjustmentOut, $productId,
            $from, null, Quantity::of($qty), $userId, reasonId: $reasonId, sourceType: $sourceType,
        );
    }
}
