import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api } from '../../lib/api'
import type { Category, Medicine, Unit } from '../../lib/types'

interface MedicineFormModalProps {
  open: boolean
  /** null = mode tambah; obat = mode edit. */
  medicine: Medicine | null
  onClose: () => void
  onSaved: () => void
}

export function MedicineFormModal({ open, medicine, onClose, onSaved }: MedicineFormModalProps) {
  const isEdit = medicine !== null
  const [form, setForm] = useState({
    code: '',
    name: '',
    category_id: '',
    unit_id: '',
    sale_price: '',
    min_stock: '',
    description: '',
  })
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (open && !initialized) {
    setForm({
      code: medicine?.code ?? '',
      name: medicine?.name ?? '',
      category_id: medicine?.category ? String(medicine.category.id) : '',
      unit_id: medicine?.unit ? String(medicine.unit.id) : '',
      sale_price: medicine ? String(Number(medicine.sale_price)) : '',
      min_stock: medicine ? String(medicine.min_stock) : '',
      // Deskripsi wajib dimuat ulang saat edit agar tidak tertimpa null (kehilangan data).
      description: medicine?.description ?? '',
    })
    setInitialized(true)
    setError(null)
  }
  if (!open && initialized) {
    setInitialized(false)
  }

  const { data: categories } = useQuery({
    queryKey: ['categories'],
    queryFn: () => api<{ data: Category[] }>('/categories'),
    enabled: open,
  })
  const { data: units } = useQuery({
    queryKey: ['units'],
    queryFn: () => api<{ data: Unit[] }>('/units'),
    enabled: open,
  })

  function setField(field: keyof typeof form, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  async function handleSubmit() {
    setSubmitting(true)
    setError(null)
    const body = {
      code: form.code.trim(),
      name: form.name.trim(),
      category_id: Number(form.category_id),
      unit_id: Number(form.unit_id),
      sale_price: Number(form.sale_price),
      min_stock: form.min_stock === '' ? 0 : Number(form.min_stock),
      description: form.description.trim() === '' ? null : form.description.trim(),
    }
    try {
      if (isEdit) {
        await api(`/medicines/${medicine.id}`, { method: 'PUT', body })
      } else {
        await api('/medicines', { method: 'POST', body })
      }
      setInitialized(false)
      onSaved()
    } catch (err) {
      setError(
        err instanceof ApiError ? err.message : 'Gagal menyimpan obat. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  const canSubmit =
    form.code.trim() !== '' &&
    form.name.trim() !== '' &&
    form.category_id !== '' &&
    form.unit_id !== '' &&
    form.sale_price !== '' &&
    Number(form.sale_price) >= 0 &&
    !submitting

  return (
    <Modal
      open={open}
      title={isEdit ? `Edit Obat — ${medicine.name}` : 'Tambah Obat'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            {isEdit ? 'Simpan perubahan' : 'Tambah obat'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-4">
          <Field label="Kode obat *">
            <Input value={form.code} onChange={(e) => setField('code', e.target.value)} placeholder="MED-006" />
          </Field>
          <Field label="Nama obat *">
            <Input value={form.name} onChange={(e) => setField('name', e.target.value)} placeholder="Ibuprofen 400mg" />
          </Field>
          <Field label="Kategori *">
            <Select value={form.category_id} onChange={(e) => setField('category_id', e.target.value)}>
              <option value="">Pilih kategori…</option>
              {categories?.data.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Satuan *">
            <Select value={form.unit_id} onChange={(e) => setField('unit_id', e.target.value)}>
              <option value="">Pilih satuan…</option>
              {units?.data.map((unit) => (
                <option key={unit.id} value={unit.id}>
                  {unit.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Harga jual (Rp) *">
            <Input
              type="number"
              min={0}
              value={form.sale_price}
              onChange={(e) => setField('sale_price', e.target.value)}
            />
          </Field>
          <Field label="Minimum stok">
            <Input
              type="number"
              min={0}
              value={form.min_stock}
              onChange={(e) => setField('min_stock', e.target.value)}
            />
          </Field>
        </div>
        <Field label="Deskripsi (opsional)">
          <textarea
            value={form.description}
            onChange={(e) => setField('description', e.target.value)}
            rows={2}
            className="w-full rounded-lg border border-input-border bg-surface px-3 py-2 text-sm text-ink outline-none transition-colors placeholder:text-placeholder focus:border-primary disabled:bg-neutral-bg disabled:opacity-60"
          />
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
