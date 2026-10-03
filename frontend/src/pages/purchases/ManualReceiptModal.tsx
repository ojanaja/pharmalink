import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api } from '../../lib/api'
import { todayLocal } from '../../lib/format'
import type { LaravelPaginated, Medicine, Supplier } from '../../lib/types'

// Tanggal hari ini (Y-m-d, waktu lokal) untuk batas minimal expiry_date.
const TODAY = todayLocal()

interface ManualReceiptItem {
  medicine_id: number | null
  batch_number: string
  expiry_date: string
  quantity: number
  unit_cost: string
}

interface ManualReceiptModalProps {
  open: boolean
  onClose: () => void
}

const EMPTY_ITEM: ManualReceiptItem = {
  medicine_id: null,
  batch_number: '',
  expiry_date: '',
  quantity: 1,
  unit_cost: '',
}

/** Penerimaan manual tanpa PO — POST /api/receipts. */
export function ManualReceiptModal({ open, onClose }: ManualReceiptModalProps) {
  const [supplierId, setSupplierId] = useState('')
  const [items, setItems] = useState<ManualReceiptItem[]>([{ ...EMPTY_ITEM }])
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [created, setCreated] = useState<string | null>(null)

  if (open && !initialized) {
    setSupplierId('')
    setItems([{ ...EMPTY_ITEM }])
    setError(null)
    setCreated(null)
    setInitialized(true)
  }
  if (!open && initialized) {
    setInitialized(false)
  }

  const { data: suppliers } = useQuery({
    queryKey: ['suppliers'],
    queryFn: () => api<LaravelPaginated<Supplier>>('/suppliers?per_page=100'),
    enabled: open,
  })
  const { data: medicines } = useQuery({
    queryKey: ['medicines-all'],
    queryFn: () => api<LaravelPaginated<Medicine>>('/medicines?per_page=100'),
    enabled: open,
  })

  function updateItem(index: number, patch: Partial<ManualReceiptItem>) {
    setItems((prev) => prev.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  }

  async function handleSubmit() {
    setSubmitting(true)
    setError(null)
    try {
      const response = await api<{ data: { receipt_number: string } }>('/receipts', {
        method: 'POST',
        body: {
          supplier_id: supplierId === '' ? null : Number(supplierId),
          items: items.map((item) => ({
            medicine_id: item.medicine_id,
            batch_number: item.batch_number.trim(),
            expiry_date: item.expiry_date,
            quantity: item.quantity,
            unit_cost: item.unit_cost === '' ? null : Number(item.unit_cost),
          })),
        },
      })
      setCreated(response.data.receipt_number)
    } catch (err) {
      // Batch number bentrok antar obat / expiry berbeda ditolak BatchService dengan 422.
      setError(
        err instanceof ApiError ? err.message : 'Penerimaan gagal. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  const canSubmit =
    items.length > 0 &&
    items.every(
      (item) =>
        item.medicine_id !== null &&
        item.batch_number.trim() !== '' &&
        item.expiry_date !== '' &&
        item.expiry_date >= TODAY &&
        item.quantity > 0,
    ) &&
    !submitting

  return (
    <Modal
      open={open}
      title="Penerimaan Barang (tanpa PO)"
      onClose={onClose}
      wide
      footer={
        created ? (
          <Button onClick={onClose}>Tutup</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose}>
              Batal
            </Button>
            <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
              Simpan Penerimaan
            </Button>
          </>
        )
      }
    >
      {created ? (
        <div className="flex flex-col items-center gap-2 py-4 text-center">
          <p className="text-lg font-bold text-ink">Penerimaan tersimpan</p>
          <p className="text-[13px] font-semibold text-primary">{created}</p>
          <p className="text-[13px] text-ink-secondary">
            Stok batch bertambah dan tercatat pada kartu stok masing-masing obat.
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <Field label="Supplier (opsional)">
            <Select value={supplierId} onChange={(event) => setSupplierId(event.target.value)}>
              <option value="">Penerimaan manual (tanpa supplier)</option>
              {suppliers?.data.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </Select>
          </Field>

          <div className="flex items-center justify-between">
            <span className="text-[13px] font-semibold text-ink">Item diterima</span>
            <Button
              size="sm"
              variant="secondary"
              icon={<Plus size={14} />}
              onClick={() => setItems((prev) => [...prev, { ...EMPTY_ITEM }])}
            >
              Tambah item
            </Button>
          </div>

          <div className="flex flex-col gap-3">
            {items.map((item, index) => (
              <div key={index} className="rounded-lg border border-line p-3">
                <div className="flex items-center justify-between">
                  <span className="text-[11px] font-bold text-ink-secondary">Item {index + 1}</span>
                  <button
                    type="button"
                    aria-label={`Hapus item ${index + 1}`}
                    onClick={() => setItems((prev) => prev.filter((_, i) => i !== index))}
                    disabled={items.length === 1}
                    className="text-ink-secondary transition-colors hover:text-danger-ink disabled:opacity-40"
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
                <div className="mt-2 grid grid-cols-2 gap-2">
                  <div className="col-span-2">
                    <Select
                      value={item.medicine_id ?? ''}
                      onChange={(event) =>
                        updateItem(index, { medicine_id: Number(event.target.value) || null })
                      }
                      aria-label={`Obat item ${index + 1}`}
                    >
                      <option value="">Pilih obat…</option>
                      {medicines?.data.map((medicine) => (
                        <option key={medicine.id} value={medicine.id}>
                          {medicine.code} — {medicine.name}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <Input
                    value={item.batch_number}
                    onChange={(event) => updateItem(index, { batch_number: event.target.value })}
                    placeholder="No. batch *"
                    aria-label={`Nomor batch item ${index + 1}`}
                  />
                  <Input
                    type="date"
                    min={TODAY}
                    value={item.expiry_date}
                    onChange={(event) => updateItem(index, { expiry_date: event.target.value })}
                    aria-label={`Tanggal kedaluwarsa item ${index + 1}`}
                  />
                  <Input
                    type="number"
                    min={1}
                    value={item.quantity || ''}
                    onChange={(event) => updateItem(index, { quantity: Number(event.target.value) })}
                    placeholder="Qty *"
                    aria-label={`Jumlah item ${index + 1}`}
                  />
                  <Input
                    type="number"
                    min={0}
                    value={item.unit_cost}
                    onChange={(event) => updateItem(index, { unit_cost: event.target.value })}
                    placeholder="Harga beli (opsional)"
                    aria-label={`Harga beli item ${index + 1}`}
                  />
                </div>
              </div>
            ))}
          </div>

          {error && (
            <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
              {error}
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
