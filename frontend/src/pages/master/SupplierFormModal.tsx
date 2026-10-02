import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Field, Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api } from '../../lib/api'
import type { Supplier } from '../../lib/types'

interface SupplierFormModalProps {
  open: boolean
  supplier: Supplier | null
  onClose: () => void
  onSaved: () => void
}

export function SupplierFormModal({ open, supplier, onClose, onSaved }: SupplierFormModalProps) {
  const isEdit = supplier !== null
  const [form, setForm] = useState({ name: '', contact_person: '', phone: '', address: '' })
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (open && !initialized) {
    setForm({
      name: supplier?.name ?? '',
      contact_person: supplier?.contact_person ?? '',
      phone: supplier?.phone ?? '',
      address: supplier?.address ?? '',
    })
    setInitialized(true)
    setError(null)
  }
  if (!open && initialized) {
    setInitialized(false)
  }

  function setField(field: keyof typeof form, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  async function handleSubmit() {
    setSubmitting(true)
    setError(null)
    const body = {
      name: form.name.trim(),
      contact_person: form.contact_person.trim() === '' ? null : form.contact_person.trim(),
      phone: form.phone.trim() === '' ? null : form.phone.trim(),
      address: form.address.trim() === '' ? null : form.address.trim(),
    }
    try {
      if (isEdit) {
        await api(`/suppliers/${supplier.id}`, { method: 'PUT', body })
      } else {
        await api('/suppliers', { method: 'POST', body })
      }
      setInitialized(false)
      onSaved()
    } catch (err) {
      setError(
        err instanceof ApiError ? err.message : 'Gagal menyimpan supplier. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  const canSubmit = form.name.trim() !== '' && !submitting

  return (
    <Modal
      open={open}
      title={isEdit ? `Edit Supplier — ${supplier.name}` : 'Tambah Supplier'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            {isEdit ? 'Simpan perubahan' : 'Tambah supplier'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Nama supplier *">
          <Input value={form.name} onChange={(e) => setField('name', e.target.value)} placeholder="PT Sumber Sehat Medika" />
        </Field>
        <div className="grid grid-cols-2 gap-4">
          <Field label="Kontak person">
            <Input value={form.contact_person} onChange={(e) => setField('contact_person', e.target.value)} />
          </Field>
          <Field label="Telepon">
            <Input value={form.phone} onChange={(e) => setField('phone', e.target.value)} />
          </Field>
        </div>
        <Field label="Alamat">
          <Input value={form.address} onChange={(e) => setField('address', e.target.value)} />
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
