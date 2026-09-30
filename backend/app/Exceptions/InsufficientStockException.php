<?php

namespace App\Exceptions;

use DomainException;

/**
 * Dilempar saat pengurangan stok melebihi saldo batch.
 * Dirender sebagai HTTP 422 agar transaksi yang memicu bisa rollback utuh.
 */
class InsufficientStockException extends DomainException
{
    public function __construct(string $medicine, int $requested, int $available)
    {
        parent::__construct(
            "Stok '{$medicine}' tidak cukup: diminta {$requested}, tersedia {$available}."
        );
    }
}
