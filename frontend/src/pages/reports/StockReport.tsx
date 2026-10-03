import { useQuery } from '@tanstack/react-query'
import { Package, TriangleAlert, Wallet } from 'lucide-react'
import { Badge } from '../../components/ui/Badge'
import { StatCard } from '../../components/ui/StatCard'
import { api } from '../../lib/api'
import { formatRupiah } from '../../lib/format'
import { apiStockStatus } from '../../lib/medicine'
import { sortRows, useSort } from '../../lib/sort'
import type { StockReport } from '../../lib/types'
import { ExportButton } from './ExportButton'
import { ReportError, ReportTable } from './shared'

export function StockReport() {
  const { sort, toggleSort } = useSort()
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['report-stock'],
    queryFn: () => api<{ data: StockReport }>('/reports/stock'),
  })

  const lowCount =
    data?.data.medicines.filter((medicine) => medicine.status === 'menipis').length ?? 0
  const outCount =
    data?.data.medicines.filter((medicine) => medicine.status === 'habis').length ?? 0

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-end justify-end gap-4">
        <ExportButton report="stock" />
      </div>

      {isError ? (
        <ReportError onRetry={() => refetch()} />
      ) : (
        <>
          {isPending ? (
            <div className="grid grid-cols-3 gap-4">
              {Array.from({ length: 3 }, (_, i) => (
                <div key={i} className="h-32 animate-pulse rounded-card bg-neutral-bg" />
              ))}
            </div>
          ) : (
            <div className="grid grid-cols-3 gap-4">
              <StatCard
                icon={Wallet}
                label="Nilai Persediaan"
                value={formatRupiah(data?.data.total_value ?? 0)}
                note={`${data?.data.medicines.length ?? 0} obat aktif`}
              />
              <StatCard
                icon={TriangleAlert}
                label="Stok Menipis"
                value={`${lowCount} obat`}
                note="Perlu rencana pembelian"
                noteClassName="text-warning-ink"
              />
              <StatCard
                icon={Package}
                label="Stok Habis"
                value={`${outCount} obat`}
                note={outCount > 0 ? 'Butuh tindakan segera' : 'Tidak ada obat habis'}
                noteClassName={outCount > 0 ? 'text-danger-ink' : 'text-success-ink'}
              />
            </div>
          )}

          {(data?.data.items_without_purchase_price ?? 0) > 0 && (
            <div className="rounded-lg bg-warning-bg px-4 py-2.5 text-[13px] text-warning-ink">
              {data?.data.items_without_purchase_price} obat belum memiliki harga beli — nilai
              persediaan di atas belum memperhitungkan obat tersebut.
            </div>
          )}

          <ReportTable
            title="Nilai Persediaan per Obat"
            note="Nilai dihitung dari harga beli tiap batch dikali stok tersedia pada batch tersebut."
            isPending={isPending}
            isEmpty={!data || data.data.medicines.length === 0}
            emptyText="Belum ada data persediaan."
            head={['Obat', 'Kategori', 'Stok', 'Min.', 'Nilai', 'Status']}
            sortable={{ Obat: 'nama', Stok: 'stok', Nilai: 'nilai' }}
            sort={sort}
            onSortToggle={toggleSort}
            rows={sortRows(data?.data.medicines ?? [], sort, (medicine, key) => {
              switch (key) {
                case 'nama':
                  return medicine.name
                case 'stok':
                  return medicine.stock_total
                case 'nilai':
                  return Number(medicine.stock_value)
                default:
                  return null
              }
            }).map((medicine) => {
              const status = apiStockStatus(medicine.status)
              return [
                <div key="n">
                  <p className="text-[13px] font-semibold text-ink">{medicine.name}</p>
                  <p className="text-[11px] text-ink-secondary">{medicine.code}</p>
                </div>,
                <span key="c">{medicine.category ?? '—'}</span>,
                <span key="s">{new Intl.NumberFormat('id-ID').format(medicine.stock_total)}</span>,
                <span key="m">{new Intl.NumberFormat('id-ID').format(medicine.min_stock)}</span>,
                <span key="v" className="font-semibold">
                  {formatRupiah(medicine.stock_value)}
                </span>,
                <Badge key="b" variant={status.variant}>
                  {status.label}
                </Badge>,
              ]
            })}
          />
        </>
      )}
    </div>
  )
}
