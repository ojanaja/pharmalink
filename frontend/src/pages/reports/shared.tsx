import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react'
import type { SortState } from '../../lib/sort'

export function ReportError({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-card border border-line bg-surface px-6 py-16 text-center">
      <p className="text-sm text-danger-ink">Gagal memuat laporan.</p>
      <button
        type="button"
        onClick={onRetry}
        className="text-[13px] font-bold text-primary hover:underline"
      >
        Coba lagi
      </button>
    </div>
  )
}

interface ReportTableProps {
  title: string
  note?: string
  head: string[]
  rows: React.ReactNode[][]
  isPending: boolean
  isEmpty: boolean
  emptyText: string
  footer?: React.ReactNode
  /** Map label kolom → sortKey untuk header yang bisa di-sort (opsional). */
  sortable?: Record<string, string>
  sort?: SortState
  onSortToggle?: (key: string) => void
}

/** Pola tabel laporan — konsisten dengan tabel aplikasi lain (header 10px uppercase). */
export function ReportTable({
  title,
  note,
  head,
  rows,
  isPending,
  isEmpty,
  emptyText,
  footer,
  sortable,
  sort,
  onSortToggle,
}: ReportTableProps) {
  return (
    <div className="overflow-hidden rounded-card border border-line bg-surface">
      <div className="border-b border-line px-5 py-4">
        <h3 className="text-[15px] font-bold text-ink">{title}</h3>
        {note && <p className="mt-0.5 text-xs text-ink-secondary">{note}</p>}
      </div>
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
            {head.map((column) => {
              const sortKey = sortable?.[column]
              if (!sortKey || !sort || !onSortToggle) {
                return (
                  <th key={column} className="px-5 py-3">
                    {column}
                  </th>
                )
              }
              const active = sort.key === sortKey
              const Icon = active
                ? sort.direction === 'asc'
                  ? ArrowUp
                  : ArrowDown
                : ArrowUpDown
              return (
                <th
                  key={column}
                  onClick={() => onSortToggle(sortKey)}
                  aria-sort={active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}
                  className="cursor-pointer select-none px-5 py-3 transition-colors hover:text-ink"
                >
                  <span className="inline-flex items-center gap-1">
                    {column}
                    <Icon size={11} className={active ? 'text-primary' : 'text-placeholder'} aria-hidden="true" />
                  </span>
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody>
          {isPending &&
            Array.from({ length: 5 }, (_, i) => (
              <tr key={i} className="border-t border-line">
                {head.map((_, j) => (
                  <td key={j} className="px-4 py-3">
                    <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                  </td>
                ))}
              </tr>
            ))}
          {!isPending && isEmpty && (
            <tr>
              <td
                colSpan={head.length}
                className="px-6 py-12 text-center text-sm text-ink-secondary"
              >
                {emptyText}
              </td>
            </tr>
          )}
          {!isPending &&
            rows.map((cells, i) => (
              <tr key={i} className="border-t border-line text-[13px] text-ink-secondary">
                {cells.map((cell, j) => (
                  <td key={j} className="px-4 py-3">
                    {cell}
                  </td>
                ))}
              </tr>
            ))}
        </tbody>
      </table>
      {footer}
    </div>
  )
}
