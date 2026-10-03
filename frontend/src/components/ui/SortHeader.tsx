import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react'
import type { SortState } from '../../lib/sort'

interface SortHeaderProps {
  label: string
  sortKey: string
  sort: SortState
  onToggle: (key: string) => void
  /** Kolom angka rata kanan ikut ke ikon sort. */
  align?: 'left' | 'right'
}

/** Header tabel klik-able untuk sort — ikon netral/naik/turun sesuai state sort. */
export function SortHeader({ label, sortKey, sort, onToggle, align = 'left' }: SortHeaderProps) {
  const active = sort.key === sortKey
  const Icon = active ? (sort.direction === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown
  return (
    <th
      onClick={() => onToggle(sortKey)}
      aria-sort={active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}
      className={`cursor-pointer select-none px-5 py-3 transition-colors hover:text-ink ${
        align === 'right' ? 'text-right' : ''
      }`}
    >
      <span
        className={`inline-flex items-center gap-1 ${
          align === 'right' ? 'flex-row-reverse' : ''
        }`}
      >
        {label}
        <Icon size={11} className={active ? 'text-primary' : 'text-placeholder'} aria-hidden="true" />
      </span>
    </th>
  )
}
