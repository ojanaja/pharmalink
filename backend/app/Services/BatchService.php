<?php

namespace App\Services;

use App\Models\Batch;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Registry batch untuk penerimaan barang (manual maupun PO).
 *
 * Aturan identitas batch (keputusan revisi): batch di-scope per (obat,
 * nomor batch). Satu nomor batch dari supplier boleh dipakai untuk banyak
 * obat sekaligus dalam kiriman yang sama (boleh beda expiry antar obat);
 * yang dilarang adalah SATU OBAT punya dua tanggal kedaluwarsa berbeda
 * untuk nomor batch yang sama — itu hampir pasti salah ketik fatal.
 */
class BatchService
{
    /**
     * @throws HttpResponseException 422 jika batch obat yang sama punya expiry berbeda.
     */
    public function findOrCreateForReceipt(int $medicineId, string $batchNumber, string $expiryDate): Batch
    {
        $existing = Batch::query()
            ->where('medicine_id', $medicineId)
            ->where('batch_number', $batchNumber)
            ->first();

        if ($existing !== null) {
            $registeredExpiry = $existing->expiry_date?->toDateString();

            if ($registeredExpiry !== $expiryDate) {
                throw new HttpResponseException(response()->json([
                    'message' => "Batch {$batchNumber} sudah terdaftar dengan tanggal kedaluwarsa {$registeredExpiry}.",
                ], 422));
            }

            return $existing;
        }

        return Batch::create([
            'medicine_id' => $medicineId,
            'batch_number' => $batchNumber,
            'expiry_date' => Carbon::createFromFormat('Y-m-d', $expiryDate)->toDateString(),
            'quantity_on_hand' => 0,
            'received_at' => today(),
        ]);
    }
}
