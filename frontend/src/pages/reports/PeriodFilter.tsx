import { CalendarRange } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Field'

interface PeriodFilterProps {
  from: string
  to: string
  onApply: (from: string, to: string) => void
}

export function PeriodFilter({ from, to, onApply }: PeriodFilterProps) {
  const [draftFrom, setDraftFrom] = useState(from)
  const [draftTo, setDraftTo] = useState(to)

  return (
    <div className="flex items-end gap-3">
      <span className="flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-3 text-[13px] font-semibold text-ink">
        <CalendarRange size={15} className="text-ink-secondary" aria-hidden="true" />
        Periode laporan
      </span>
      <div className="flex flex-col gap-1">
        <label htmlFor="period-from" className="text-[11px] text-ink-secondary">
          Dari
        </label>
        <Input
          id="period-from"
          type="date"
          value={draftFrom}
          onChange={(event) => setDraftFrom(event.target.value)}
          className="w-40"
        />
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor="period-to" className="text-[11px] text-ink-secondary">
          Sampai
        </label>
        <Input
          id="period-to"
          type="date"
          value={draftTo}
          min={draftFrom}
          onChange={(event) => setDraftTo(event.target.value)}
          className="w-40"
        />
      </div>
      <Button
        variant="secondary"
        onClick={() => onApply(draftFrom, draftTo)}
        disabled={!draftFrom || !draftTo || draftTo < draftFrom}
      >
        Terapkan
      </Button>
    </div>
  )
}
