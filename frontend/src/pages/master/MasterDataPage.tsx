import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { Pagination } from '../../components/ui/Pagination'
import { SortHeader } from '../../components/ui/SortHeader'
import { ApiError, api } from '../../lib/api'
import { formatRupiah } from '../../lib/format'
import { sortRows, useSort } from '../../lib/sort'
import type { Category, LaravelPaginated, Medicine, Supplier, Unit } from '../../lib/types'
import { MedicineFormModal } from './MedicineFormModal'
import { SupplierFormModal } from './SupplierFormModal'

const TABS = [
  { key: 'obat', label: 'Obat' },
  { key: 'supplier', label: 'Supplier' },
] as const

type TabKey = (typeof TABS)[number]['key']

export function MasterDataPage() {
  const { user } = useAuth()
  // MedicinePolicy backend: create/update/delete hanya owner; apoteker hanya melihat.
  const isOwner = user?.role === 'owner'
  const [tab, setTab] = useState<TabKey>('obat')

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="text-2xl font-bold text-ink">Master Data</h2>
        <p className="mt-1 text-sm text-ink-secondary">
          Kelola data dasar obat, kategori, satuan, dan supplier apotek.
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

      {tab === 'obat' ? <MedicinesTab isOwner={isOwner} /> : <SuppliersTab isOwner={isOwner} />}
    </div>
  )
}

/* ---------------------------------- Obat ---------------------------------- */

function MedicinesTab({ isOwner }: { isOwner: boolean }) {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Medicine | null>(null)
  const [deleting, setDeleting] = useState<Medicine | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [deletingSubmit, setDeletingSubmit] = useState(false)
  const { sort, toggleSort } = useSort()

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['medicines', page],
    queryFn: () => api<LaravelPaginated<Medicine>>(`/medicines?page=${page}`),
  })

  async function handleDelete() {
    if (!deleting) return
    setDeletingSubmit(true)
    setDeleteError(null)
    try {
      await api(`/medicines/${deleting.id}`, { method: 'DELETE' })
      setDeleting(null)
      queryClient.invalidateQueries({ queryKey: ['medicines'] })
    } catch (err) {
      setDeleteError(err instanceof ApiError ? err.message : 'Gagal menghapus obat.')
    } finally {
      setDeletingSubmit(false)
    }
  }

  return (
    <>
      <div className="flex items-center justify-between gap-4">
        <CategoryUnitManager isOwner={isOwner} />
      </div>

      <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
        <div className="flex items-center justify-between border-b border-line px-5 py-4">
          <h3 className="text-[15px] font-bold text-ink">Daftar Obat</h3>
          {isOwner && (
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
          )}
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
                    {Array.from({ length: 8 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {sortRows(data?.data ?? [], sort, (medicine, key) => {
                switch (key) {
                  case 'code':
                    return medicine.code
                  case 'name':
                    return medicine.name
                  case 'price':
                    return Number(medicine.sale_price)
                  case 'min':
                    return medicine.min_stock
                  default:
                    return null
                }
              }).map((medicine) => {
                return (
                  <tr key={medicine.id} className="border-t border-line">
                    <td className="px-5 py-3 font-medium text-ink-secondary">{medicine.code}</td>
                    <td className="px-4 py-3 font-semibold text-ink">{medicine.name}</td>
                    <td className="px-4 py-3 text-ink-secondary">{medicine.category?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-ink-secondary">{medicine.unit?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-right text-ink-secondary">
                      {formatRupiah(medicine.sale_price)}
                    </td>
                    <td className="px-4 py-3 text-right text-ink-secondary">
                      {new Intl.NumberFormat('id-ID').format(medicine.min_stock)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={medicine.is_active ? 'success' : 'neutral'}>
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
                            aria-label={`Hapus ${medicine.name}`}
                            onClick={() => {
                              setDeleting(medicine)
                              setDeleteError(null)
                            }}
                            className="text-ink-secondary transition-colors hover:text-danger-ink"
                          >
                            <Trash2 size={15} />
                          </button>
                        </div>
                      </td>
                    )}
                  </tr>
                )
              })}
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
          queryClient.invalidateQueries({ queryKey: ['medicines'] })
        }}
      />

      <Modal
        open={deleting !== null}
        title="Hapus data obat?"
        onClose={() => setDeleting(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleting(null)}>
              Batal
            </Button>
            <Button variant="danger" loading={deletingSubmit} onClick={handleDelete}>
              Hapus
            </Button>
          </>
        }
      >
        <p>
          {deleting?.name} akan dihapus dari master data. Riwayat transaksi tetap tersimpan.
        </p>
        {deleteError && (
          <div role="alert" className="mt-3 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {deleteError}
          </div>
        )}
      </Modal>
    </>
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

  const selected = data?.data.find((supplier) => supplier.id === selectedId) ?? null
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
