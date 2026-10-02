import { useQuery } from '@tanstack/react-query'
import { CalendarClock, PackageX } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { StatCard } from '../../components/ui/StatCard'
import { api } from '../../lib/api'
import { formatDateId, formatRupiah } from '../../lib/format'
import type { ExpiryReport } from '../../lib/types'
import { ExportButton } from './ExportButton'
import { ReportError, ReportTable } from './shared'

const DAY_OPTIONS = [30, 60, 90] as const

export function ExpiryReport() {
  const [days, setDays] = useState<number>(60)

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['report-expiry', days],
    queryFn: () => api<{ data: ExpiryReport }>(`/reports/expiry?days=${days}`),
  })

  const toRow = (item: ExpiryReport['expiring'][number], variant: 'danger' | 'warning') => [
    <div key="n">
      <p className="text-[13px] font-semibold text-ink">{item.medicine.name}</p>
      <p className="text-[11px] text-ink-secondary">{item.medicine.code}</p>
    </div>,
    <span key="b">{item.batch_number}</span>,
    <span key="t">{item.expiry_date ? formatDateId(item.expiry_date) : '—'}</span>,
    <span key="q">{new Intl.NumberFormat('id-ID').format(item.quantity_on_hand)}</span>,
    <span key="v" className="font-semibold">
      {formatRupiah(item.stock_value)}
    </span>,
    <Badge key="s" variant={variant}>
      {variant === 'danger' ? 'Kedaluwarsa' : `≤ ${days} hari`}
    </Badge>,
  ]

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-end justify-between gap-4">
        <div className="flex items-center gap-2">
          <span className="text-[13px] font-semibold text-ink">Ambang peringatan</span>
          {DAY_OPTIONS.map((option) => (
            <button
              key={option}
              type="button"
              onClick={() => setDays(option)}
              aria-pressed={days === option}
              className={`rounded-full border px-3.5 py-1.5 text-[13px] font-bold transition-colors ${
                days === option
                  ? 'border-primary bg-primary text-white'
                  : 'border-line bg-surface text-ink-secondary hover:border-primary hover:text-primary'
              }`}
            >
              {option} hari
            </button>
          ))}
        </div>
        <ExportButton report="expiry" params={{ days }} />
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
                icon={PackageX}
                label="Sudah Kedaluwarsa"
                value={`${data?.data.counts.expired ?? 0} batch`}
                note={data?.data.counts.expired ? 'Harus dikarantina' : 'Tidak ada batch kedaluwarsa'}
                noteClassName={data?.data.counts.expired ? 'text-danger-ink' : 'text-success-ink'}
              />
              <StatCard
                icon={CalendarClock}
                label="Mendekati Kedaluwarsa"
                value={`${data?.data.counts.expiring ?? 0} batch`}
                note={`Dalam ${days} hari ke depan`}
                noteClassName="text-warning-ink"
              />
            </div>
          )}

          <ReportTable
            title="Daftar Batch Kedaluwarsa"
            note={`Batch diurutkan dari tanggal kedaluwarsa terdekat (ambang ${days} hari).`}
            isPending={isPending}
            isEmpty={
              !data || (data.data.expired.length === 0 && data.data.expiring.length === 0)
            }
            emptyText="Tidak ada batch kedaluwarsa atau mendekati kedaluwarsa."
            head={['Obat', 'Batch', 'Tanggal Expired', 'Qty', 'Nilai', 'Status']}
            rows={[
              ...(data?.data.expired ?? []).map((item) => toRow(item, 'danger')),
              ...(data?.data.expiring ?? []).map((item) => toRow(item, 'warning')),
            ]}
          />
        </>
      )}
    </div>
  )
}
