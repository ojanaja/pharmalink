import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api } from '../../lib/api'
import { formatRupiah } from '../../lib/format'
import type { LaravelPaginated, Medicine, Supplier } from '../../lib/types'

interface PoFormItem {
  medicine_id: number | null
  quantity: number
  unit_price: string
}

interface PurchaseOrderFormModalProps {
  open: boolean
  onClose: () => void
  onCreated: () => void
}

const EMPTY_ITEM: PoFormItem = { medicine_id: null, quantity: 1, unit_price: '' }

export function PurchaseOrderFormModal({ open, onClose, onCreated }: PurchaseOrderFormModalProps) {
  const [supplierId, setSupplierId] = useState('')
  const [expectedDate, setExpectedDate] = useState('')
  const [note, setNote] = useState('')
  const [items, setItems] = useState<PoFormItem[]>([{ ...EMPTY_ITEM }])
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

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

  // Total hanya menjumlah baris yang harganya diisi; baris tanpa harga mengikuti harga supplier (server-side).
  const estimatedTotal = useMemo(
    () =>
      items.reduce((sum, item) => {
        const price = Number(item.unit_price)
        return Number.isFinite(price) && price > 0 ? sum + price * item.quantity : sum
      }, 0),
    [items],
  )
  const somePriceEmpty = items.some((item) => item.unit_price === '')

  function updateItem(index: number, patch: Partial<PoFormItem>) {
    setItems((prev) => prev.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  }

  function reset() {
    setSupplierId('')
    setExpectedDate('')
    setNote('')
    setItems([{ ...EMPTY_ITEM }])
    setError(null)
  }

  async function handleSubmit() {
    setSubmitting(true)
    setError(null)
    try {
      await api('/purchase-orders', {
        method: 'POST',
        body: {
          supplier_id: Number(supplierId),
          expected_date: expectedDate || null,
          note: note || null,
          items: items.map((item) => ({
            medicine_id: item.medicine_id,
            quantity: item.quantity,
            // null = server mengambil harga dari pivot medicine_supplier
            unit_price: item.unit_price === '' ? null : Number(item.unit_price),
          })),
        },
      })
      reset()
      onCreated()
    } catch (err) {
      setError(
        err instanceof ApiError ? err.message : 'Gagal membuat pembelian. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  const canSubmit =
    supplierId !== '' &&
    items.length > 0 &&
    items.every((item) => item.medicine_id !== null && item.quantity > 0) &&
    !submitting

  return (
    <Modal
      open={open}
      title="Pembelian Baru"
      onClose={onClose}
      wide
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            Buat Pembelian
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-4">
          <Field label="Supplier *">
            <Select value={supplierId} onChange={(event) => setSupplierId(event.target.value)}>
              <option value="">Pilih supplier…</option>
              {suppliers?.data.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Tanggal perkiraan datang (opsional)">
            <Input
              type="date"
              value={expectedDate}
              onChange={(event) => setExpectedDate(event.target.value)}
            />
          </Field>
        </div>

        <div className="flex items-center justify-between">
          <span className="text-[13px] font-semibold text-ink">
            Item obat — {items.length} item
          </span>
          <Button
            size="sm"
            variant="secondary"
            icon={<Plus size={14} />}
            onClick={() => setItems((prev) => [...prev, { ...EMPTY_ITEM }])}
          >
            Tambah item
          </Button>
        </div>

        <div className="flex flex-col gap-2">
          {items.map((item, index) => (
            <div key={index} className="flex items-end gap-2">
              <div className="min-w-0 flex-1">
                {index === 0 && <span className="mb-1 block text-[11px] text-ink-secondary">Obat</span>}
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
              <div className="w-24">
                {index === 0 && <span className="mb-1 block text-[11px] text-ink-secondary">Qty</span>}
                <Input
                  type="number"
                  min={1}
                  value={item.quantity || ''}
                  onChange={(event) => updateItem(index, { quantity: Number(event.target.value) })}
                  aria-label={`Jumlah item ${index + 1}`}
                />
              </div>
              <div className="w-36">
                {index === 0 && <span className="mb-1 block text-[11px] text-ink-secondary">Harga beli</span>}
                <Input
                  type="number"
                  min={0}
                  placeholder="Otomatis"
                  value={item.unit_price}
                  onChange={(event) => updateItem(index, { unit_price: event.target.value })}
                  aria-label={`Harga beli item ${index + 1}`}
                />
              </div>
              <button
                type="button"
                aria-label={`Hapus item ${index + 1}`}
                onClick={() => setItems((prev) => prev.filter((_, i) => i !== index))}
                disabled={items.length === 1}
                className="mb-0.5 text-ink-secondary transition-colors hover:text-danger-ink disabled:opacity-40"
              >
                <Trash2 size={15} />
              </button>
            </div>
          ))}
        </div>

        <p className="rounded-lg bg-table-header px-3 py-2 text-[11px] text-ink-secondary">
          Batch dan tanggal kedaluwarsa dicatat saat penerimaan barang. Harga beli kosong mengikuti
          harga supplier terakhir.
        </p>

        <div className="flex items-center justify-between border-t border-line pt-3">
          <span className="text-[13px] text-ink-secondary">
            {somePriceEmpty ? 'Total perkiraan (sebagian harga mengikuti supplier)' : 'Total pembelian'}
          </span>
          <span className="text-xl font-bold text-primary">{formatRupiah(estimatedTotal)}</span>
        </div>

        <Field label="Catatan (opsional)">
          <Input value={note} onChange={(event) => setNote(event.target.value)} placeholder="Catatan pembelian" />
        </Field>

        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}
