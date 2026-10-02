import { useQuery } from '@tanstack/react-query'
import { ReceiptText, Scale, ShoppingBag } from 'lucide-react'
import { useState } from 'react'
import { Pagination } from '../../components/ui/Pagination'
import { StatCard } from '../../components/ui/StatCard'
import { api } from '../../lib/api'
import { formatDateTimeId, formatRupiah } from '../../lib/format'
import type { SalesReport } from '../../lib/types'
import { ExportButton } from './ExportButton'
import { currentMonthPeriod } from '../../lib/format'
import { PeriodFilter } from './PeriodFilter'
import { ReportError, ReportTable } from './shared'

export function SalesReport() {
  const initial = currentMonthPeriod()
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [page, setPage] = useState(1)

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['report-sales', from, to, page],
    queryFn: () => api<{ data: SalesReport }>(`/reports/sales?from=${from}&to=${to}&page=${page}`),
  })

  function applyPeriod(nextFrom: string, nextTo: string) {
    setFrom(nextFrom)
    setTo(nextTo)
    setPage(1)
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-end justify-between gap-4">
        <PeriodFilter from={from} to={to} onApply={applyPeriod} />
        <ExportButton report="sales" params={{ from, to }} />
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
                icon={ReceiptText}
                label="Omzet Bersih"
                value={formatRupiah(data?.data.summary.omzet ?? 0)}
              />
              <StatCard
                icon={ShoppingBag}
                label="Transaksi"
                value={`${data?.data.summary.transactions ?? 0}`}
              />
              <StatCard
                icon={Scale}
                label="Rata-rata Transaksi"
                value={formatRupiah(data?.data.summary.average ?? 0)}
              />
            </div>
          )}

          <ReportTable
            title="Penjualan per Obat"
            note="Akumulasi kuantitas dan omzet per obat pada periode ini."
            isPending={isPending}
            isEmpty={!data || data.data.medicines.length === 0}
            emptyText="Tidak ada penjualan pada periode ini."
            head={['Obat', 'Kuantitas', 'Omzet']}
            rows={(data?.data.medicines ?? []).map((medicine) => [
              <div key="n">
                <p className="text-[13px] font-semibold text-ink">{medicine.name}</p>
                <p className="text-[11px] text-ink-secondary">{medicine.code}</p>
              </div>,
              <span key="q">{new Intl.NumberFormat('id-ID').format(medicine.quantity)}</span>,
              <span key="o" className="font-semibold">
                {formatRupiah(medicine.omzet)}
              </span>,
            ])}
          />

          <ReportTable
            title="Riwayat Transaksi"
            note={`${data?.data.transactions.meta.total ?? 0} transaksi tercatat pada periode ini.`}
            isPending={isPending}
            isEmpty={!data || data.data.transactions.data.length === 0}
            emptyText="Tidak ada transaksi pada periode ini."
            head={['Tanggal', 'No. Transaksi', 'Kasir', 'Item', 'Total']}
            rows={(data?.data.transactions.data ?? []).map((sale) => [
              <span key="t">{formatDateTimeId(sale.sold_at)}</span>,
              <span key="i" className="font-semibold">
                {sale.invoice_number}
              </span>,
              <span key="u">{sale.user?.name ?? '—'}</span>,
              <span key="c">{sale.items_count ?? 0}</span>,
              <span key="s" className="font-semibold">
                {formatRupiah(sale.total)}
              </span>,
            ])}
            footer={
              data && (
                <Pagination
                  currentPage={data.data.transactions.meta.current_page}
                  lastPage={data.data.transactions.meta.last_page}
                  total={data.data.transactions.meta.total}
                  from={data.data.transactions.meta.from ?? 0}
                  to={data.data.transactions.meta.to ?? 0}
                  onPageChange={setPage}
                />
              )
            }
          />
        </>
      )}
    </div>
  )
}
