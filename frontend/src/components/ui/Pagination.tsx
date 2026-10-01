import { ChevronLeft, ChevronRight } from 'lucide-react'

interface PaginationProps {
  currentPage: number
  lastPage: number
  total: number
  from: number
  to: number
  onPageChange: (page: number) => void
}

export function Pagination({
  currentPage,
  lastPage,
  total,
  from,
  to,
  onPageChange,
}: PaginationProps) {
  if (total === 0) return null
  return (
    <div className="flex items-center justify-between gap-4 px-4 py-3 text-xs text-ink-secondary">
      <span>
        Menampilkan {from}–{to} dari {total} data
      </span>
      <div className="flex items-center gap-1">
        <button
          type="button"
          disabled={currentPage <= 1}
          onClick={() => onPageChange(currentPage - 1)}
          aria-label="Sebelumnya"
          className="flex size-8 items-center justify-center rounded-lg border border-line text-ink transition-colors hover:border-primary disabled:cursor-not-allowed disabled:opacity-40"
        >
          <ChevronLeft size={14} />
        </button>
        {Array.from({ length: lastPage }, (_, i) => i + 1).map((page) => (
          <button
            key={page}
            type="button"
            onClick={() => onPageChange(page)}
            aria-current={page === currentPage ? 'page' : undefined}
            className={`size-8 rounded-lg text-[13px] font-bold transition-colors ${
              page === currentPage
                ? 'bg-primary text-white'
                : 'border border-line text-ink hover:border-primary'
            }`}
          >
            {page}
          </button>
        ))}
        <button
          type="button"
          disabled={currentPage >= lastPage}
          onClick={() => onPageChange(currentPage + 1)}
          aria-label="Berikutnya"
          className="flex size-8 items-center justify-center rounded-lg border border-line text-ink transition-colors hover:border-primary disabled:cursor-not-allowed disabled:opacity-40"
        >
          <ChevronRight size={14} />
        </button>
      </div>
    </div>
  )
}
