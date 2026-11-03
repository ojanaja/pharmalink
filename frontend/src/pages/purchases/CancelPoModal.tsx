import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api } from '../../lib/api'
import type { PurchaseOrderListItem } from '../../lib/types'

/** Konfirmasi pembatalan PO — hanya status ordered yang dapat dibatalkan (409 bila sudah diproses). */
export function CancelPoModal({
  target,
  onClose,
  onSaved,
}: {
  target: Pick<PurchaseOrderListItem, 'id' | 'po_number'> | null
  onClose: () => void
  onSaved: (poNumber: string) => void
}) {
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleCancel() {
    if (!target) return
    setSubmitting(true)
    setError(null)
    try {
      await api(`/purchase-orders/${target.id}/cancel`, { method: 'POST', body: {} })
      onSaved(target.po_number)
    } catch (err) {
      // 409: status bukan ordered (sudah diterima sebagian/penuh); 422: validasi lain.
      setError(err instanceof ApiError ? err.message : 'Pembatalan PO gagal. Coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={target !== null}
      title={`Batalkan PO ${target?.po_number ?? ''}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" loading={submitting} onClick={handleCancel}>
            Batalkan PO
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p>Pesanan ke supplier ini akan ditandai dibatalkan.</p>
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}
