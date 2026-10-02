import { useQuery } from '@tanstack/react-query'
import { Minus, ReceiptText, Scale, TrendingUp } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { StatCard } from '../../components/ui/StatCard'
import { api } from '../../lib/api'
import { formatRupiah } from '../../lib/format'
import type { ProfitLossReport } from '../../lib/types'
import { currentMonthPeriod } from '../../lib/format'
import { PeriodFilter } from './PeriodFilter'
import { ReportError, ReportTable } from './shared'

export function ProfitLossReport() {
  const initial = currentMonthPeriod()
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['report-profit-loss', from, to],
    queryFn: () =>
      api<{ data: ProfitLossReport }>(`/reports/profit-loss?from=${from}&to=${to}`),
  })

  const report = data?.data
  const sales = Number(report?.sales ?? 0)
  const cogs = Number(report?.cogs ?? 0)
  const gross = Number(report?.gross_profit ?? 0)
  const margin = sales > 0 ? (gross / sales) * 100 : 0

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-end justify-between gap-4">
        <PeriodFilter
          from={from}
          to={to}
          onApply={(nextFrom, nextTo) => {
            setFrom(nextFrom)
            setTo(nextTo)
          }}
        />
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
              <StatCard icon={ReceiptText} label="Penjualan" value={formatRupiah(sales)} />
              <StatCard
                icon={Scale}
                label="Harga Pokok"
                value={formatRupiah(cogs)}
                note={sales > 0 ? `${((cogs / sales) * 100).toFixed(1).replace('.', ',')}% dari penjualan` : undefined}
              />
              <StatCard
                icon={TrendingUp}
                label="Laba Kotor"
                value={formatRupiah(gross)}
                note={`Margin ${margin.toFixed(1).replace('.', ',')}%`}
                noteClassName="text-success-ink"
              />
            </div>
          )}

          {/* Kartu rumus — pola Figma #45:9452 */}
          <div className="rounded-card border border-line bg-surface p-6">
            <h3 className="text-[15px] font-bold text-ink">Rumus Sederhana</h3>
            <p className="mt-0.5 text-xs text-ink-secondary">
              Tidak menggunakan akuntansi double-entry.
            </p>
            <div className="mt-4 flex items-center justify-center gap-4 rounded-lg bg-primary-soft px-6 py-5 text-center">
              <span className="text-xl font-bold text-ink">{formatRupiah(sales)}</span>
              <Minus size={18} className="text-ink-secondary" aria-hidden="true" />
              <span className="text-xl font-bold text-ink">{formatRupiah(cogs)}</span>
              <span className="text-2xl font-bold text-primary">=</span>
              <span className="text-2xl font-bold text-primary">{formatRupiah(gross)}</span>
            </div>
            <p className="mt-2 text-center text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
              Penjualan − Harga Pokok = Laba Kotor
            </p>
          </div>

          {(report?.items_without_cost ?? 0) > 0 && (
            <div className="rounded-lg bg-warning-bg px-4 py-2.5 text-[13px] text-warning-ink">
              {report?.items_without_cost} item terjual belum memiliki harga pokok — laba kotor di
              atas dapat lebih tinggi dari seharusnya.
            </div>
          )}

          <ReportTable
            title="Breakdown Periode"
            note={`Periode ${from} s.d. ${to}.`}
            isPending={isPending}
            isEmpty={false}
            emptyText=""
            head={['Keterangan', 'Jumlah']}
            rows={[
              [
                <span key="l" className="text-ink">
                  Penjualan bersih
                </span>,
                <span key="v" className="font-semibold text-ink">
                  {formatRupiah(sales)}
                </span>,
              ],
              [
                <span key="l" className="text-ink">
                  Harga pokok penjualan
                </span>,
                <span key="v" className="font-semibold text-danger-ink">
                  − {formatRupiah(cogs)}
                </span>,
              ],
              [
                <span key="l" className="flex items-center gap-2 font-bold text-ink">
                  Laba kotor
                  <Badge variant="info">Margin {margin.toFixed(1).replace('.', ',')}%</Badge>
                </span>,
                <span key="v" className="font-bold text-primary">
                  {formatRupiah(gross)}
                </span>,
              ],
            ]}
          />
        </>
      )}
    </div>
  )
}
