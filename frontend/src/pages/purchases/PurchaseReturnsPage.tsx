import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Pagination } from '../../components/ui/Pagination'
import { SortHeader } from '../../components/ui/SortHeader'
import { api } from '../../lib/api'
import { formatDateTimeId } from '../../lib/format'
import { sortRows, useSort } from '../../lib/sort'
import type { LaravelPaginated } from '../../lib/types'

interface PurchaseReturnListItem {
  id: number
  return_number: string
  reason: string
  created_at: string
  supplier: { id: number; name: string } | null
  total_quantity: number
  items_count: number
}

export function PurchaseReturnsPage() {
  const [page, setPage] = useState(1)
  const { sort, toggleSort } = useSort()
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['purchase-returns', page],
    queryFn: () => api<LaravelPaginated<PurchaseReturnListItem>>(`/purchase-returns?page=${page}`),
    placeholderData: keepPreviousData,
  })

  const visible = sortRows(data?.data ?? [], sort, (retur, key) => {
    switch (key) {
      case 'nomor':
        return retur.return_number
      case 'tanggal':
        return retur.created_at
      case 'supplier':
        return retur.supplier?.name ?? null
      case 'qty':
        return retur.total_quantity
      default:
        return null
    }
  })

  return (
    <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
      <div className="border-b border-line px-5 py-4">
        <h3 className="text-[15px] font-bold text-ink">Daftar Retur Pembelian</h3>
        <p className="mt-0.5 text-xs text-ink-secondary">
          Barang dikembalikan ke supplier dari penerimaan PO. {data?.meta.total ?? 0} retur tercatat.
        </p>
      </div>

      {isError ? (
        <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
          <p className="text-sm text-danger-ink">Gagal memuat daftar retur.</p>
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
              <SortHeader label="Nomor Retur" sortKey="nomor" sort={sort} onToggle={toggleSort} />
              <SortHeader label="Tanggal" sortKey="tanggal" sort={sort} onToggle={toggleSort} />
              <SortHeader label="Supplier" sortKey="supplier" sort={sort} onToggle={toggleSort} />
              <th className="px-4 py-3 text-right">Item</th>
              <SortHeader label="Total Qty" sortKey="qty" sort={sort} onToggle={toggleSort} align="right" />
              <th className="px-4 py-3">Alasan</th>
            </tr>
          </thead>
          <tbody>
            {isPending &&
              Array.from({ length: 4 }, (_, i) => (
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
                  Belum ada retur pembelian.
                </td>
              </tr>
            )}

            {visible.map((retur) => (
              <tr key={retur.id} className="border-t border-line">
                <td className="px-5 py-3 font-semibold text-ink">{retur.return_number}</td>
                <td className="px-4 py-3 text-ink-secondary">{formatDateTimeId(retur.created_at)}</td>
                <td className="px-4 py-3 text-ink-secondary">{retur.supplier?.name ?? '—'}</td>
                <td className="px-4 py-3 text-right text-ink-secondary">{retur.items_count}</td>
                <td className="px-4 py-3 text-right font-semibold text-ink">
                  {new Intl.NumberFormat('id-ID').format(retur.total_quantity)}
                </td>
                <td className="px-4 py-3 text-[13px] text-ink-secondary">{retur.reason}</td>
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
  )
}
