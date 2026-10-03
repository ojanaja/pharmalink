import { useQuery } from '@tanstack/react-query'
import { ReceiptText, ShoppingBag } from 'lucide-react'
import { useState } from 'react'
import { StatCard } from '../../components/ui/StatCard'
import { api } from '../../lib/api'
import { formatRupiah } from '../../lib/format'
import type { PurchasesReport } from '../../lib/types'
import { ExportButton } from './ExportButton'
import { currentMonthPeriod } from '../../lib/format'
import { sortRows, useSort } from '../../lib/sort'
import { PeriodFilter } from './PeriodFilter'
import { ReportError, ReportTable } from './shared'

export function PurchasesReport() {
  const initial = currentMonthPeriod()
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const { sort: supSort, toggleSort: toggleSupSort } = useSort()
  const { sort: medSort, toggleSort: toggleMedSort } = useSort()

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['report-purchases', from, to],
    queryFn: () =>
      api<{ data: PurchasesReport }>(`/reports/purchases?from=${from}&to=${to}`),
  })

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-end justify-between gap-4">
        <PeriodFilter from={from} to={to} onApply={(nextFrom, nextTo) => {
          setFrom(nextFrom)
          setTo(nextTo)
        }} />
        <ExportButton report="purchases" params={{ from, to }} />
      </div>

      {isError ? (
        <ReportError onRetry={() => refetch()} />
      ) : (
        <>
          {isPending ? (
            <div className="grid grid-cols-2 gap-4">
              {Array.from({ length: 2 }, (_, i) => (
                <div key={i} className="h-32 animate-pulse rounded-card bg-neutral-bg" />
              ))}
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-4">
              <StatCard
                icon={ReceiptText}
                label="Total Pembelian"
                value={formatRupiah(data?.data.summary.total ?? 0)}
              />
              <StatCard
                icon={ShoppingBag}
                label="Penerimaan"
                value={`${data?.data.summary.receipts ?? 0} penerimaan`}
              />
            </div>
          )}

          <ReportTable
            title="Ringkasan Supplier"
            note="Kontribusi pembelian per supplier pada periode ini."
            isPending={isPending}
            isEmpty={!data || data.data.suppliers.length === 0}
            emptyText="Tidak ada pembelian pada periode ini."
            head={['Supplier', 'Penerimaan', 'Total Pembelian']}
            sortable={{ Supplier: 'nama', 'Total Pembelian': 'total' }}
            sort={supSort}
            onSortToggle={toggleSupSort}
            rows={sortRows(data?.data.suppliers ?? [], supSort, (supplier, key) => {
              switch (key) {
                case 'nama':
                  return supplier.name
                case 'total':
                  return Number(supplier.total)
                default:
                  return null
              }
            }).map((supplier) => [
              <span key="n" className="font-semibold text-ink">
                {supplier.name}
              </span>,
              <span key="r">{supplier.receipts}</span>,
              <span key="t" className="font-semibold">
                {formatRupiah(supplier.total)}
              </span>,
            ])}
          />

          <ReportTable
            title="Pembelian per Obat"
            note="Akumulasi kuantitas dan nilai per obat pada periode ini."
            isPending={isPending}
            isEmpty={!data || data.data.medicines.length === 0}
            emptyText="Tidak ada pembelian pada periode ini."
            head={['Obat', 'Kuantitas', 'Total']}
            sortable={{ Obat: 'nama', Kuantitas: 'qty', Total: 'total' }}
            sort={medSort}
            onSortToggle={toggleMedSort}
            rows={sortRows(data?.data.medicines ?? [], medSort, (medicine, key) => {
              switch (key) {
                case 'nama':
                  return medicine.name
                case 'qty':
                  return medicine.quantity
                case 'total':
                  return Number(medicine.total)
                default:
                  return null
              }
            }).map((medicine) => [
              <div key="n">
                <p className="text-[13px] font-semibold text-ink">{medicine.name}</p>
                <p className="text-[11px] text-ink-secondary">{medicine.code}</p>
              </div>,
              <span key="q">{new Intl.NumberFormat('id-ID').format(medicine.quantity)}</span>,
              <span key="t" className="font-semibold">
                {formatRupiah(medicine.total)}
              </span>,
            ])}
          />
        </>
      )}
    </div>
  )
}
