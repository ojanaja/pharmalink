import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Power, Search, Upload } from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Input, Select, Field } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { Pagination } from '../../components/ui/Pagination'
import { SortHeader } from '../../components/ui/SortHeader'
import { ApiError, api } from '../../lib/api'
import { formatRupiah } from '../../lib/format'
import { sortRows, useSort } from '../../lib/sort'
import { useDebounce } from '../../lib/useDebounce'
import type {
  Category,
  LaravelPaginated,
  Manufacturer,
  Medicine,
  Supplier,
  Unit,
} from '../../lib/types'
import { ImportMedicinesModal } from '../MedicinesImportModal'
import { ExportButton } from '../reports/ExportButton'
import { MedicineFormModal } from './MedicineFormModal'
import { SupplierFormModal } from './SupplierFormModal'

const TABS = [
  { key: 'obat', label: 'Obat' },
  { key: 'pabrikan', label: 'Pabrikan' },
  { key: 'supplier', label: 'Supplier' },
] as const

type TabKey = (typeof TABS)[number]['key']

export function MasterDataPage() {
  const { user } = useAuth()
  // MedicinePolicy & ManufacturerPolicy backend: create/update hanya owner; apoteker hanya melihat.
  const isOwner = user?.role === 'owner'
  const [tab, setTab] = useState<TabKey>('obat')

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="text-2xl font-bold text-ink">Master Data</h2>
        <p className="mt-1 text-sm text-ink-secondary">
          Kelola data dasar obat, pabrikan, kategori, satuan, dan supplier apotek.
        </p>
      </div>

      <div className="flex gap-2" role="tablist" aria-label="Kategori master data">
        {TABS.map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={tab === item.key}
            onClick={() => setTab(item.key)}
            className={`rounded-full border px-4 py-2 text-[13px] font-bold transition-colors ${
              tab === item.key
                ? 'border-primary bg-primary text-white'
                : 'border-line bg-surface text-ink-secondary hover:border-primary hover:text-primary'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'obat' ? (
        <MedicinesTab isOwner={isOwner} />
      ) : tab === 'pabrikan' ? (
        <ManufacturersTab isOwner={isOwner} />
      ) : (
        <SuppliersTab isOwner={isOwner} />
      )}
    </div>
  )
}

/* ---------------------------------- Obat ---------------------------------- */

function MedicinesTab({ isOwner }: { isOwner: boolean }) {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [formOpen, setFormOpen] = useState(false)
  const [importOpen, setImportOpen] = useState(false)
  const [editing, setEditing] = useState<Medicine | null>(null)
  const [toggleTarget, setToggleTarget] = useState<Medicine | null>(null)
  const [manufacturerId, setManufacturerId] = useState('')
  const { sort, toggleSort } = useSort()

  // Master data perlu melihat obat nonaktif — index default hanya aktif.
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['medicines', page, manufacturerId],
    queryFn: () => {
      const params = new URLSearchParams({ page: String(page), include_inactive: '1' })
      if (manufacturerId) params.set('manufacturer_id', manufacturerId)
      return api<LaravelPaginated<Medicine>>(`/medicines?${params}`)
    },
    placeholderData: keepPreviousData,
  })

  const { data: manufacturers } = useQuery({
    queryKey: ['manufacturers', 'filter'],
    queryFn: () => api<{ data: Manufacturer[] }>('/manufacturers?per_page=100'),
  })

  const medicineValue = (medicine: Medicine, key: string): string | number | null => {
    switch (key) {
      case 'code':
        return medicine.code
      case 'name':
        return medicine.name
      case 'manufacturer':
        return medicine.manufacturer?.name ?? null
      case 'price':
        return Number(medicine.sale_price)
      case 'min':
        return medicine.min_stock
      default:
        return null
    }
  }

  const visible = sortRows(data?.data ?? [], sort, medicineValue)

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['medicines'] })
  }

  return (
    <>
      <div className="flex items-center justify-between gap-4">
        <div className="w-64">
          <Select
            value={manufacturerId}
            onChange={(event) => {
              setManufacturerId(event.target.value)
              setPage(1)
            }}
            aria-label="Filter pabrikan"
          >
            <option value="">Semua pabrikan</option>
            {(manufacturers?.data ?? [])
              .filter((manufacturer) => manufacturer.is_active)
              .map((manufacturer) => (
                <option key={manufacturer.id} value={manufacturer.id}>
                  {manufacturer.name}
                </option>
              ))}
          </Select>
        </div>
        {isOwner && (
          <div className="flex gap-2">
            <ExportButton url="/api/medicines/export" label="Export" />
            <Button variant="secondary" size="sm" icon={<Upload size={14} />} onClick={() => setImportOpen(true)}>
              Impor
            </Button>
            <Button
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => {
                setEditing(null)
                setFormOpen(true)
              }}
            >
              Tambah obat
            </Button>
          </div>
        )}
      </div>

      <CategoryUnitManager isOwner={isOwner} />

      <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
        <div className="flex items-center justify-between border-b border-line px-5 py-4">
          <div>
            <h3 className="text-[15px] font-bold text-ink">Daftar Obat</h3>
            <p className="mt-0.5 text-xs text-ink-secondary">
              {data?.meta.total ?? 0} obat terdaftar (termasuk nonaktif).
            </p>
          </div>
        </div>

        {isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat data obat.</p>
            <button
              type="button"
              onClick={() => refetch()}
              className="text-[13px] font-bold text-primary hover:underline"
            >
              Coba lagi
            </button>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <SortHeader label="Kode" sortKey="code" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Nama Obat" sortKey="name" sort={sort} onToggle={toggleSort} />
                <th className="px-4 py-3">Kategori</th>
                <th className="px-4 py-3">Satuan</th>
                <SortHeader label="Pabrikan" sortKey="manufacturer" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Harga Jual" sortKey="price" sort={sort} onToggle={toggleSort} align="right" />
                <SortHeader label="Min. Stok" sortKey="min" sort={sort} onToggle={toggleSort} align="right" />
                <th className="px-4 py-3">Status</th>
                {isOwner && <th className="px-4 py-3" aria-label="Aksi" />}
              </tr>
            </thead>
            <tbody>
              {isPending &&
                Array.from({ length: 5 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 9 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {visible.map((medicine) => (
                <tr key={medicine.id} className="border-t border-line">
                  <td className="px-5 py-3 font-medium text-ink-secondary">{medicine.code}</td>
                  <td className="px-4 py-3 font-semibold text-ink">{medicine.name}</td>
                  <td className="px-4 py-3 text-ink-secondary">{medicine.category?.name ?? '—'}</td>
                  <td className="px-4 py-3 text-ink-secondary">{medicine.unit?.name ?? '—'}</td>
                  <td className="px-4 py-3 text-ink-secondary">{medicine.manufacturer?.name ?? '—'}</td>
                  <td className="px-4 py-3 text-right text-ink-secondary">
                    {formatRupiah(medicine.sale_price)}
                  </td>
                  <td className="px-4 py-3 text-right text-ink-secondary">
                    {new Intl.NumberFormat('id-ID').format(medicine.min_stock)}
                  </td>
                  <td className="px-4 py-3">
                    <Badge variant={medicine.is_active ? 'success' : 'danger'}>
                      {medicine.is_active ? 'Aktif' : 'Nonaktif'}
                    </Badge>
                  </td>
                  {isOwner && (
                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-2">
                        <button
                          type="button"
                          aria-label={`Edit ${medicine.name}`}
                          onClick={() => {
                            setEditing(medicine)
                            setFormOpen(true)
                          }}
                          className="text-ink-secondary transition-colors hover:text-primary"
                        >
                          <Pencil size={15} />
                        </button>
                        <button
                          type="button"
                          aria-label={`${medicine.is_active ? 'Nonaktifkan' : 'Aktifkan'} ${medicine.name}`}
                          onClick={() => setToggleTarget(medicine)}
                          className={`transition-colors ${
                            medicine.is_active
                              ? 'text-ink-secondary hover:text-danger-ink'
                              : 'text-ink-secondary hover:text-success-ink'
                          }`}
                        >
                          <Power size={15} />
                        </button>
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {data && (
          <Pagination
            currentPage={data.meta.current_page}
            lastPage={data.meta.last_page}
            total={data.meta.total}
            from={data.meta.from ?? 0}
            to={data.meta.to ?? 0}
            onPageChange={setPage}
          />
        )}
      </div>

      <MedicineFormModal
        open={formOpen}
        medicine={editing}
        onClose={() => setFormOpen(false)}
        onSaved={() => {
          setFormOpen(false)
          invalidate()
        }}
      />

      <ToggleMedicineModal
        target={toggleTarget}
        onClose={() => setToggleTarget(null)}
        onSaved={() => {
          setToggleTarget(null)
          invalidate()
        }}
      />

      <ImportMedicinesModal
        open={importOpen}
        onClose={() => setImportOpen(false)}
        onImported={() => queryClient.invalidateQueries({ queryKey: ['medicines'] })}
      />
    </>
  )
}

/** Obat nonaktif tidak dijual/diterima (index default hanya aktif) tapi tetap tersimpan di master. */
function ToggleMedicineModal({
  target,
  onClose,
  onSaved,
}: {
  target: Medicine | null
  onClose: () => void
  onSaved: () => void
}) {
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const deactivating = target?.is_active ?? false

  async function handleToggle() {
    if (!target) return
    setSubmitting(true)
    setError(null)
    try {
      await api(`/medicines/${target.id}/toggle-active`, { method: 'POST', body: {} })
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Gagal mengubah status obat.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={target !== null}
      title={deactivating ? `Nonaktifkan obat ${target?.name ?? ''}?` : `Aktifkan obat ${target?.name ?? ''}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant={deactivating ? 'danger' : 'primary'}
            loading={submitting}
            onClick={handleToggle}
          >
            {deactivating ? 'Ya, nonaktifkan' : 'Ya, aktifkan'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p>
          {deactivating
            ? 'Obat tidak akan muncul di kasir dan tidak dapat diterima stoknya, riwayat transaksi tetap tersimpan.'
            : 'Obat dapat kembali dijual dan diterima stoknya.'}
        </p>
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}

/** Kelola kategori & satuan: daftar + tambah sederhana (owner saja, selaras MedicinePolicy). */
function CategoryUnitManager({ isOwner }: { isOwner: boolean }) {
  const queryClient = useQueryClient()
  const { data: categories } = useQuery({
    queryKey: ['categories'],
    queryFn: () => api<{ data: Category[] }>('/categories'),
  })
  const { data: units } = useQuery({
    queryKey: ['units'],
    queryFn: () => api<{ data: Unit[] }>('/units'),
  })
  const [newCategory, setNewCategory] = useState('')
  const [newUnit, setNewUnit] = useState('')
  const [addError, setAddError] = useState<string | null>(null)

  async function add(kind: 'categories' | 'units', name: string, clear: () => void) {
    setAddError(null)
    try {
      await api(`/${kind}`, { method: 'POST', body: { name: name.trim() } })
      clear()
      queryClient.invalidateQueries({ queryKey: [kind] })
    } catch (err) {
      setAddError(err instanceof ApiError ? err.message : 'Gagal menambah data.')
    }
  }

  return (
    <div className="grid w-full grid-cols-2 gap-4">
      {(
        [
          {
            kind: 'categories' as const,
            title: 'Kategori',
            items: categories?.data ?? [],
            draft: newCategory,
            setDraft: setNewCategory,
          },
          {
            kind: 'units' as const,
            title: 'Satuan',
            items: units?.data ?? [],
            draft: newUnit,
            setDraft: setNewUnit,
          },
        ]
      ).map(({ kind, title, items, draft, setDraft }) => (
        <div key={kind} className="rounded-card border border-line bg-surface p-4">
          <p className="text-[13px] font-bold text-ink">{title}</p>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {items.map((item) => (
              <Badge key={item.id} variant="neutral">
                {item.name}
              </Badge>
            ))}
          </div>
          {isOwner && (
            <div className="mt-3 flex gap-2">
              <Input
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                placeholder={`Nama ${title.toLowerCase()} baru`}
                className="h-8"
              />
              <Button
                size="sm"
                variant="secondary"
                disabled={draft.trim() === ''}
                onClick={() => add(kind, draft, () => setDraft(''))}
              >
                Tambah
              </Button>
            </div>
          )}
        </div>
      ))}
      {addError && <p className="col-span-2 text-xs text-danger-ink">{addError}</p>}
    </div>
  )
}

/* -------------------------------- Pabrikan -------------------------------- */

function ManufacturersTab({ isOwner }: { isOwner: boolean }) {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const debouncedSearch = useDebounce(search)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Manufacturer | null>(null)
  const [toggleTarget, setToggleTarget] = useState<Manufacturer | null>(null)
  const { sort, toggleSort } = useSort()

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['manufacturers', page, debouncedSearch],
    queryFn: () =>
      api<LaravelPaginated<Manufacturer>>(
        `/manufacturers?page=${page}&search=${encodeURIComponent(debouncedSearch)}`,
      ),
    placeholderData: keepPreviousData,
  })

  const visible = sortRows(data?.data ?? [], sort, (manufacturer, key) => {
    switch (key) {
      case 'code':
        return manufacturer.code
      case 'name':
        return manufacturer.name
      default:
        return null
    }
  })

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['manufacturers'] })
  }

  return (
    <>
      <div className="flex items-center justify-between gap-4">
        <div className="relative w-72">
          <Search
            size={16}
            className="absolute left-3 top-1/2 -translate-y-1/2 text-placeholder"
            aria-hidden="true"
          />
          <Input
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder="Cari kode atau nama pabrikan…"
            className="pl-9"
            aria-label="Cari pabrikan"
          />
        </div>
        {isOwner && (
          <Button
            size="sm"
            icon={<Plus size={14} />}
            onClick={() => {
              setEditing(null)
              setFormOpen(true)
            }}
          >
            Tambah pabrikan
          </Button>
        )}
      </div>

      <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
        {isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat data pabrikan.</p>
            <button
              type="button"
              onClick={() => refetch()}
              className="text-[13px] font-bold text-primary hover:underline"
            >
              Coba lagi
            </button>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <SortHeader label="Kode" sortKey="code" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Nama" sortKey="name" sort={sort} onToggle={toggleSort} />
                <th className="px-4 py-3">Alamat</th>
                <th className="px-4 py-3">Kontak</th>
                <th className="px-4 py-3 text-right">Obat</th>
                <th className="px-4 py-3">Status</th>
                {isOwner && <th className="px-4 py-3" aria-label="Aksi" />}
              </tr>
            </thead>
            <tbody>
              {isPending &&
                Array.from({ length: 4 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 7 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {data && visible.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-6 py-16 text-center text-sm text-ink-secondary">
                    Belum ada pabrikan terdaftar.
                  </td>
                </tr>
              )}

              {visible.map((manufacturer) => (
                <tr key={manufacturer.id} className="border-t border-line">
                  <td className="px-5 py-3 font-medium text-ink-secondary">{manufacturer.code}</td>
                  <td className="px-4 py-3 font-semibold text-ink">{manufacturer.name}</td>
                  <td className="px-4 py-3 text-ink-secondary">{manufacturer.address ?? '—'}</td>
                  <td className="px-4 py-3 text-ink-secondary">{manufacturer.contact ?? '—'}</td>
                  <td className="px-4 py-3 text-right text-ink-secondary">
                    {manufacturer.medicines_count}
                  </td>
                  <td className="px-4 py-3">
                    <Badge variant={manufacturer.is_active ? 'success' : 'danger'}>
                      {manufacturer.is_active ? 'Aktif' : 'Nonaktif'}
                    </Badge>
                  </td>
                  {isOwner && (
                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-2">
                        <button
                          type="button"
                          aria-label={`Edit ${manufacturer.name}`}
                          onClick={() => {
                            setEditing(manufacturer)
                            setFormOpen(true)
                          }}
                          className="text-ink-secondary transition-colors hover:text-primary"
                        >
                          <Pencil size={15} />
                        </button>
                        <button
                          type="button"
                          aria-label={`${manufacturer.is_active ? 'Nonaktifkan' : 'Aktifkan'} ${manufacturer.name}`}
                          onClick={() => setToggleTarget(manufacturer)}
                          className={`transition-colors ${
                            manufacturer.is_active
                              ? 'text-ink-secondary hover:text-danger-ink'
                              : 'text-ink-secondary hover:text-success-ink'
                          }`}
                        >
                          <Power size={15} />
                        </button>
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {data && (
          <Pagination
            currentPage={data.meta.current_page}
            lastPage={data.meta.last_page}
            total={data.meta.total}
            from={data.meta.from ?? 0}
            to={data.meta.to ?? 0}
            onPageChange={setPage}
          />
        )}
      </div>

      <ManufacturerFormModal
        open={formOpen}
        manufacturer={editing}
        onClose={() => setFormOpen(false)}
        onSaved={() => {
          setFormOpen(false)
          invalidate()
        }}
      />

      <ToggleManufacturerModal
        target={toggleTarget}
        onClose={() => setToggleTarget(null)}
        onSaved={() => {
          setToggleTarget(null)
          invalidate()
        }}
      />
    </>
  )
}

function ManufacturerFormModal({
  open,
  manufacturer,
  onClose,
  onSaved,
}: {
  open: boolean
  manufacturer: Manufacturer | null
  onClose: () => void
  onSaved: () => void
}) {
  const isEdit = manufacturer !== null
  const [form, setForm] = useState({ code: '', name: '', address: '', contact: '' })
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (open && !initialized) {
    setForm({
      code: manufacturer?.code ?? '',
      name: manufacturer?.name ?? '',
      address: manufacturer?.address ?? '',
      contact: manufacturer?.contact ?? '',
    })
    setInitialized(true)
    setError(null)
  }
  if (!open && initialized) {
    setInitialized(false)
  }

  async function handleSubmit() {
    setSubmitting(true)
    setError(null)
    const body = {
      code: form.code.trim(),
      name: form.name.trim(),
      address: form.address.trim() === '' ? null : form.address.trim(),
      contact: form.contact.trim() === '' ? null : form.contact.trim(),
    }
    try {
      if (isEdit) {
        await api(`/manufacturers/${manufacturer.id}`, { method: 'PUT', body })
      } else {
        await api('/manufacturers', { method: 'POST', body })
      }
      setInitialized(false)
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Gagal menyimpan pabrikan. Coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  const canSubmit = form.code.trim() !== '' && form.name.trim() !== '' && !submitting

  return (
    <Modal
      open={open}
      title={isEdit ? `Edit Pabrikan — ${manufacturer.name}` : 'Tambah Pabrikan'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            {isEdit ? 'Simpan perubahan' : 'Tambah pabrikan'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-4">
          <Field label="Kode *">
            <Input
              value={form.code}
              onChange={(e) => setForm({ ...form, code: e.target.value })}
              placeholder="MNF-KBF"
            />
          </Field>
          <Field label="Nama *">
            <Input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="Kalbe Farma"
            />
          </Field>
        </div>
        <Field label="Kontak">
          <Input
            value={form.contact}
            onChange={(e) => setForm({ ...form, contact: e.target.value })}
            placeholder="021-…"
          />
        </Field>
        <Field label="Alamat">
          <Input
            value={form.address}
            onChange={(e) => setForm({ ...form, address: e.target.value })}
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

function ToggleManufacturerModal({
  target,
  onClose,
  onSaved,
}: {
  target: Manufacturer | null
  onClose: () => void
  onSaved: () => void
}) {
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const deactivating = target?.is_active ?? false

  async function handleToggle() {
    if (!target) return
    setSubmitting(true)
    setError(null)
    try {
      await api(`/manufacturers/${target.id}/toggle-active`, { method: 'POST', body: {} })
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Gagal mengubah status pabrikan.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={target !== null}
      title={deactivating ? `Nonaktifkan pabrikan ${target?.name ?? ''}?` : `Aktifkan pabrikan ${target?.name ?? ''}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant={deactivating ? 'danger' : 'primary'}
            loading={submitting}
            onClick={handleToggle}
          >
            {deactivating ? 'Ya, nonaktifkan' : 'Ya, aktifkan'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p>
          {deactivating
            ? 'Pabrikan tidak dapat dipilih untuk obat baru; data obat yang sudah terkait tetap tersimpan.'
            : 'Pabrikan dapat dipilih kembali untuk obat baru.'}
        </p>
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}

/* --------------------------------- Supplier --------------------------------- */

function SuppliersTab({ isOwner }: { isOwner: boolean }) {
  const queryClient = useQueryClient()
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Supplier | null>(null)
  const { sort, toggleSort } = useSort()

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['suppliers'],
    queryFn: () => api<LaravelPaginated<Supplier>>('/suppliers?per_page=100'),
  })

  // Pivot obat+harga hanya ada di endpoint show — muat saat baris dipilih.
  const detailQuery = useQuery({
    queryKey: ['supplier', selectedId],
    queryFn: () => api<{ data: Supplier }>(`/suppliers/${selectedId}`),
    enabled: selectedId !== null,
  })

  const selected = data?.data.find((supplier) => supplier.id === selectedId) ?? null
  const selectedDetail = detailQuery.data?.data ?? null
  const visibleSuppliers = sortRows(data?.data ?? [], sort, (supplier, key) => {
    switch (key) {
      case 'name':
        return supplier.name
      case 'kontak':
        return supplier.contact_person
      default:
        return null
    }
  })

  return (
    <div className="flex items-start gap-6">
      <div className="min-w-0 flex-1 overflow-hidden rounded-card border border-line bg-surface shadow-low">
        <div className="flex items-center justify-between border-b border-line px-5 py-4">
          <h3 className="text-[15px] font-bold text-ink">Daftar Supplier</h3>
          {isOwner && (
            <Button
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => {
                setEditing(null)
                setFormOpen(true)
              }}
            >
              Tambah supplier
            </Button>
          )}
        </div>

        {isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat data supplier.</p>
            <button
              type="button"
              onClick={() => refetch()}
              className="text-[13px] font-bold text-primary hover:underline"
            >
              Coba lagi
            </button>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <SortHeader label="Nama" sortKey="name" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Kontak" sortKey="kontak" sort={sort} onToggle={toggleSort} />
                <th className="px-4 py-3">Telepon</th>
              </tr>
            </thead>
            <tbody>
              {isPending &&
                Array.from({ length: 4 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 3 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {data && visibleSuppliers.length === 0 && (
                <tr>
                  <td colSpan={3} className="px-6 py-16 text-center text-sm text-ink-secondary">
                    Belum ada supplier terdaftar.
                  </td>
                </tr>
              )}

              {visibleSuppliers.map((supplier) => (
                <tr
                  key={supplier.id}
                  onClick={() => setSelectedId(supplier.id)}
                  className={`cursor-pointer border-t border-line transition-colors ${
                    selectedId === supplier.id ? 'bg-primary-soft' : 'hover:bg-table-header'
                  }`}
                >
                  <td className="px-5 py-3 font-semibold text-ink">{supplier.name}</td>
                  <td className="px-4 py-3 text-ink-secondary">{supplier.contact_person ?? '—'}</td>
                  <td className="px-4 py-3 text-ink-secondary">{supplier.phone ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Panel detail kanan — pola Figma #42:871 */}
      <div className="w-[340px] shrink-0 rounded-card border border-line bg-surface p-5">
        {selected ? (
          <div className="flex flex-col gap-4">
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="text-[15px] font-bold text-ink">{selected.name}</p>
                <p className="mt-0.5 text-xs text-ink-secondary">Supplier terdaftar</p>
              </div>
              {isOwner && (
                <Button
                  size="sm"
                  variant="secondary"
                  icon={<Pencil size={13} />}
                  onClick={() => {
                    setEditing(selected)
                    setFormOpen(true)
                  }}
                >
                  Edit
                </Button>
              )}
            </div>
            <dl className="flex flex-col gap-2.5">
              {(
                [
                  ['Kontak person', selected.contact_person],
                  ['Telepon', selected.phone],
                  ['Alamat', selected.address],
                ] as const
              ).map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs text-ink-secondary">{label}</dt>
                  <dd className="mt-0.5 text-[13px] font-semibold text-ink">{value ?? '—'}</dd>
                </div>
              ))}
            </dl>

            <div className="border-t border-line pt-3">
              <p className="text-xs font-bold uppercase tracking-wide text-ink-secondary">
                Obat & harga beli
              </p>
              {detailQuery.isPending ? (
                <div className="mt-2 h-16 animate-pulse rounded bg-neutral-bg" />
              ) : selectedDetail?.medicines && selectedDetail.medicines.length > 0 ? (
                <table className="mt-2 w-full text-left text-sm">
                  <thead>
                    <tr className="text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                      <th className="py-1 pr-2">Kode</th>
                      <th className="py-1 pr-2">Nama Obat</th>
                      <th className="py-1 text-right">Harga Beli</th>
                    </tr>
                  </thead>
                  <tbody>
                    {selectedDetail.medicines.map((item) => (
                      <tr key={item.medicine_id} className="border-t border-line">
                        <td className="py-1.5 pr-2 text-[11px] text-ink-secondary">{item.code}</td>
                        <td className="py-1.5 pr-2 text-[12px] font-semibold text-ink">
                          {item.name}
                        </td>
                        <td className="py-1.5 text-right text-[12px] text-ink">
                          {formatRupiah(item.purchase_price)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <p className="mt-2 rounded-lg bg-table-header px-3 py-2 text-[11px] text-ink-secondary">
                  Supplier ini belum memiliki harga obat terdaftar.
                </p>
              )}
            </div>
          </div>
        ) : (
          <p className="py-8 text-center text-[13px] text-ink-secondary">
            Pilih supplier untuk melihat detail.
          </p>
        )}
      </div>

      <SupplierFormModal
        open={formOpen}
        supplier={editing}
        onClose={() => setFormOpen(false)}
        onSaved={() => {
          setFormOpen(false)
          queryClient.invalidateQueries({ queryKey: ['suppliers'] })
        }}
      />
    </div>
  )
}
