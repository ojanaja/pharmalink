import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Select } from '../../components/ui/Field'
import { Pagination } from '../../components/ui/Pagination'
import { api } from '../../lib/api'
import { formatDateId, formatRupiah } from '../../lib/format'
import { poStatusLabel } from '../../lib/medicine'
import type { LaravelPaginated, PurchaseOrderListItem, Supplier } from '../../lib/types'
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
  const [formOpen, setFormOpen] = useState(false)

  const { data: suppliers } = useQuery({
    queryKey: ['suppliers'],
    queryFn: () => api<LaravelPaginated<Supplier>>('/suppliers?per_page=100'),
  })

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['purchase-orders', page, status, supplierId],
    queryFn: () => {
      const params = new URLSearchParams({ page: String(page) })
      if (status) params.set('status', status)
      if (supplierId) params.set('supplier_id', supplierId)
      return api<LaravelPaginated<PurchaseOrderListItem>>(`/purchase-orders?${params}`)
    },
    placeholderData: keepPreviousData,
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
        <Button icon={<Plus size={15} />} onClick={() => setFormOpen(true)}>
          Buat pembelian
        </Button>
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
      </div>

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
                <th className="px-5 py-3">Nomor</th>
                <th className="px-4 py-3">Tanggal</th>
                <th className="px-4 py-3">Supplier</th>
                <th className="px-4 py-3 text-right">Item</th>
                <th className="px-4 py-3 text-right">Total</th>
                <th className="px-4 py-3">Status</th>
              </tr>
            </thead>
            <tbody>
              {isPending &&
                Array.from({ length: 5 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 6 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-6 py-16 text-center text-sm text-ink-secondary">
                    Belum ada purchase order.
                  </td>
                </tr>
              )}

              {data?.data.map((po) => {
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
    </div>
  )
}
