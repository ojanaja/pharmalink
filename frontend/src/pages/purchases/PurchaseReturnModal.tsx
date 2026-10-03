import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Field, Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api } from '../../lib/api'

/** Bentuk `errors.items` saat 422 retur melebihi sisa yang dapat diretur. */
interface ReturnErrorItem {
  purchase_receipt_item_id: number
  received?: number
  returned?: number
  requested?: number
  returnable?: number
}

interface PurchaseReturnModalProps {
  open: boolean
  supplierId: number | null
  item: { id: number; medicineName: string; batchNumber: string; returnable: number } | null
  onClose: () => void
  onSaved: (returnNumber: string) => void
}

export function PurchaseReturnModal({
  open,
  supplierId,
  item,
  onClose,
  onSaved,
}: PurchaseReturnModalProps) {
  const [quantity, setQuantity] = useState('')
  const [reason, setReason] = useState('')
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (item && !initialized) {
    // Qty default = seluruh sisa yang dapat diretur.
    setQuantity(String(item.returnable))
    setReason('')
    setError(null)
    setInitialized(true)
  }
  if (!item && initialized) {
    setInitialized(false)
  }

  async function handleSubmit() {
    if (!item) return
    setSubmitting(true)
    setError(null)
    try {
      const response = await api<{ data: { return_number: string } }>('/purchase-returns', {
        method: 'POST',
        body: {
          supplier_id: supplierId,
          reason: reason.trim(),
          items: [{ purchase_receipt_item_id: item.id, quantity: Number(quantity) }],
        },
      })
      setInitialized(false)
      onSaved(response.data.return_number)
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        const detail = err.errors as { items?: ReturnErrorItem[] }
        const match = detail.items?.find(
          (entry) => entry.purchase_receipt_item_id === item.id,
        )
        if (match?.returnable !== undefined) {
          setError(
            `Jumlah melebihi sisa yang dapat diretur (sisa ${match.returnable}, diminta ${match.requested ?? '-'}).`,
          )
          setSubmitting(false)
          return
        }
      }
      setError(err instanceof ApiError ? err.message : 'Retur gagal. Periksa koneksi dan coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  const qtyNum = Number(quantity)
  const overLimit = item !== null && qtyNum > item.returnable
  const canSubmit =
    item !== null && qtyNum > 0 && !overLimit && reason.trim().length >= 5 && !submitting

  return (
    <Modal
      open={open}
      title="Retur ke Supplier"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            Simpan Retur
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-[13px] text-ink-secondary">
          {item?.medicineName} · Batch {item?.batchNumber} — sisa yang dapat diretur{' '}
          {item?.returnable} unit.
        </p>
        <div className="grid grid-cols-2 gap-4">
          <Field
            label="Jumlah retur *"
            error={overLimit ? 'Melebihi sisa yang dapat diretur.' : undefined}
          >
            <Input
              type="number"
              min={1}
              max={item?.returnable}
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
              invalid={overLimit}
            />
          </Field>
          <Field label="Alasan retur * (minimal 5 karakter)">
            <Input
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="Contoh: Barang cacat dari supplier"
            />
          </Field>
        </div>
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}
