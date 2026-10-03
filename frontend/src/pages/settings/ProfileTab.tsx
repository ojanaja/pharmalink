import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Field, Input } from '../../components/ui/Field'
import { ApiError, api } from '../../lib/api'
import type { PharmacySettings } from '../../lib/types'

const EMPTY_PROFILE = {
  name: '',
  license_number: '',
  pharmacist_name: '',
  address: '',
  phone: '',
}

/** Profil Apotek — pola Figma #45:7995, footer form sesuai DS 03. */
export function ProfileTab() {
  const queryClient = useQueryClient()
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: () => api<{ data: PharmacySettings }>('/settings'),
  })

  const [form, setForm] = useState(EMPTY_PROFILE)
  const [initialized, setInitialized] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  // Form diisi setelah data pertama kali tersedia; "initialized" mencegah reset saat refetch.
  if (data && !initialized) {
    const settings = data.data
    setForm({
      name: settings.name,
      license_number: settings.license_number ?? '',
      pharmacist_name: settings.pharmacist_name ?? '',
      address: settings.address ?? '',
      phone: settings.phone ?? '',
    })
    setInitialized(true)
  }

  const dirty =
    initialized &&
    data !== undefined &&
    (form.name !== data.data.name ||
      form.license_number !== (data.data.license_number ?? '') ||
      form.pharmacist_name !== (data.data.pharmacist_name ?? '') ||
      form.address !== (data.data.address ?? '') ||
      form.phone !== (data.data.phone ?? ''))

  // "saved" hanya tampil selama form kembali bersih; setField/reset me-resetnya.
  const showSaved = saved && !dirty

  function setField(field: keyof typeof form, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }))
    setSaved(false)
  }

  function reset() {
    if (!data) return
    const settings = data.data
    setForm({
      name: settings.name,
      license_number: settings.license_number ?? '',
      pharmacist_name: settings.pharmacist_name ?? '',
      address: settings.address ?? '',
      phone: settings.phone ?? '',
    })
    setError(null)
  }

  async function handleSave() {
    if (!data) return
    setSaving(true)
    setError(null)
    try {
      await api('/settings', {
        method: 'PUT',
        body: {
          // PUT settings bersifat utuh — sertakan juga field transaksi dari data terakhir.
          ...data.data,
          name: form.name.trim(),
          license_number: form.license_number.trim() === '' ? null : form.license_number.trim(),
          pharmacist_name:
            form.pharmacist_name.trim() === '' ? null : form.pharmacist_name.trim(),
          address: form.address.trim() === '' ? null : form.address.trim(),
          phone: form.phone.trim() === '' ? null : form.phone.trim(),
        },
      })
      queryClient.invalidateQueries({ queryKey: ['settings'] })
      setSaved(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Gagal menyimpan profil. Coba lagi.')
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
      <h3 className="text-[15px] font-bold text-ink">Profil Apotek</h3>
      <p className="mt-0.5 text-xs text-ink-secondary">
        Informasi resmi yang tampil pada struk, laporan, dan dokumen apotek.
      </p>

      {isPending ? (
        <div className="mt-4 grid grid-cols-2 gap-4">
          {Array.from({ length: 4 }, (_, i) => (
            <div key={i} className="h-10 animate-pulse rounded-lg bg-neutral-bg" />
          ))}
        </div>
      ) : (
        <div className="mt-4 grid grid-cols-2 gap-4">
          <Field label="Nama apotek *">
            <Input value={form.name} onChange={(e) => setField('name', e.target.value)} />
          </Field>
          <Field label="Nomor izin (SIA)">
            <Input
              value={form.license_number}
              onChange={(e) => setField('license_number', e.target.value)}
            />
          </Field>
          <Field label="Apoteker penanggung jawab">
            <Input
              value={form.pharmacist_name}
              onChange={(e) => setField('pharmacist_name', e.target.value)}
            />
          </Field>
          <Field label="Telepon">
            <Input value={form.phone} onChange={(e) => setField('phone', e.target.value)} />
          </Field>
          <div className="col-span-2">
            <Field label="Alamat">
              <Input value={form.address} onChange={(e) => setField('address', e.target.value)} />
            </Field>
          </div>
        </div>
      )}

      {error && (
        <p role="alert" className="mt-3 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
          {error}
        </p>
      )}

      {/* Aksi form — pola DS 03: status perubahan + Batal + Simpan */}
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
          <Button loading={saving} disabled={!dirty || form.name.trim() === ''} onClick={handleSave}>
            Simpan perubahan
          </Button>
        </div>
      </div>
    </div>
  )
}
