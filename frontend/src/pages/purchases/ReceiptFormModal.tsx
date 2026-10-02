import { CircleAlert } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api } from '../../lib/api'
import type { PoItem } from '../../lib/types'

// Tanggal hari ini (Y-m-d) untuk validasi client expiry_date; dihitung sekali saat modul dimuat.
const TODAY = new Date().toISOString().slice(0, 10)

/** Bentuk `errors.items` saat 422 penerimaan melebihi sisa pesanan. */
interface ReceiptErrorItem {
  po_item_id: number
  remaining?: number
  requested?: number
}

interface ReceiptFormRow {
  po_item_id: number
  medicineName: string
  quantity: number
  batch_number: string
  expiry_date: string
  unit_cost: string
}

interface ReceiptFormModalProps {
  open: boolean
  poId: number
  /** Hanya item dengan sisa pesanan yang bisa diterima. */
  items: PoItem[]
  onClose: () => void
  onCreated: () => void
}

export function ReceiptFormModal({ open, poId, items, onClose, onCreated }: ReceiptFormModalProps) {
  
  const [rows, setRows] = useState<ReceiptFormRow[]>([])
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [errors, setErrors] = useState<Map<number, string>>(new Map())
  const [generalError, setGeneralError] = useState<string | null>(null)

  // Baris dibangun saat modal dibuka: qty default = sisa pesanan, harga = harga PO.
  if (open && !initialized) {
    setRows(
      items
        .filter((item) => item.received_quantity !== null)
        .map((item) => {
          const remaining = item.quantity - (item.received_quantity ?? 0)
          return {
            po_item_id: item.id,
            medicineName: item.medicine?.name ?? `Item #${item.id}`,
            quantity: remaining,
            batch_number: '',
            expiry_date: '',
            unit_cost: item.unit_price,
          }
        })
        .filter((row) => row.quantity > 0),
    )
    setInitialized(true)
    setErrors(new Map())
    setGeneralError(null)
  }
  if (!open && initialized) {
    setInitialized(false)
  }

  function updateRow(poItemId: number, patch: Partial<ReceiptFormRow>) {
    setRows((prev) => prev.map((row) => (row.po_item_id === poItemId ? { ...row, ...patch } : row)))
    setErrors((prev) => {
      const next = new Map(prev)
      next.delete(poItemId)
      return next
    })
  }

  async function handleSubmit() {
    setSubmitting(true)
    setGeneralError(null)
    try {
      await api(`/purchase-orders/${poId}/receipts`, {
        method: 'POST',
        body: {
          items: rows.map((row) => ({
            po_item_id: row.po_item_id,
            batch_number: row.batch_number,
            expiry_date: row.expiry_date,
            quantity: row.quantity,
            unit_cost: row.unit_cost === '' ? null : Number(row.unit_cost),
          })),
        },
      })
      setInitialized(false)
      onCreated()
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        const detail = err.errors as { items?: ReceiptErrorItem[] }
        if (Array.isArray(detail.items) && detail.items.length > 0) {
          const next = new Map<number, string>()
          for (const item of detail.items) {
            if (item.remaining !== undefined) {
              next.set(
                item.po_item_id,
                `Jumlah melebihi sisa pesanan (sisa ${item.remaining}, diminta ${item.requested ?? '-'}).`,
              )
            } else {
              next.set(item.po_item_id, 'Data item tidak valid.')
            }
          }
          setErrors(next)
          setSubmitting(false)
          return
        }
      }
      setGeneralError(
        err instanceof ApiError ? err.message : 'Penerimaan gagal. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  const canSubmit =
    rows.length > 0 &&
    rows.every(
      (row) =>
        row.quantity > 0 &&
        row.batch_number.trim() !== '' &&
        row.expiry_date !== '' &&
        row.expiry_date >= TODAY,
    ) &&
    !submitting

  return (
    <Modal
      open={open}
      title="Penerimaan Barang"
      onClose={onClose}
      wide
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            Simpan Penerimaan
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="rounded-lg bg-info-bg px-3 py-2 text-[11px] text-info-ink">
          Batch dan tanggal kedaluwarsa menentukan stok yang dapat dijual. Cocokkan dengan kemasan
          fisik sebelum menyimpan.
        </p>

        {rows.length === 0 && (
          <p className="rounded-lg bg-table-header px-4 py-6 text-center text-[13px] text-ink-secondary">
            Semua item PO ini sudah diterima penuh.
          </p>
        )}

        <div className="flex flex-col gap-3">
          {rows.map((row) => (
            <div key={row.po_item_id} className="rounded-lg border border-line p-3">
              <p className="mb-2 text-[13px] font-semibold text-ink">{row.medicineName}</p>
              <div className="grid grid-cols-4 gap-2">
                <div className="flex flex-col gap-1">
                  <span className="text-[11px] text-ink-secondary">Qty diterima</span>
                  <Input
                    type="number"
                    min={1}
                    value={row.quantity || ''}
                    onChange={(event) =>
                      updateRow(row.po_item_id, { quantity: Number(event.target.value) })
                    }
                    invalid={errors.has(row.po_item_id)}
                    aria-label={`Jumlah diterima ${row.medicineName}`}
                  />
                </div>
                <div className="flex flex-col gap-1">
                  <span className="text-[11px] text-ink-secondary">No. batch *</span>
                  <Input
                    value={row.batch_number}
                    onChange={(event) =>
                      updateRow(row.po_item_id, { batch_number: event.target.value })
                    }
                    placeholder="BTH-…"
                    aria-label={`Nomor batch ${row.medicineName}`}
                  />
                </div>
                <div className="flex flex-col gap-1">
                  <span className="text-[11px] text-ink-secondary">Expired *</span>
                  <Input
                    type="date"
                    min={TODAY}
                    value={row.expiry_date}
                    onChange={(event) =>
                      updateRow(row.po_item_id, { expiry_date: event.target.value })
                    }
                    aria-label={`Tanggal kedaluwarsa ${row.medicineName}`}
                  />
                </div>
                <div className="flex flex-col gap-1">
                  <span className="text-[11px] text-ink-secondary">Harga beli</span>
                  <Input
                    type="number"
                    min={0}
                    value={row.unit_cost}
                    onChange={(event) =>
                      updateRow(row.po_item_id, { unit_cost: event.target.value })
                    }
                    aria-label={`Harga beli ${row.medicineName}`}
                  />
                </div>
              </div>
              {errors.has(row.po_item_id) && (
                <p className="mt-2 flex items-center gap-1 text-xs text-danger-ink">
                  <CircleAlert size={13} aria-hidden="true" />
                  {errors.get(row.po_item_id)}
                </p>
              )}
            </div>
          ))}
        </div>

        {generalError && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {generalError}
          </div>
        )}
      </div>
    </Modal>
  )
}
