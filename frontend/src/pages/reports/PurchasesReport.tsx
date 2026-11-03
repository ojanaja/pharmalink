import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ReceiptText, ShoppingBag } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../../components/ui/Badge'
import { Pagination } from '../../components/ui/Pagination'
import { StatCard } from '../../components/ui/StatCard'
import { api } from '../../lib/api'
import { formatDateId, formatRupiah } from '../../lib/format'
import { poStatusLabel } from '../../lib/medicine'
import { sortRows, useSort } from '../../lib/sort'
import type { LaravelPaginated, PurchaseOrderListItem, PurchasesReport } from '../../lib/types'
import { ExportButton } from './ExportButton'
import { currentMonthPeriod } from '../../lib/format'
import { PeriodFilter } from './PeriodFilter'
import { ReportError, ReportTable } from './shared'

export function PurchasesReport() {
  const initial = currentMonthPeriod()
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [poPage, setPoPage] = useState(1)
  const { sort: supSort, toggleSort: toggleSupSort } = useSort()
  const { sort: medSort, toggleSort: toggleMedSort } = useSort()

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['report-purchases', from, to],
    queryFn: () =>
      api<{ data: PurchasesReport }>(`/reports/purchases?from=${from}&to=${to}`),
  })

  // Daftar PO pindah ke laporan — modul Pembelian kini murni transaksi.
  const poList = useQuery({
    queryKey: ['purchase-orders', 'report', from, to, poPage],
    queryFn: () =>
      api<LaravelPaginated<PurchaseOrderListItem>>(
        `/purchase-orders?from=${from}&to=${to}&page=${poPage}`,
      ),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-end justify-between gap-4">
        <PeriodFilter from={from} to={to} onApply={(nextFrom, nextTo) => {
          setFrom(nextFrom)
          setTo(nextTo)
          setPoPage(1)
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

          <div className="overflow-hidden rounded-card border border-line bg-surface">
            <div className="border-b border-line px-5 py-4">
              <h3 className="text-[15px] font-bold text-ink">Daftar Purchase Order</h3>
              <p className="mt-0.5 text-xs text-ink-secondary">
                {poList.data?.meta.total ?? 0} PO pada periode ini. Klik nomor untuk melihat detail.
              </p>
            </div>
            {poList.isError ? (
              <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                <p className="text-sm text-danger-ink">Gagal memuat daftar PO.</p>
                <button
                  type="button"
                  onClick={() => poList.refetch()}
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
                  {poList.isPending &&
                    Array.from({ length: 5 }, (_, i) => (
                      <tr key={i} className="border-t border-line">
                        {Array.from({ length: 6 }, (_, j) => (
                          <td key={j} className="px-4 py-3">
                            <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                          </td>
                        ))}
                      </tr>
                    ))}

                  {poList.data && poList.data.data.length === 0 && (
                    <tr>
                      <td colSpan={6} className="px-6 py-12 text-center text-sm text-ink-secondary">
                        Tidak ada PO pada periode ini.
                      </td>
                    </tr>
                  )}

                  {poList.data?.data.map((po) => {
                    const status = poStatusLabel(po.status)
                    return (
                      <tr key={po.id} className="border-t border-line">
                        <td className="px-5 py-3">
                          <Link
                            to={`/pembelian/${po.id}`}
                            className="font-semibold text-ink hover:text-primary hover:underline"
                          >
                            {po.po_number}
                          </Link>
                        </td>
                        <td className="px-4 py-3 text-ink-secondary">{formatDateId(po.ordered_at)}</td>
                        <td className="px-4 py-3 text-ink-secondary">{po.supplier?.name ?? '—'}</td>
                        <td className="px-4 py-3 text-right text-ink-secondary">{po.items_count}</td>
                        <td className="px-4 py-3 text-right font-semibold text-ink">
                          {formatRupiah(po.total)}
                        </td>
                        <td className="px-4 py-3">
                          <Badge variant={status.variant}>{status.label}</Badge>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            )}
            {poList.data && (
              <Pagination
                currentPage={poList.data.meta.current_page}
                lastPage={poList.data.meta.last_page}
                total={poList.data.meta.total}
                from={poList.data.meta.from ?? 0}
                to={poList.data.meta.to ?? 0}
                onPageChange={setPoPage}
              />
            )}
          </div>
        </>
      )}
    </div>
  )
}
