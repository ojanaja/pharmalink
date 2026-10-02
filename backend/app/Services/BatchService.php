<?php

namespace App\Services;

use App\Models\Batch;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Registry batch untuk penerimaan barang (manual maupun PO).
 *
 * Aturan identitas batch: nomor batch adalah identitas fisik kemasan dari
 * supplier. Satu nomor batch tidak boleh dipakai untuk dua obat berbeda,
 * dan tidak boleh memiliki dua tanggal kedaluwarsa berbeda — bahkan antar
 * obat. Duplikat semacam itu hampir pasti salah ketik dan merusak FEFO.
 */
class BatchService
{
    /**
     * @throws HttpResponseException 422 jika nomor batch bentrok.
     */
    public function findOrCreateForReceipt(int $medicineId, string $batchNumber, string $expiryDate): Batch
    {
        // Sengaja TIDAK memfilter medicine_id: bentrok antar obat tetap tertangkap.
        $existing = Batch::query()->where('batch_number', $batchNumber)->first();

        if ($existing !== null) {
            if ($existing->medicine_id !== $medicineId) {
                throw new HttpResponseException(response()->json([
                    'message' => "Nomor batch {$batchNumber} sudah dipakai untuk obat lain ({$existing->medicine?->name}); satu nomor batch tidak boleh dipakai dua obat.",
                ], 422));
            }

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
