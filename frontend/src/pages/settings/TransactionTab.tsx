import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Field, Input } from '../../components/ui/Field'
import { ApiError, api } from '../../lib/api'
import type { PharmacySettings } from '../../lib/types'

/** Transaksi — sesuai kontrak nyata: 4 prefix + ambang kedaluwarsa (Figma #45:7800 memuat opsi tanpa backend, tidak digambar). */
export function TransactionTab() {
  const queryClient = useQueryClient()
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: () => api<{ data: PharmacySettings }>('/settings'),
  })

  const [form, setForm] = useState({
    invoice_prefix: '',
    po_prefix: '',
    receipt_prefix: '',
    opname_prefix: '',
    expiry_warning_days: '',
  })
  const [initialized, setInitialized] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  if (data && !initialized) {
    const settings = data.data
    setForm({
      invoice_prefix: settings.invoice_prefix,
      po_prefix: settings.po_prefix,
      receipt_prefix: settings.receipt_prefix,
      opname_prefix: settings.opname_prefix,
      expiry_warning_days: String(settings.expiry_warning_days),
    })
    setInitialized(true)
  }

  const dirty =
    initialized &&
    data !== undefined &&
    (form.invoice_prefix !== data.data.invoice_prefix ||
      form.po_prefix !== data.data.po_prefix ||
      form.receipt_prefix !== data.data.receipt_prefix ||
      form.opname_prefix !== data.data.opname_prefix ||
      Number(form.expiry_warning_days) !== data.data.expiry_warning_days)

  const showSaved = saved && !dirty

  function setField(field: keyof typeof form, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }))
    setSaved(false)
  }

  function reset() {
    if (!data) return
    const settings = data.data
    setForm({
      invoice_prefix: settings.invoice_prefix,
      po_prefix: settings.po_prefix,
      receipt_prefix: settings.receipt_prefix,
      opname_prefix: settings.opname_prefix,
      expiry_warning_days: String(settings.expiry_warning_days),
    })
    setError(null)
  }

  const expiryInvalid =
    form.expiry_warning_days !== '' &&
    (Number(form.expiry_warning_days) < 1 || Number(form.expiry_warning_days) > 90)
  const prefixInvalid = (value: string) => value === '' || value.length > 10

  const canSave =
    dirty &&
    !expiryInvalid &&
    !prefixInvalid(form.invoice_prefix) &&
    !prefixInvalid(form.po_prefix) &&
    !prefixInvalid(form.receipt_prefix) &&
    !prefixInvalid(form.opname_prefix) &&
    !saving

  async function handleSave() {
    if (!data) return
    setSaving(true)
    setError(null)
    try {
      await api('/settings', {
        method: 'PUT',
        body: {
          // PUT settings bersifat utuh — sertakan juga field profil dari data terakhir.
          ...data.data,
          invoice_prefix: form.invoice_prefix.trim(),
          po_prefix: form.po_prefix.trim(),
          receipt_prefix: form.receipt_prefix.trim(),
          opname_prefix: form.opname_prefix.trim(),
          expiry_warning_days: Number(form.expiry_warning_days),
        },
      })
      queryClient.invalidateQueries({ queryKey: ['settings'] })
      setSaved(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Gagal menyimpan pengaturan. Coba lagi.')
    } finally {
      setSaving(false)
    }
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-card border border-line bg-surface px-6 py-16 text-center">
        <p className="text-sm text-danger-ink">Gagal memuat pengaturan.</p>
        <button
          type="button"
          onClick={() => refetch()}
          className="text-[13px] font-bold text-primary hover:underline"
        >
          Coba lagi
        </button>
      </div>
    )
  }

  return (
    <div className="rounded-card border border-line bg-surface p-6">
      <h3 className="text-[15px] font-bold text-ink">Pengaturan Transaksi</h3>
      <p className="mt-0.5 text-xs text-ink-secondary">
        Prefix nomor dokumen dan ambang peringatan kedaluwarsa yang dipakai sistem.
      </p>

      {isPending ? (
        <div className="mt-4 grid grid-cols-2 gap-4">
          {Array.from({ length: 5 }, (_, i) => (
            <div key={i} className="h-10 animate-pulse rounded-lg bg-neutral-bg" />
          ))}
        </div>
      ) : (
        <div className="mt-4 grid grid-cols-2 gap-4">
          <Field label="Prefix nomor struk *">
            <Input
              value={form.invoice_prefix}
              onChange={(e) => setField('invoice_prefix', e.target.value)}
              invalid={prefixInvalid(form.invoice_prefix)}
              placeholder="TRX"
            />
          </Field>
          <Field label="Prefix purchase order *">
            <Input
              value={form.po_prefix}
              onChange={(e) => setField('po_prefix', e.target.value)}
              invalid={prefixInvalid(form.po_prefix)}
              placeholder="PO"
            />
          </Field>
          <Field label="Prefix penerimaan barang *">
            <Input
              value={form.receipt_prefix}
              onChange={(e) => setField('receipt_prefix', e.target.value)}
              invalid={prefixInvalid(form.receipt_prefix)}
              placeholder="RCV"
            />
          </Field>
          <Field label="Prefix stock opname *">
            <Input
              value={form.opname_prefix}
              onChange={(e) => setField('opname_prefix', e.target.value)}
              invalid={prefixInvalid(form.opname_prefix)}
              placeholder="OPN"
            />
          </Field>
          <div className="col-span-2">
            <Field
              label="Ambang peringatan kedaluwarsa (hari) *"
              error={expiryInvalid ? 'Nilai harus antara 1 dan 90 hari.' : undefined}
            >
              <Input
                type="number"
                min={1}
                max={90}
                value={form.expiry_warning_days}
                onChange={(e) => setField('expiry_warning_days', e.target.value)}
                invalid={expiryInvalid}
                className="w-40"
              />
            </Field>
            <p className="mt-1.5 flex items-center gap-2 text-[11px] text-ink-secondary">
              <Badge variant="info">Info</Badge>
              Batch dengan kedaluwarsa dalam ambang ini muncul di dashboard dan laporan
              kedaluwarsa.
            </p>
          </div>
        </div>
      )}

      {error && (
        <p role="alert" className="mt-3 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
          {error}
        </p>
      )}

      <div className="mt-5 flex items-center justify-between border-t border-line pt-4">
        <p className="text-xs text-ink-secondary">
          {showSaved
            ? 'Perubahan tersimpan.'
            : dirty
              ? 'Perubahan belum disimpan.'
              : 'Tidak ada perubahan.'}
        </p>
        <div className="flex gap-2">
          <Button variant="secondary" disabled={!dirty || saving} onClick={reset}>
            Batal
          </Button>
          <Button loading={saving} disabled={!canSave} onClick={handleSave}>
            Simpan pengaturan
          </Button>
        </div>
      </div>
    </div>
  )
}
