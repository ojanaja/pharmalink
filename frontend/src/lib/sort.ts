import { useCallback, useState } from 'react'

export type SortDirection = 'asc' | 'desc'

export interface SortState {
  key: string | null
  direction: SortDirection
}

export const NO_SORT: SortState = { key: null, direction: 'asc' }

/** State sort per tabel — klik header yang sama toggle arah, header baru mulai dari asc. */
export function useSort(): { sort: SortState; toggleSort: (key: string) => void } {
  const [sort, setSort] = useState<SortState>(NO_SORT)
  const toggleSort = useCallback((key: string) => {
    setSort((prev) =>
      prev.key === key
        ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' }
        : { key, direction: 'asc' },
    )
  }, [])
  return { sort, toggleSort }
}

/**
 * Sort immutable copy — comparator aman number/string/date-ISO.
 * Nilai null/undefined selalu di akhir terlepas dari arah (baris tanpa data tidak "naik").
 */
export function sortRows<T>(
  rows: readonly T[],
  sort: SortState,
  getValue: (row: T, key: string) => string | number | null | undefined,
): T[] {
  if (!sort.key) return [...rows]
  const dir = sort.direction === 'asc' ? 1 : -1
  return [...rows].sort((a, b) => {
    const va = getValue(a, sort.key as string)
    const vb = getValue(b, sort.key as string)
    if (va == null && vb == null) return 0
    if (va == null) return 1
    if (vb == null) return -1
    if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir
    return String(va).localeCompare(String(vb), 'id') * dir
  })
}
