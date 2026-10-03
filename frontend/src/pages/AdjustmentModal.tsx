import { CircleAlert } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../components/ui/Button'
import { Field, Input } from '../components/ui/Field'
import { Modal } from '../components/ui/Modal'
import { ApiError, api } from '../lib/api'
import type { Batch } from '../lib/types'

interface AdjustmentModalProps {
  open: boolean
  medicineName: string
  batch: Batch | null
  onClose: () => void
  onSaved: () => void
}

export function AdjustmentModal({ open, medicineName, batch, onClose, onSaved }: AdjustmentModalProps) {
  const [direction, setDirection] = useState<'in' | 'out'>('in')
  const [quantity, setQuantity] = useState('')
  const [reason, setReason] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit() {
    if (!batch) return
    setSubmitting(true)
    setError(null)
    const qty = Number(quantity)
    const signed = direction === 'in' ? qty : -qty
    try {
      await api('/stock-adjustments', {
        method: 'POST',
        body: { batch_id: batch.id, quantity: signed, reason: reason.trim() },
      })
      setQuantity('')
      setReason('')
      onSaved()
    } catch (err) {
      // Koreksi keluar melebihi saldo ditolak server dengan 422.
      setError(err instanceof ApiError ? err.message : 'Koreksi gagal. Periksa koneksi dan coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  const qtyNum = Number(quantity)
  const reasonInvalid = reason.trim().length > 0 && reason.trim().length < 5
  const canSubmit = qtyNum > 0 && reason.trim().length >= 5 && !submitting

  return (
    <Modal
      open={open}
      title="Koreksi Stok"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            Simpan Koreksi
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-[13px] text-ink-secondary">
          {medicineName} · Batch {batch?.batch_number ?? '—'} · stok saat ini{' '}
          {new Intl.NumberFormat('id-ID').format(batch?.quantity_on_hand ?? 0)}
        </p>

        <div className="flex gap-2">
          {(
            [
              { key: 'in', label: 'Stok Masuk (+)' },
              { key: 'out', label: 'Stok Keluar (−)' },
            ] as const
          ).map((option) => (
            <button
              key={option.key}
              type="button"
              onClick={() => setDirection(option.key)}
              aria-pressed={direction === option.key}
              className={`flex-1 rounded-lg border px-3 py-2 text-[13px] font-bold transition-colors ${
                direction === option.key
                  ? 'border-primary bg-primary-soft text-primary'
                  : 'border-line bg-surface text-ink-secondary'
              }`}
            >
              {option.label}
            </button>
          ))}
        </div>

        <Field label="Jumlah *">
          <Input
            type="number"
            min={1}
            value={quantity}
            onChange={(event) => setQuantity(event.target.value)}
            placeholder="0"
          />
        </Field>
        <Field label="Alasan koreksi * (minimal 5 karakter)" error={reasonInvalid ? 'Alasan terlalu pendek.' : undefined}>
          <Input
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Contoh: Rusak kemasan"
          />
        </Field>

        {direction === 'out' && batch && qtyNum > (batch.quantity_on_hand ?? 0) && (
          <p className="flex items-center gap-1.5 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            <CircleAlert size={13} aria-hidden="true" />
            Jumlah keluar melebihi saldo batch — server akan menolak koreksi ini.
          </p>
        )}
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}
