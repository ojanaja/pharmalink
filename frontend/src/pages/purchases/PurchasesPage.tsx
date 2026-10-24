import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { PackagePlus, Plus, Search, X } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { Pagination } from '../../components/ui/Pagination'
import { SortHeader } from '../../components/ui/SortHeader'
import { ApiError, api } from '../../lib/api'
import { formatDateId, formatRupiah } from '../../lib/format'
import { poStatusLabel } from '../../lib/medicine'
import { sortRows, useSort } from '../../lib/sort'
import { useDebounce } from '../../lib/useDebounce'
import type { LaravelPaginated, PurchaseOrderListItem, Supplier } from '../../lib/types'
import { ManualReceiptModal } from './ManualReceiptModal'
import { PurchaseOrderFormModal } from './PurchaseOrderFormModal'

const STATUS_OPTIONS = [
  { value: '', label: 'Semua status' },
  { value: 'ordered', label: 'Dipesan' },
  { value: 'partially_received', label: 'Sebagian Diterima' },
  { value: 'received', label: 'Diterima' },
]

export function PurchasesPage() {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [supplierId, setSupplierId] = useState('')
  const [search, setSearch] = useState('')
  const debouncedSearch = useDebounce(search)
  const [formOpen, setFormOpen] = useState(false)
  const [manualReceiptOpen, setManualReceiptOpen] = useState(false)
  const [cancelTarget, setCancelTarget] = useState<PurchaseOrderListItem | null>(null)
  const [cancelSuccess, setCancelSuccess] = useState<string | null>(null)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const queryClient = useQueryClient()
  const { sort, toggleSort } = useSort()

  const { data: suppliers } = useQuery({
    queryKey: ['suppliers'],
    queryFn: () => api<LaravelPaginated<Supplier>>('/suppliers?per_page=100'),
  })

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['purchase-orders', page, status, supplierId, debouncedSearch, from, to],
    queryFn: () => {
      const params = new URLSearchParams({ page: String(page) })
      if (status) params.set('status', status)
      if (supplierId) params.set('supplier_id', supplierId)
      if (debouncedSearch) params.set('search', debouncedSearch)
      if (from) params.set('from', from)
      if (to) params.set('to', to)
      return api<LaravelPaginated<PurchaseOrderListItem>>(`/purchase-orders?${params}`)
    },
    placeholderData: keepPreviousData,
  })

  // Filter status/supplier via API; sort hasil halaman client-side.
  const visible = sortRows(data?.data ?? [], sort, (po, key) => {
    switch (key) {
      case 'nomor':
        return po.po_number
      case 'supplier':
        return po.supplier?.name ?? null
      case 'tanggal':
        return po.ordered_at
      case 'status':
        return po.status
      case 'total':
        return Number(po.total)
      default:
        return null
    }
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-ink">Pembelian</h2>
          <p className="mt-1 text-sm text-ink-secondary">
            Kelola purchase order, pantau penerimaan, dan jaga alur stok tetap terlacak.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" icon={<PackagePlus size={15} />} onClick={() => setManualReceiptOpen(true)}>
            Penerimaan Barang
          </Button>
          <Button icon={<Plus size={15} />} onClick={() => setFormOpen(true)}>
            Buat pembelian
          </Button>
        </div>
      </div>

      {/* Alur persediaan — stepper 3 langkah per Figma #50:823 */}
      <div className="flex items-center gap-3 rounded-card border border-line bg-surface px-5 py-4">
        {['Pembelian — buat & setujui PO', 'Penerimaan — catat batch & expired', 'Stok — saldo otomatis bertambah'].map(
          (step, index) => (
            <div key={step} className="flex flex-1 items-center gap-3">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">
                {index + 1}
              </span>
              <span className="text-xs font-semibold text-ink">{step}</span>
              {index < 2 && <span className="h-px flex-1 bg-line" aria-hidden="true" />}
            </div>
          ),
        )}
      </div>

      <div className="flex items-end gap-3">
        <div className="flex w-56 flex-col gap-1">
          <label htmlFor="filter-status" className="text-[11px] text-ink-secondary">
            Status
          </label>
          <Select
            id="filter-status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
          >
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </div>
        <div className="flex w-56 flex-col gap-1">
          <label htmlFor="filter-search" className="text-[11px] text-ink-secondary">
            Cari nomor PO
          </label>
          <div className="relative">
            <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-placeholder" aria-hidden="true" />
            <Input
              id="filter-search"
              value={search}
              onChange={(event) => {
                setSearch(event.target.value)
                setPage(1)
              }}
              placeholder="PO-20261018-…"
              className="pl-8"
            />
          </div>
        </div>
        <div className="flex w-64 flex-col gap-1">
          <label htmlFor="filter-supplier" className="text-[11px] text-ink-secondary">
            Supplier
          </label>
          <Select
            id="filter-supplier"
            value={supplierId}
            onChange={(event) => {
              setSupplierId(event.target.value)
              setPage(1)
            }}
          >
            <option value="">Semua supplier</option>
            {suppliers?.data.map((supplier) => (
              <option key={supplier.id} value={supplier.id}>
                {supplier.name}
              </option>
            ))}
          </Select>
        </div>
        <div className="flex w-40 flex-col gap-1">
          <label htmlFor="filter-from" className="text-[11px] text-ink-secondary">
            Dari
          </label>
          <Input
            id="filter-from"
            type="date"
            value={from}
            onChange={(event) => {
              setFrom(event.target.value)
              setPage(1)
            }}
          />
        </div>
        <div className="flex w-40 flex-col gap-1">
          <label htmlFor="filter-to" className="text-[11px] text-ink-secondary">
            Sampai
          </label>
          <Input
            id="filter-to"
            type="date"
            value={to}
            min={from || undefined}
            onChange={(event) => {
              setTo(event.target.value)
              setPage(1)
            }}
          />
        </div>
      </div>

      {cancelSuccess && (
        <p className="rounded-lg bg-success-bg px-4 py-2.5 text-[13px] text-success-ink">
          PO {cancelSuccess} dibatalkan.
        </p>
      )}

      <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
        <div className="border-b border-line px-5 py-4">
          <h3 className="text-[15px] font-bold text-ink">Daftar Purchase Order</h3>
          <p className="mt-0.5 text-xs text-ink-secondary">
            {data?.meta.total ?? 0} pembelian tercatat.
          </p>
        </div>

        {isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat daftar pembelian.</p>
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
                <SortHeader label="Nomor" sortKey="nomor" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Tanggal" sortKey="tanggal" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Supplier" sortKey="supplier" sort={sort} onToggle={toggleSort} />
                <th className="px-4 py-3 text-right">Item</th>
                <SortHeader label="Total" sortKey="total" sort={sort} onToggle={toggleSort} align="right" />
                <SortHeader label="Status" sortKey="status" sort={sort} onToggle={toggleSort} />
                <th className="px-4 py-3" aria-label="Aksi" />
              </tr>
            </thead>
            <tbody>
              {isPending &&
                Array.from({ length: 5 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 7 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-6 py-16 text-center text-sm text-ink-secondary">
                    Belum ada purchase order.
                  </td>
                </tr>
              )}

              {visible.map((po) => {
                const statusInfo = poStatusLabel(po.status)
                return (
                  <tr
                    key={po.id}
                    onClick={() => navigate(`/pembelian/${po.id}`)}
                    className="cursor-pointer border-t border-line transition-colors hover:bg-table-header"
                  >
                    <td className="px-5 py-3 font-semibold text-ink">{po.po_number}</td>
                    <td className="px-4 py-3 text-ink-secondary">{formatDateId(po.ordered_at)}</td>
                    <td className="px-4 py-3 text-ink-secondary">{po.supplier?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-right text-ink-secondary">{po.items_count}</td>
                    <td className="px-4 py-3 text-right font-semibold text-ink">
                      {formatRupiah(po.total)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={statusInfo.variant}>{statusInfo.label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      {po.status === 'ordered' && (
                        <button
                          type="button"
                          aria-label={`Batalkan PO ${po.po_number}`}
                          onClick={(event) => {
                            event.stopPropagation()
                            setCancelTarget(po)
                            setCancelSuccess(null)
                          }}
                          className="text-danger-ink transition-opacity hover:opacity-70"
                        >
                          <X size={15} />
                        </button>
                      )}
                    </td>
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

      <PurchaseOrderFormModal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        onCreated={() => {
          setFormOpen(false)
          refetch()
        }}
      />

      <ManualReceiptModal
        open={manualReceiptOpen}
        onClose={() => {
          setManualReceiptOpen(false)
          // Penerimaan manual menambah stok — data dashboard/persediaan perlu disegarkan.
          queryClient.invalidateQueries({ queryKey: ['dashboard'] })
          queryClient.invalidateQueries({ queryKey: ['medicines'] })
        }}
      />

      <CancelPoModal
        target={cancelTarget}
        onClose={() => setCancelTarget(null)}
        onSaved={(poNumber) => {
          setCancelTarget(null)
          setCancelSuccess(poNumber)
          queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
        }}
      />
    </div>
  )
}

/** Konfirmasi pembatalan PO — hanya status ordered yang dapat dibatalkan (409 bila sudah diproses). */
function CancelPoModal({
  target,
  onClose,
  onSaved,
}: {
  target: PurchaseOrderListItem | null
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
