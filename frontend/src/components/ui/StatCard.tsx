import type { LucideIcon } from 'lucide-react'

interface StatCardProps {
  label: string
  value: string
  note?: string
  icon: LucideIcon
  noteClassName?: string
}

/** Kartu ringkasan — putih, border halus, radius 12 (pola EL-ab7648c3). */
export function StatCard({ label, value, note, icon: Icon, noteClassName = '' }: StatCardProps) {
  return (
    <div className="rounded-card border border-line bg-surface p-5 shadow-low">
      <div className="mb-3 flex items-center justify-between">
        <span className="rounded-lg bg-primary-soft p-2 text-primary">
          <Icon size={20} aria-hidden="true" />
        </span>
      </div>
      <p className="text-[13px] text-ink-secondary">{label}</p>
      <p className="mt-1 text-2xl font-bold text-ink">{value}</p>
      {note && <p className={`mt-1 text-xs ${noteClassName}`}>{note}</p>}
    </div>
  )
}
